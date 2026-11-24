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
            ->where('plays.created_by', $userId)
            ->whereHas('players', fn ($query) => $query->where('players.id', $player->id));

        $gameDetails = fn ($query) => $query
            ->select(['id', 'name', 'image_url'])
            ->withExists(['collections as can_view' => fn ($collections) => $collections
                ->where('game_collections.created_by', $userId)]);

        $gamesPlayed = (clone $plays)
            ->select('game_id')
            ->selectRaw('COUNT(*) AS plays_count, MAX(played_at) AS last_played_at')
            ->groupBy('game_id')
            ->with(['game' => $gameDetails])
            ->withCasts(['last_played_at' => 'date'])
            ->get();

        $topGames = $gamesPlayed
            ->map(fn (Play $summary) => [
                'id' => $summary->game->id,
                'name' => $summary->game->name,
                'image_url' => $summary->game->image_url,
                'can_view' => (bool) $summary->game->can_view,
                'plays_count' => (int) $summary->plays_count,
                'last_played_at' => $summary->last_played_at->format('Y-m-d'),
            ])
            ->sort(fn (array $a, array $b) =>
                ($b['plays_count'] <=> $a['plays_count']) ?: strcmp($a['name'], $b['name']))
            ->take(5)
            ->values();

        $frequentPlayers = (clone $plays)
            ->join('play_player', 'play_player.play_id', '=', 'plays.id')
            ->join('players', 'players.id', '=', 'play_player.player_id')
            ->where('players.id', '<>', $player->id)
            ->select('players.id', 'players.name', 'players.created_by')
            ->selectRaw('COUNT(*) AS plays_together')
            ->groupBy('players.id', 'players.name', 'players.created_by')
            ->orderByDesc('plays_together')
            ->orderBy('players.name')
            ->orderBy('players.id')
            ->limit(5)
            ->toBase()
            ->get()
            ->map(fn ($companion) => [
                'id' => $companion->id,
                'name' => $companion->name,
                'can_view' => (int) $companion->created_by === (int) $userId,
                'plays_together' => (int) $companion->plays_together,
            ]);

        $recent = $plays
            ->with(['game' => $gameDetails, 'expansion:id,name', 'players:id,name'])
            ->orderByDesc('played_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'game_id', 'expansion_id', 'played_at', 'notes']);

        $recentPlays = $recent
            ->map(function (Play $play) {
                return [
                    'id' => $play->id,
                    'played_at' => $play->played_at->format('Y-m-d'),
                    'notes' => $play->notes,
                    'game' => [
                        'id' => $play->game->id,
                        'name' => $play->game->name,
                        'image_url' => $play->game->image_url,
                        'can_view' => (bool) $play->game->can_view,
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
                'total_plays' => (int) $gamesPlayed->sum('plays_count'),
                'unique_games' => $gamesPlayed->count(),
                'last_played_at' => $recent->first()?->played_at?->format('Y-m-d'),
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
