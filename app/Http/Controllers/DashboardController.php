<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Play;
use App\Models\Poll;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $gamesCount = Game::query()
            ->whereHas('collections', fn ($query) => $query
                ->where('game_collections.created_by', $userId))
            ->count();

        $openPollsQuery = Poll::query()
            ->where('created_by', $userId)
            ->where('status', 'open');

        $openPollsCount = (clone $openPollsQuery)->count();

        $openPolls = $openPollsQuery
            ->withCount(['participants', 'candidates'])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (Poll $poll) => [
                'id' => $poll->id,
                'name' => $poll->name,
                'token' => $poll->token,
                'participants_count' => $poll->participants_count,
                'candidates_count' => $poll->candidates_count,
            ]);

        $registeredPollIds = Play::query()
            ->where('created_by', $userId)
            ->whereNotNull('poll_id')
            ->pluck('poll_id');

        $pendingPlaysQuery = Poll::query()
            ->where('created_by', $userId)
            ->where('status', 'closed')
            ->whereNotIn('id', $registeredPollIds);

        $pendingPlaysCount = (clone $pendingPlaysQuery)->count();

        $pendingPlays = $pendingPlaysQuery
            ->latest()
            ->take(5)
            ->get(['id', 'name']);

        $recentPlays = Play::query()
            ->where('created_by', $userId)
            ->with(['game', 'expansion', 'players'])
            ->orderByDesc('played_at')
            ->orderByDesc('id')
            ->take(5)
            ->get()
            ->map(fn (Play $play) => [
                'id' => $play->id,
                'played_at' => $play->played_at?->format('Y-m-d'),
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
                    ->map(fn ($player) => [
                        'id' => $player->id,
                        'name' => $player->name,
                    ])
                    ->values(),
            ]);

        return Inertia::render('Dashboard', [
            'summary' => [
                'games_count' => $gamesCount,
                'open_polls_count' => $openPollsCount,
                'total_plays' => Play::query()->where('created_by', $userId)->count(),
                'pending_plays_count' => $pendingPlaysCount,
            ],
            'openPolls' => $openPolls,
            'pendingPlays' => $pendingPlays,
            'recentPlays' => $recentPlays,
        ]);
    }
}
