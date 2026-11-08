<?php

namespace App\Http\Controllers;

use App\Models\Play;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    public function index(Request $request): Response
    {
        $players = Player::query()
            ->where('created_by', $request->user()->id)
            ->orderBy('name')
            ->get();

        return Inertia::render('players/index', [
            'players' => $players,
        ]);
    }

    public function show(
        Request $request,
        Player $player
    ): Response {
        $userId = $request->user()->id;

        abort_unless(
            $player->created_by === $userId,
            403
        );

        /*
        |--------------------------------------------------------------------------
        | Partidas del jugador registradas por el usuario actual
        |--------------------------------------------------------------------------
        */

        $plays = Play::query()
            ->where('created_by', $userId)
            ->whereHas('players', function ($query) use ($player) {
                $query->where('players.id', $player->id);
            })
            ->with(['game', 'expansion', 'players'])
            ->orderByDesc('played_at')
            ->orderByDesc('id')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | ESTADÍSTICAS GENERALES
        |--------------------------------------------------------------------------
        */

        $totalPlays = $plays->count();
        $uniqueGames = $plays->pluck('game_id')->unique()->count();
        $lastPlayedAt = $plays->first()?->played_at?->format('Y-m-d');

        /*
        |--------------------------------------------------------------------------
        | JUEGOS MÁS JUGADOS
        |--------------------------------------------------------------------------
        */

        $topGames = $plays
            ->groupBy('game_id')
            ->map(function ($gamePlays) {
                $firstPlay = $gamePlays->first();

                return [
                    'id' => $firstPlay->game->id,
                    'name' => $firstPlay->game->name,
                    'image_url' => $firstPlay->game->image_url,
                    'plays_count' => $gamePlays->count(),
                    'last_played_at' => $firstPlay->played_at->format('Y-m-d'),
                ];
            })
            ->sort(function (array $a, array $b) {
                if ($a['plays_count'] !== $b['plays_count']) {
                    return $b['plays_count'] <=> $a['plays_count'];
                }

                return strcmp($a['name'], $b['name']);
            })
            ->take(5)
            ->values();

        /*
        |--------------------------------------------------------------------------
        | COMPAÑEROS MÁS HABITUALES
        |--------------------------------------------------------------------------
        */

        $frequentPlayers = $plays
            ->flatMap(function (Play $play) use ($player) {
                return $play->players->filter(
                    fn (Player $otherPlayer) => $otherPlayer->id !== $player->id
                );
            })
            ->groupBy('id')
            ->map(function ($group) {
                $otherPlayer = $group->first();

                return [
                    'id' => $otherPlayer->id,
                    'name' => $otherPlayer->name,
                    'plays_together' => $group->count(),
                ];
            })
            ->sortByDesc('plays_together')
            ->take(5)
            ->values();

        /*
        |--------------------------------------------------------------------------
        | ÚLTIMAS PARTIDAS
        |--------------------------------------------------------------------------
        */

        $recentPlays = $plays
            ->take(10)
            ->map(function (Play $play) {
                return [
                    'id' => $play->id,
                    'played_at' => $play->played_at->format('Y-m-d'),
                    'notes' => $play->notes,
                    'game' => [
                        'id' => $play->game->id,
                        'name' => $play->game->name,
                        'image_url' => $play->game->image_url,
                    ],
                    'expansion' => $play->expansion
                        ? [
                            'id' => $play->expansion->id,
                            'name' => $play->expansion->name,
                        ]
                        : null,
                    'players' => $play->players
                        ->map(fn (Player $playPlayer) => [
                            'id' => $playPlayer->id,
                            'name' => $playPlayer->name,
                        ])
                        ->values(),
                ];
            })
            ->values();

        return Inertia::render('players/show', [
            'player' => [
                'id' => $player->id,
                'name' => $player->name,
                'is_active' => $player->is_active,
                'color' => $player->color,
            ],
            'stats' => [
                'total_plays' => $totalPlays,
                'unique_games' => $uniqueGames,
                'last_played_at' => $lastPlayedAt,
            ],
            'top_games' => $topGames,
            'frequent_players' => $frequentPlayers,
            'recent_plays' => $recentPlays,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('players/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', Rule::in(Player::COLORS)],
        ]);

        Player::create([
            'created_by' => $request->user()->id,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? 'violet',
            'is_active' => true,
        ]);

        return redirect()
            ->route('players.index')
            ->with('success', 'Jugador añadido correctamente.');
    }

    public function edit(Request $request, Player $player): Response
    {
        abort_unless($player->created_by === $request->user()->id, 403);
        return Inertia::render('players/edit', [
            'player' => $player->only(['id', 'name', 'color']),
        ]);
    }

    public function update(Request $request, Player $player): RedirectResponse
    {
        abort_unless($player->created_by === $request->user()->id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', Rule::in(Player::COLORS)],
        ]);

        $player->update([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? $player->color,
        ]);

        return redirect()->route('players.index')
            ->with('success', 'Jugador actualizado correctamente.');
    }

    public function toggle(Request $request, Player $player): RedirectResponse
    {
        // Comprobar que el jugador pertenece
        // al usuario autenticado.
        abort_unless(
            $player->created_by === $request->user()->id,
            403
        );

        $player->update([
            'is_active' => ! $player->is_active,
        ]);

        return redirect()
            ->route('players.index');
    }
}
