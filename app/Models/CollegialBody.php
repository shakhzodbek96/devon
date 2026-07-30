<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Standing collegial body ("Kollegial organ") — permanent chairman,
 * secretary and member roster (PLAN_kollegial-organ.md §2.2, §3). Business
 * rules (CRUD, member management) live in `App\Services\CollegialBodyService`
 * — this model is intentionally thin.
 */
class CollegialBody extends Model
{
    /** @use HasFactory<\Database\Factories\CollegialBodyFactory> */
    use HasFactory, HasUuids;

    protected $table = 'collegial_bodies';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'name',
        'chairman_employee_uuid',
        'secretary_employee_uuid',
        'quorum_min',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return HasMany<CollegialBodyMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CollegialBodyMember::class, 'collegial_body_uuid', 'uuid');
    }

    /**
     * @return HasMany<Protocol, $this>
     */
    public function protocols(): HasMany
    {
        return $this->hasMany(Protocol::class, 'collegial_body_uuid', 'uuid');
    }
}
