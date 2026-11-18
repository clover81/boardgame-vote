<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property-read Play|null $play
 */
class Poll extends Model
{
    protected $fillable = [
        'created_by',
        'name',
        'token',
        'status',
        'single_choice',
        'ranking_slots',
        'parent_poll_id',
        'submission_key',
        'planned_second_game',
    ];

    protected $casts = [
        'single_choice' => 'boolean',
        'ranking_slots' => 'integer',
        'parent_poll_id' => 'integer',
        'planned_second_game' => 'boolean',
    ];

    public static function suggestedRankingSlots(
        int $participantCount,
        int $candidateCount
    ): int {
        if ($candidateCount <= 2) {
            return 1;
        }

        if (
            ($candidateCount >= 5 && $participantCount >= 5) ||
            ($candidateCount >= 6 && $participantCount >= 4)
        ) {
            return 3;
        }

        return 2;
    }

    public function requiredVoteCount(int $candidateCount): int
    {
        $slots = $this->ranking_slots
            ?? ($this->single_choice ? 1 : 2);

        return min(max(1, (int) $slots), max(0, $candidateCount));
    }

    public function votePoints(
        int $firstVotes,
        int $secondVotes,
        int $thirdVotes = 0
    ): int {
        if ($this->ranking_slots !== null) {
            return match ((int) $this->ranking_slots) {
                1 => $firstVotes,
                2 => ($firstVotes * 2) + $secondVotes,
                3 => ($firstVotes * 3) + ($secondVotes * 2) + $thirdVotes,
                default => throw new \InvalidArgumentException(
                    'Número de preferencias no válido.'
                ),
            };
        }

        // Compatibilidad con las votaciones antiguas.
        return $this->single_choice
            ? $firstVotes
            : ($firstVotes * 2) + $secondVotes;
    }

    public function firstGamePoll(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_poll_id');
    }

    public function secondGamePoll(): HasOne
    {
        return $this->hasOne(self::class, 'parent_poll_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function participants(): HasMany
    {
        return $this->hasMany(
            PollParticipant::class
        );
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(
            PollCandidate::class
        );
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /** @return HasOne<Play, $this> */
    public function play(): HasOne
    {
        return $this->hasOne(Play::class);
    }

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    public function gameCollections(): BelongsToMany
    {
        return $this
            ->belongsToMany(
                GameCollection::class,
                'poll_game_collection'
            )
            ->withTimestamps();
    }
}
