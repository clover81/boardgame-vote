<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with('idealPlayerCounts')
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
                'ideal_player_counts' => $game->idealPlayerCounts
                    ->pluck('player_count')->values(),
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

    public function show(Request $request, GameCollection $collection, Game $game): Response
    {
        $this->authorizeFriend($request, $collection);

        abort_unless(
            $game->is_active && $collection->games()->whereKey($game->id)->exists(),
            404
        );

        $game->load([
            'idealPlayerCounts',
            'expansions' => fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('expansions.id', DB::table('game_collection_expansion')
                    ->where('game_collection_id', $collection->id)
                    ->select('expansion_id'))
                ->orderBy('name'),
        ]);

        return Inertia::render('catalog/show', [
            'catalogToken' => 'friends-'.$collection->id,
            'catalogBasePath' => route('friends.collections.index', $collection, false),
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
                'ideal_player_counts' => $game->idealPlayerCounts
                    ->pluck('player_count')->values(),
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
