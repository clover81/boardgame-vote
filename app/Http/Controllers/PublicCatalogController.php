<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class PublicCatalogController extends Controller
{
    public function index(string $token): Response
    {
        $owner = User::query()
            ->where('catalog_share_token', $token)
            ->firstOrFail();

        // Solo colecciones públicas de esta cuenta.
        $collections = GameCollection::query()
            ->where('created_by', $owner->id)
            ->where('is_public', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $collectionIds = $collections->pluck('id');

        // Solo juegos activos de las colecciones públicas.
        $games = Game::query()
            ->where('is_active', true)
            ->whereHas('collections', fn ($query) =>
                $query->whereIn(
                    'game_collections.id',
                    $collectionIds
                )
            )
            ->with([
                'idealPlayerCounts',
                'collections' => fn ($query) =>
                    $query->whereIn(
                        'game_collections.id',
                        $collectionIds
                    )->orderBy('name'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Game $game) => [
                'id' => $game->id,
                'name' => $game->name,
                'image_url' => $game->image_url,
                'min_players' => $game->min_players,
                'max_players' => $game->max_players,
                'min_playtime' => $game->min_playtime,
                'max_playtime' => $game->max_playtime,
                'complexity' => $game->complexity,
                'game_mode' => $game->game_mode,
                'game_modes' => $game->game_modes
                    ?? ($game->game_mode ? [$game->game_mode] : []),
                'short_description' => $game->short_description,
                'bgg_url' => $game->bgg_url,
                'youtube_url' => $game->youtube_url,
                'ideal_player_counts' => $game
                    ->idealPlayerCounts
                    ->pluck('player_count')
                    ->values(),
                'collections' => $game->collections
                    ->map(fn ($collection) => [
                        'id' => $collection->id,
                        'name' => $collection->name,
                    ])
                    ->values(),
            ])
            ->values();

        return Inertia::render('catalog/index', [
            'catalogToken' => $token,
            'collections' => $collections,
            'games' => $games,
        ]);
    }

    public function show(string $token, int $game): Response
    {
        $owner = User::query()
            ->where('catalog_share_token', $token)
            ->firstOrFail();

        // Solo juegos activos que pertenezcan a
        // una colección pública de este usuario.
        $game = Game::query()
            ->whereKey($game)
            ->where('is_active', true)
            ->whereHas('collections', function ($query) use ($owner) {
                $query
                    ->where('game_collections.created_by', $owner->id)
                    ->where('game_collections.is_public', true);
            })
            ->with([
                'idealPlayerCounts',

                'collections' => fn ($query) => $query
                    ->where('game_collections.created_by', $owner->id)
                    ->where('game_collections.is_public', true)
                    ->orderBy('game_collections.name'),

                'expansions' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('name'),
            ])
            ->firstOrFail();

        return Inertia::render('catalog/show', [
            'catalogToken' => $token,

            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'image_url' => $game->image_url,
                'min_players' => $game->min_players,
                'max_players' => $game->max_players,
                'min_playtime' => $game->min_playtime,
                'max_playtime' => $game->max_playtime,
                'complexity' => $game->complexity,
                'game_mode' => $game->game_mode,
                'game_modes' => $game->game_modes
                    ?? ($game->game_mode ? [$game->game_mode] : []),
                'short_description' => $game->short_description,
                'full_description' => $game->full_description,
                'additional_information' => $game->additional_information,
                'bgg_url' => $game->bgg_url,
                'youtube_url' => $game->youtube_url,

                'ideal_player_counts' => $game
                    ->idealPlayerCounts
                    ->pluck('player_count')
                    ->values(),

                'collections' => $game->collections
                    ->map(fn ($collection) => [
                        'id' => $collection->id,
                        'name' => $collection->name,
                    ])
                    ->values(),

                'expansions' => $game->expansions
                    ->map(fn ($expansion) => [
                        'id' => $expansion->id,
                        'name' => $expansion->name,
                        'image_url' => $expansion->image_url,
                        'bgg_url' => $expansion->bgg_url,
                        'youtube_url' => $expansion->youtube_url,
                    ])
                    ->values(),
            ],
        ]);
    }
}
