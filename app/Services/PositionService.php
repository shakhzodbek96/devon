<?php

namespace App\Services;

use App\Exceptions\PositionValidationException;
use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for the position catalogue. The seeded 15 entries
 * (PositionsSeeder) stay as the on-premise default; this service adds
 * user-managed entries on top and enforces two invariants the Employees
 * module relies on:
 *   - unique name (case-insensitive) across the whole catalogue, and
 *   - no deletion while any employee or assignment still references the
 *     position (`position_id` is a plain string column with no DB-level
 *     FK, so the guard lives here).
 * Every mutation is audited (append-only) via AuditLogger, matching
 * UnitService.
 */
class PositionService
{
    private const UNIT_TYPES = ['DEPARTMENT', 'DIRECTORATE', 'DIVISION', 'DEPARTMENT_SUB', 'SECTION', 'OTHER'];

    public function create(array $input, User $actor): Position
    {
        return DB::transaction(function () use ($input, $actor) {
            $name = trim($input['nameUz']);
            if ($this->nameClashes($name)) {
                throw new PositionValidationException('duplicate-name');
            }

            $position = Position::create([
                'id' => $this->generateId(),
                'name_uz' => $name,
                'allowed_unit_types' => $this->normalizeTypes($input['allowedUnitTypes']),
            ]);

            AuditLogger::log($actor, 'CREATE', 'position', $position->id, $position->name_uz);

            return $position;
        });
    }

    /**
     * @param  array<string, mixed>  $patch  Only keys present are applied.
     */
    public function update(string $id, array $patch, User $actor): Position
    {
        return DB::transaction(function () use ($id, $patch, $actor) {
            $position = Position::findOrFail($id);

            if (array_key_exists('nameUz', $patch)) {
                $name = trim($patch['nameUz']);
                if ($name !== $position->name_uz && $this->nameClashes($name, $id)) {
                    throw new PositionValidationException('duplicate-name');
                }
                $position->name_uz = $name;
            }

            if (array_key_exists('allowedUnitTypes', $patch)) {
                $position->allowed_unit_types = $this->normalizeTypes($patch['allowedUnitTypes']);
            }

            $position->save();

            AuditLogger::log($actor, 'UPDATE', 'position', $position->id, $position->name_uz);

            return $position->fresh();
        });
    }

    public function delete(string $id, User $actor): void
    {
        DB::transaction(function () use ($id, $actor) {
            $position = Position::findOrFail($id);

            if ($this->isReferenced($id)) {
                throw new PositionValidationException('in-use');
            }

            $label = $position->name_uz;
            $position->delete();

            AuditLogger::log($actor, 'DELETE', 'position', $id, $label);
        });
    }

    /** A position is in use if any employee or assignment points at it. */
    private function isReferenced(string $id): bool
    {
        return Employee::where('position_id', $id)->exists()
            || Assignment::where('position_id', $id)->exists();
    }

    private function nameClashes(string $name, ?string $ignoreId = null): bool
    {
        $needle = mb_strtolower($name);

        return Position::when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get()
            ->contains(fn (Position $p) => mb_strtolower($p->name_uz) === $needle);
    }

    /** Dedupe + keep only known unit types, preserving catalogue order. */
    private function normalizeTypes(array $types): array
    {
        return array_values(array_intersect(self::UNIT_TYPES, array_unique($types)));
    }

    /** Collision-checked `POS-XXXXXX` code, distinct from the seeded ids. */
    private function generateId(): string
    {
        do {
            $id = 'POS-'.strtoupper(Str::random(6));
        } while (Position::whereKey($id)->exists());

        return $id;
    }
}
