<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Conference-room registry (PLAN_conference-room.md §2). Mirrors the
 * frontend `ConferenceRoom` (src/types/domain.ts). Business rules (CRUD,
 * archive) live in `App\Services\ConferenceRoomService` — this model is
 * intentionally thin.
 */
class ConferenceRoom extends Model
{
    /** @use HasFactory<\Database\Factories\ConferenceRoomFactory> */
    use HasFactory, HasUuids;

    protected $table = 'conference_rooms';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'name',
        'capacity',
        'location',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }
}
