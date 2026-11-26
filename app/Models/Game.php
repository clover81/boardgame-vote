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
    public const CATALOG_CARD_COLUMNS = [
        'id',
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
        'bgg_url',
        'youtube_url',
    ];

    public const CATALOG_DETAIL_COLUMNS = [
        ...self::CATALOG_CARD_COLUMNS,
        'full_description',
        'additional_information',
    ];

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

    /** @return array{min_players: int|null, max_players: int|null, ideal_player_counts: list<int>} */
    public function catalogPlayerRange(): array
    {
        $min = (int) $this->min_players;
        $max = (int) $this->max_players;

        if ($min < 1 || $max < $min) {
            return ['min_players' => null, 'max_players' => null, 'ideal_player_counts' => []];
        }

        return [
            'min_players' => $min,
            'max_players' => $max,
            'ideal_player_counts' => $this->idealPlayerCounts
                ->pluck('player_count')
                ->map(fn ($count) => (int) $count)
                ->filter(fn (int $count) => $count >= $min && $count <= $max)
                ->sort()
                ->values()
                ->all(),
        ];
    }

    public function ratedComplexity(): ?string
    {
        $complexity = $this->complexity;

        return $complexity !== null && (float) $complexity >= 1 && (float) $complexity <= 5
            ? $complexity
            : null;
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
