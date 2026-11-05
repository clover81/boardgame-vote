<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vote extends Model
{
    protected $fillable = [
        'poll_id',
        'poll_participant_id',
        'poll_candidate_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(
            PollParticipant::class,
            'poll_participant_id'
        );
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(
            PollCandidate::class,
            'poll_candidate_id'
        );
    }
}
