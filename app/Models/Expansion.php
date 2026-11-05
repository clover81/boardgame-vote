<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $game_id
 * @property int|null $bgg_id
 * @property string $name
 * @property string|null $image_url
 * @property string|null $bgg_url
 * @property string|null $youtube_url
 * @property string|null $notes
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Game $game
 * @property-read Collection<int, Play> $plays
 */
class Expansion extends Model
{
    protected $fillable = [
        'game_id',
        'bgg_id',
        'name',
        'image_url',
        'bgg_url',
        'youtube_url',
        'notes',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bgg_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Juego base
    |--------------------------------------------------------------------------
    */

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Partidas jugadas con esta expansión
    |--------------------------------------------------------------------------
    */

    /** @return HasMany<Play, $this> */
    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }
}
