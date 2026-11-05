<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PollParticipant extends Model
{
    protected $fillable = [
        'poll_id',
        'player_id',
        'display_name',
        'vote_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (PollParticipant $participant) {
            if (!empty($participant->vote_token)) {
                return;
            }

            do {
                $token = Str::random(40);
            } while (
                static::where(
                    'vote_token',
                    $token
                )->exists()
            );

            $participant->vote_token = $token;
        });
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(
            Vote::class,
            'poll_participant_id'
        );
    }
}
