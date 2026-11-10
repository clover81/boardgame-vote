<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GameCollection extends Model
{
    protected $fillable = [
        'created_by',
        'name',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    // Cache de la última consulta BGG de esta colección (no de las fichas locales).
    public function bggPreviewCacheKey(): string
    {
        return 'bgg:collection:preview:' . $this->id . ':'
            . sha1(trim((string) $this->bgg_username));
    }

    /*
    |--------------------------------------------------------------------------
    | Propietario de la colección
    |--------------------------------------------------------------------------
    */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Juegos de la colección
    |--------------------------------------------------------------------------
    */

    public function games(): BelongsToMany
    {
        return $this
            ->belongsToMany(
                Game::class,
                'game_collection_game'
            )
            ->withTimestamps();
    }

    public function expansions(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Expansion::class,
            'game_collection_expansion',
            'game_collection_id',
            'expansion_id'
        );
    }

    public function polls(): BelongsToMany
    {
        return $this
            ->belongsToMany(
                Poll::class,
                'poll_game_collection'
            )
            ->withTimestamps();
    }
}
