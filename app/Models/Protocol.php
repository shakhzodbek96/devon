<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bayonnoma (protocol) — one decision-round of a `CollegialBody`
 * (PLAN_kollegial-organ.md §1, §3). Business rules (the full
 * secretary -> reviewers -> members -> chairman approval chain) live in
 * `App\Services\ProtocolService` — this model is intentionally thin. ERI
 * signing (member/chairman approve paths) reuses the shared `signatures`
 * table with `resource_type='protocol'`.
 */
class Protocol extends Model
{
    use HasUuids;

    protected $table = 'protocols';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'number',
        'collegial_body_uuid',
        'protocol_date',
        'template_key',
        'agenda',
        'status',
        'review_note',
        'chairman_note',
        'created_by',
        'submitted_at',
        'members_completed_at',
        'chairman_signed_at',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'protocol_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'members_completed_at' => 'datetime',
            'chairman_signed_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ProtocolReviewer, $this>
     */
    public function reviewers(): HasMany
    {
        return $this->hasMany(ProtocolReviewer::class, 'protocol_uuid', 'uuid');
    }

    /**
     * @return HasMany<ProtocolParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ProtocolParticipant::class, 'protocol_uuid', 'uuid');
    }
}
