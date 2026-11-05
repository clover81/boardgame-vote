<?php

use App\Models\Game;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('decision classification considers all recorded games', function ($playedIndexes, $tie, $expectedType) {
    $this->withoutVite();
    $user = User::factory()->create();
    $poll = Poll::create([
        'created_by' => $user->id,
        'name' => 'Decisión con varias partidas',
        'token' => 'MULTIDECISION',
        'status' => 'closed',
    ]);
    $games = collect(['Sky Team', 'The White Castle', 'Heat', 'Dune'])
        ->map(fn ($name) => Game::create([
            'name' => $name,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]));
    $candidates = $games->take(2)
        ->map(fn ($game) => $poll->candidates()->create(['game_id' => $game->id]));

    foreach ($tie ? [[0, 1], [1, 0]] : [[0, 1]] as $index => $choices) {
        $participant = $poll->participants()->create(['display_name' => 'Jugador '.$index]);

        foreach ($choices as $position => $choice) {
            $participant->votes()->create([
                'poll_id' => $poll->id,
                'poll_candidate_id' => $candidates[$choice]->id,
                'position' => $position + 1,
            ]);
        }
    }

    foreach ($playedIndexes as $gameIndex) {
        $poll->plays()->create([
            'created_by' => $user->id,
            'game_id' => $games[$gameIndex]->id,
            'played_at' => '2026-11-01',
        ]);
    }

    $this->actingAs($user)->get(route('statistics.decisions'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statistics/decisions')
            ->where('stats.analysed_sessions', 1)
            ->where('stats.winner_played', $expectedType === 'winner' ? 1 : 0)
            ->where('stats.other_candidate_played', $expectedType === 'other_candidate' ? 1 : 0)
            ->where('stats.outside_played', $expectedType === 'outside' ? 1 : 0)
            ->where('stats.tie_sessions', $tie ? 1 : 0)
            ->has('decisions', 1)
            ->where('decisions.0.decision_type', $expectedType)
            ->where('decisions.0.has_clear_winner', !$tie)
        );
})->with([
    'winner played after an outside game' => [[2, 0], false, 'winner'],
    'winner takes precedence over another candidate' => [[1, 0], false, 'winner'],
    'another candidate played after an outside game' => [[2, 1], false, 'other_candidate'],
    'only outside games played' => [[2, 3], false, 'outside'],
    'a real tie remains a tie with multiple plays' => [[2, 0], true, 'tie'],
]);
