<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int|null $bgg_id
 * @property string $name
 * @property string|null $image_url
 * @property int $min_players
 * @property int $max_players
 * @property int|null $min_playtime
 * @property int|null $max_playtime
 * @property numeric-string|null $complexity
 * @property string|null $game_mode
 * @property array<int, string>|null $game_modes
 * @property string|null $short_description
 * @property string|null $full_description
 * @property string|null $description_source
 * @property string|null $additional_information
 * @property string|null $bgg_url
 * @property string|null $youtube_url
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, Expansion> $expansions
 * @property-read Collection<int, GameIdealPlayerCount> $idealPlayerCounts
 * @property-read Collection<int, Play> $plays
 */
class Game extends Model
{
    protected $fillable = [
        'bgg_id',
        'name',
        'image_url',
        'min_players',
        'max_players',
        'min_playtime',
        'max_playtime',
        'complexity',
        'game_mode',
        'game_modes',
        'short_description',
        'full_description',
        'description_source',
        'additional_information',
        'bgg_url',
        'youtube_url',
        'is_active',
    ];

    /** @return HasMany<Expansion, $this> */
    public function expansions(): HasMany
    {
        return $this->hasMany(Expansion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bgg_id' => 'integer',
            'min_players' => 'integer',
            'max_players' => 'integer',
            'min_playtime' => 'integer',
            'max_playtime' => 'integer',
            'complexity' => 'decimal:2',
            'game_modes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<GameIdealPlayerCount, $this> */
    public function idealPlayerCounts(): HasMany
    {
        return $this->hasMany(GameIdealPlayerCount::class);
    }

    /** @return HasMany<Play, $this> */
    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    public function collections(): BelongsToMany
    {
        return $this
            ->belongsToMany(
                GameCollection::class,
                'game_collection_game'
            )
            ->withTimestamps();
    }
}
