<?php

namespace App\Support;

use App\Models\Poll;

final class PollTieBreakOutcome
{
    public static function forOriginal(Poll $original): ?array
    {
        $round = $original->tieBreakPoll()->first();

        if ($round === null) {
            return null;
        }

        $outcome = [
            'poll_id' => (int) $round->id,
            'status' => $round->status,
            'winner' => null,
        ];

        if ($round->status !== 'closed') {
            return $outcome;
        }

        $round->load([
            'candidates.game',
            'candidates.votes',
        ]);

        $scores = $round->candidates
            ->filter(fn ($candidate) => $candidate->game !== null)
            ->map(fn ($candidate) => [
                'game_id' => (int) $candidate->game_id,
                'game_name' => $candidate->game->name,
                'votes' => $candidate->votes
                    ->where('position', 1)
                    ->count(),
            ])
            ->sortByDesc('votes')
            ->values();

        $leader = $scores->first();
        $second = $scores->get(1);

        if ($leader === null || $leader['votes'] <= 0) {
            return $outcome;
        }

        if ($second === null || $leader['votes'] > $second['votes']) {
            $outcome['winner'] = [...$leader, 'method' => 'votes'];

            return $outcome;
        }

        $drawWinnerId = $original->tie_break_draw_winner_game_id;

        if ($drawWinnerId !== null) {
            $drawWinner = $scores->first(
                fn (array $score) =>
                    $score['votes'] === $leader['votes']
                    && $score['game_id'] === (int) $drawWinnerId
            );

            if ($drawWinner !== null) {
                $outcome['winner'] = [...$drawWinner, 'method' => 'draw'];
            }
        }

        return $outcome;
    }
}
