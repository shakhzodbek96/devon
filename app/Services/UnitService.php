<?php

namespace App\Services;

use App\Exceptions\UnitValidationException;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for the organizational structure tree (PLAN_PHASE_AB.md
 * §2.2), ported verbatim from the frontend mock's `createUnit` / `updateUnit`
 * / `archiveUnit` (frontend/dashboard/src/lib/mock-backend/index.ts):
 * max depth 7, unique sibling name (ACTIVE, case-insensitive), parent
 * existence, cycle prevention via materialized path, and recursive
 * level/path recompute for every descendant when a unit's parent changes.
 */
class UnitService
{
    public const MAX_DEPTH = 7;

    public function create(array $input, User $actor): Unit
    {
        return DB::transaction(function () use ($input, $actor) {
            $parentUuid = $input['parentUuid'] ?? null;
            $parent = null;
            if ($parentUuid !== null) {
                $parent = Unit::where('uuid', $parentUuid)->first();
                if (! $parent) {
                    throw new UnitValidationException('invalid-parent');
                }
            }

            $level = $parent ? $parent->level + 1 : 0;
            if ($level >= self::MAX_DEPTH) {
                throw new UnitValidationException('max-depth');
            }
            if ($this->nameClashesWithSibling($parentUuid, $input['nameUz'])) {
                throw new UnitValidationException('duplicate-name');
            }

            $uuid = (string) Str::uuid();
            $path = $parent ? "{$parent->path}{$uuid}/" : "/{$uuid}/";

            $unit = Unit::create([
                'uuid' => $uuid,
                'name_uz' => $input['nameUz'],
                'name_ru' => $input['nameRu'] ?? null,
                'short_name' => $input['shortName'] ?? null,
                'code' => $input['code'],
                'type' => $input['type'],
                'parent_uuid' => $parentUuid,
                'level' => $level,
                'path' => $path,
                'status' => 'ACTIVE',
                'description' => $input['description'] ?? null,
                'created_by' => $actor->uuid,
                'updated_by' => $actor->uuid,
            ]);

            AuditLogger::log($actor, 'CREATE', 'unit', $unit->uuid, $unit->name_uz);

            return $unit;
        });
    }

    /**
     * @param  array<string, mixed>  $patch  Only keys present are applied
     *   (mirrors the mock's `Partial<Unit>` patch semantics).
     */
    public function update(string $uuid, array $patch, User $actor): Unit
    {
        return DB::transaction(function () use ($uuid, $patch, $actor) {
            $unit = Unit::where('uuid', $uuid)->firstOrFail();

            $parentProvided = array_key_exists('parentUuid', $patch);
            $newParentUuid = $parentProvided ? $patch['parentUuid'] : $unit->parent_uuid;
            $parentChanging = $parentProvided && $newParentUuid !== $unit->parent_uuid;

            $newParent = null;
            if ($parentChanging && $newParentUuid !== null) {
                if ($newParentUuid === $uuid) {
                    throw new UnitValidationException('cycle');
                }
                $newParent = Unit::where('uuid', $newParentUuid)->first();
                if (! $newParent) {
                    throw new UnitValidationException('invalid-parent');
                }
                // Descendant detection: any unit whose path passes through
                // `uuid` is a descendant — paths look like `/root/.../uuid/.../leaf/`.
                if (str_contains($newParent->path, "/{$uuid}/")) {
                    throw new UnitValidationException('cycle');
                }
                if ($newParent->level + 1 >= self::MAX_DEPTH) {
                    throw new UnitValidationException('max-depth');
                }
            }

            $newName = array_key_exists('nameUz', $patch) ? $patch['nameUz'] : $unit->name_uz;
            $nameChanging = array_key_exists('nameUz', $patch) && $newName !== $unit->name_uz;
            if (($parentChanging || $nameChanging)
                && $this->nameClashesWithSibling($newParentUuid, $newName, $uuid)) {
                throw new UnitValidationException('duplicate-name');
            }

            $oldPath = $unit->path;

            $fieldMap = [
                'nameUz' => 'name_uz',
                'nameRu' => 'name_ru',
                'shortName' => 'short_name',
                'code' => 'code',
                'type' => 'type',
                'description' => 'description',
                'headEmployeeUuid' => 'head_employee_uuid',
                'deputyEmployeeUuid' => 'deputy_employee_uuid',
            ];
            foreach ($fieldMap as $frontKey => $column) {
                if (array_key_exists($frontKey, $patch)) {
                    $unit->{$column} = $patch[$frontKey];
                }
            }

            if ($parentChanging) {
                $unit->parent_uuid = $newParentUuid;
                $unit->level = $newParent ? $newParent->level + 1 : 0;
                $unit->path = $newParent ? "{$newParent->path}{$uuid}/" : "/{$uuid}/";
            }

            $unit->updated_by = $actor->uuid;
            $unit->save();

            if ($parentChanging) {
                $this->recomputeDescendants($uuid, $oldPath, $unit->path, $actor);
            }

            // A parent move is a distinct, meaningful action — audited as
            // UNIT_TRANSFER; every other field edit is a plain UPDATE
            // (PLAN_PHASE_AB.md §2.2).
            AuditLogger::log(
                $actor,
                $parentChanging ? 'UNIT_TRANSFER' : 'UPDATE',
                'unit',
                $unit->uuid,
                $unit->name_uz,
            );

            return $unit->fresh();
        });
    }

    public function archive(string $uuid, User $actor): void
    {
        DB::transaction(function () use ($uuid, $actor) {
            $unit = Unit::where('uuid', $uuid)->firstOrFail();
            $unit->status = 'ARCHIVED';
            $unit->updated_by = $actor->uuid;
            $unit->save();

            AuditLogger::log($actor, 'ARCHIVE', 'unit', $unit->uuid, $unit->name_uz);
        });
    }

    /**
     * Recursively rewrite `path` + `level` for every descendant of a moved
     * unit. Validates max-depth for the WHOLE subtree before any row is
     * persisted — if any descendant would exceed the cap, the entire move
     * (including the moved unit itself) rolls back via the enclosing
     * transaction, matching the mock's all-or-nothing in-memory behavior.
     */
    private function recomputeDescendants(string $movedUuid, string $oldPath, string $newPath, User $actor): void
    {
        $descendants = Unit::where('path', 'like', $oldPath.'%')
            ->where('uuid', '!=', $movedUuid)
            ->get();

        foreach ($descendants as $descendant) {
            if (! str_starts_with($descendant->path, $oldPath)) {
                continue;
            }
            $remainder = substr($descendant->path, strlen($oldPath));
            $rewritten = $newPath.$remainder;
            $newLevel = count(array_filter(explode('/', $rewritten))) - 1;

            if ($newLevel >= self::MAX_DEPTH) {
                throw new UnitValidationException('max-depth');
            }

            $descendant->path = $rewritten;
            $descendant->level = $newLevel;
            $descendant->updated_by = $actor->uuid;
            $descendant->save();
        }
    }

    private function nameClashesWithSibling(?string $parentUuid, string $name, ?string $ignoreUuid = null): bool
    {
        $needle = mb_strtolower(trim($name));

        $query = Unit::query()->where('status', 'ACTIVE');
        $query = $parentUuid === null
            ? $query->whereNull('parent_uuid')
            : $query->where('parent_uuid', $parentUuid);
        if ($ignoreUuid !== null) {
            $query->where('uuid', '!=', $ignoreUuid);
        }

        return $query->get()
            ->contains(fn (Unit $u) => mb_strtolower(trim($u->name_uz)) === $needle);
    }
}
