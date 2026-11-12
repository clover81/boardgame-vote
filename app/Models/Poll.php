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
    ];

    protected $casts = ['single_choice' => 'boolean'];

    public function requiredVoteCount(int $candidateCount): int
    {
        return min($this->single_choice ? 1 : 2, $candidateCount);
    }

    public function votePoints(int $firstVotes, int $secondVotes): int
    {
        return $this->single_choice ? $firstVotes : ($firstVotes * 2) + $secondVotes;
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
