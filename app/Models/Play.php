<?php

namespace App\Models;

use App\Models\Poll;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int|null $created_by
 * @property int|null $poll_id
 * @property int $game_id
 * @property int|null $expansion_id
 * @property CarbonInterface $played_at
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $creator
 * @property-read Poll|null $poll
 * @property-read Game $game
 * @property-read Expansion|null $expansion
 * @property-read Collection<int, Player> $players
 */
class Play extends Model
{
    protected $fillable = [
        'created_by',
        'poll_id',
        'game_id',
        'expansion_id',
        'played_at',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'played_at' => 'date',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Usuario propietario del historial
    |--------------------------------------------------------------------------
    */

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /** @return BelongsTo<Poll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Juego base
    |--------------------------------------------------------------------------
    */

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(
            Game::class
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Expansión utilizada
    |--------------------------------------------------------------------------
    */

    /** @return BelongsTo<Expansion, $this> */
    public function expansion(): BelongsTo
    {
        return $this->belongsTo(
            Expansion::class
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Jugadores participantes
    |--------------------------------------------------------------------------
    */

    /** @return BelongsToMany<Player, $this> */
    public function players(): BelongsToMany
    {
        return $this
            ->belongsToMany(
                Player::class,
                'play_player'
            )
            ->withTimestamps();
    }
}
