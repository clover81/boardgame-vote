<?php

use App\Models\Game;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('all recorded games count while candidate conversions count once per poll', function ($tie) {
    $this->withoutVite();
    $user = User::factory()->create();
    $poll = Poll::create([
        'created_by' => $user->id,
        'name' => 'Votación con varias partidas',
        'token' => 'MULTIPLAY',
        'status' => 'closed',
    ]);
    Poll::create([
        'created_by' => $user->id,
        'name' => 'Sin partidas',
        'token' => 'NOPLAYS',
        'status' => 'closed',
    ]);
    $games = collect(['Sky Team', 'The White Castle', 'Heat'])
        ->map(fn ($name) => Game::create([
            'name' => $name,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]));
    $candidates = $games->take(2)
        ->map(fn ($game) => $poll->candidates()->create(['game_id' => $game->id]));
    $ballots = $tie ? [[0, 1], [1, 0]] : [[0, 1]];

    foreach ($ballots as $index => $choices) {
        $participant = $poll->participants()->create(['display_name' => 'Jugador '.$index]);

        foreach ($choices as $position => $choice) {
            $participant->votes()->create([
                'poll_id' => $poll->id,
                'poll_candidate_id' => $candidates[$choice]->id,
                'position' => $position + 1,
            ]);
        }
    }

    // A repeated game counts as two plays, but only one candidate conversion.
    foreach ([0, 0, 1, 2] as $gameIndex) {
        $poll->plays()->create([
            'created_by' => $user->id,
            'game_id' => $games[$gameIndex]->id,
            'played_at' => '2026-11-01',
        ]);
    }

    $response = $this->actingAs($user)->get(route('statistics.games'));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('statistics/games')
        ->where('summary.closed_polls', 2)
        ->where('summary.polls_with_play', 1)
        ->where('summary.candidate_appearances', 2)
        ->has('games', 3)
    );
    $stats = collect($response->inertiaProps('games'))->keyBy('game.id');

    foreach ($games->take(2) as $index => $game) {
        expect($stats[$game->id]['played_as_candidate'])->toBe(1)
            ->and($stats[$game->id]['candidate_conversion'])->toEqual(100)
            ->and($stats[$game->id]['played_after_poll'])->toBe($index === 0 ? 2 : 1)
            ->and($stats[$game->id]['played_from_outside'])->toBe(0)
            ->and($stats[$game->id]['wins_played'])->toBe(!$tie && $index === 0 ? 1 : 0);
    }

    expect($stats[$games[2]->id]['appearances'])->toBe(0)
        ->and($stats[$games[2]->id]['played_after_poll'])->toBe(1)
        ->and($stats[$games[2]->id]['played_from_outside'])->toBe(1);
})->with(['clear winner' => [false], 'tie' => [true]]);

test('recorded plays from another account are excluded from game voting statistics', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $game = Game::create([
        'name' => 'Sky Team',
        'min_players' => 1,
        'max_players' => 2,
        'game_mode' => 'cooperative',
        'is_active' => true,
    ]);
    $poll = Poll::create([
        'created_by' => $user->id,
        'name' => 'Votación propia',
        'token' => 'OWNPOLL',
        'status' => 'closed',
    ]);
    $poll->plays()->create([
        'created_by' => $otherUser->id,
        'game_id' => $game->id,
        'played_at' => '2026-11-01',
    ]);

    $this->actingAs($user)->get(route('statistics.games'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statistics/games')
            ->where('summary.closed_polls', 1)
            ->where('summary.polls_with_play', 0)
            ->has('games', 0)
        );
});
