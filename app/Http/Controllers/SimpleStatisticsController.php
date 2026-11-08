<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Play;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SimpleStatisticsController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = (int) $request->user()->id;

        // Solamente juegos de colecciones propias, sin duplicar los compartidos.
        // Las partidas se cuentan por propietario y no por todos los usuarios.
        $games = Game::query()
            ->select(['id', 'name', 'image_url'])
            ->whereHas('collections', fn ($query) => $query
                ->where('game_collections.created_by', $userId))
            ->withCount(['plays as times_played' => fn ($query) => $query
                ->where('created_by', $userId)])
            ->withMax(['plays as last_played_at' => fn ($query) => $query
                ->where('created_by', $userId)], 'played_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Game $game) => [
                'id' => $game->id,
                'name' => $game->name,
                'image_url' => $game->image_url,
                'times_played' => (int) $game->times_played,
                'last_played_at' => $game->last_played_at
                    ? substr((string) $game->last_played_at, 0, 10)
                    : null,
            ]);

        $playedGames = $games->filter(fn (array $game) => $game['times_played'] > 0);
        $notRecordedGames = $games->filter(fn (array $game) => $game['times_played'] === 0);

        $mostPlayed = $playedGames
            ->sort(function (array $a, array $b) {
                $byPlays = $b['times_played'] <=> $a['times_played'];
                if ($byPlays !== 0) {
                    return $byPlays;
                }
                $byDate = strcmp($b['last_played_at'] ?? '', $a['last_played_at'] ?? '');
                return $byDate !== 0 ? $byDate : strcasecmp($a['name'], $b['name']);
            })
            ->take(10)
            ->values();

        return Inertia::render('statistics/index', [
            'summary' => [
                'total_plays' => Play::query()->where('created_by', $userId)->count(),
                'games_count' => $games->count(),
                'games_with_plays' => $playedGames->count(),
                'games_without_plays' => $notRecordedGames->count(),
            ],
            'mostPlayed' => $mostPlayed,
            'withoutPlays' => $notRecordedGames->take(10)->values(),
        ]);
    }
}
