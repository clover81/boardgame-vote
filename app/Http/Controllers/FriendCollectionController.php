<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameCollection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Colecciones de amigos: consulta exclusivamente autenticada.
 * Nunca reutilizar estas rutas para guardar, editar o eliminar.
 */
class FriendCollectionController extends Controller
{
    private function authorizeFriend(Request $request, GameCollection $collection): void
    {
        // Nunca revelar colecciones privadas ajenas, tampoco mediante URL directa.
        // Las colecciones propias mantienen sus rutas privadas habituales.
        abort_unless(
            (int) $collection->created_by !== (int) $request->user()->id
            && (bool) $collection->is_public,
            404
        );
    }

    public function index(Request $request, GameCollection $collection): Response
    {
        $this->authorizeFriend($request, $collection);
        $collection->load('creator:id,name');

        $games = Game::query()
            ->where('is_active', true)
            ->whereHas('collections', fn ($query) => $query
                ->where('game_collections.id', $collection->id))
            ->with('idealPlayerCounts:id,game_id,player_count')
            ->orderBy('name')
            ->get(Game::CATALOG_CARD_COLUMNS)
            ->map(fn (Game $game) => [
                'id' => $game->id,
                'name' => $game->name,
                'image_url' => $game->image_url,
                ...$game->catalogPlayerRange(),
                'min_playtime' => $game->min_playtime,
                'max_playtime' => $game->max_playtime,
                'complexity' => $game->ratedComplexity(),
                'game_mode' => $game->game_mode,
                'game_modes' => $game->game_modes
                    ?? ($game->game_mode ? [$game->game_mode] : []),
                'short_description' => $game->short_description,
                'bgg_url' => $game->bgg_url,
                'youtube_url' => $game->youtube_url,
                'collections' => collect([[
                    'id' => $collection->id,
                    'name' => $collection->name,
                ]]),
            ])->values();

        return Inertia::render('catalog/index', [
            // Valor local para mantener independientes los filtros del catálogo.
            'catalogToken' => 'friends-'.$collection->id,
            'catalogBasePath' => route('friends.collections.index', $collection, false),
            'catalogOwnerName' => $collection->creator?->name ?? 'otro usuario',
            'collections' => [[
                'id' => $collection->id,
                'name' => $collection->name,
            ]],
            'games' => $games,
        ]);
    }

    public function show(Request $request, GameCollection $collection, string $game): Response
    {
        $this->authorizeFriend($request, $collection);

        $game = $collection->games()
            ->where('games.is_active', true)
            ->with([
                'idealPlayerCounts:id,game_id,player_count',
                'expansions' => fn ($query) => $query->forCatalog([$collection->id]),
            ])
            ->findOrFail($game, array_map(
                fn (string $column) => 'games.'.$column,
                Game::CATALOG_DETAIL_COLUMNS,
            ));

        return Inertia::render('catalog/show', [
            'catalogToken' => 'friends-'.$collection->id,
            'catalogBasePath' => route('friends.collections.index', $collection, false),
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'image_url' => $game->image_url,
                ...$game->catalogPlayerRange(),
                'min_playtime' => $game->min_playtime,
                'max_playtime' => $game->max_playtime,
                'complexity' => $game->ratedComplexity(),
                'game_mode' => $game->game_mode,
                'game_modes' => $game->game_modes
                    ?? ($game->game_mode ? [$game->game_mode] : []),
                'short_description' => $game->short_description,
                'full_description' => $game->full_description,
                'additional_information' => $game->additional_information,
                'bgg_url' => $game->bgg_url,
                'youtube_url' => $game->youtube_url,
                'collections' => [[
                    'id' => $collection->id,
                    'name' => $collection->name,
                ]],
                'expansions' => $game->expansions->map(fn ($expansion) => [
                    'id' => $expansion->id,
                    'name' => $expansion->name,
                    'image_url' => $expansion->image_url,
                    'bgg_url' => $expansion->bgg_url,
                    'youtube_url' => $expansion->youtube_url,
                ])->values(),
            ],
        ]);
    }
}
