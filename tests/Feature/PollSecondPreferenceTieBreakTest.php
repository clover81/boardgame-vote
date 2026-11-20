<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Poll;
use App\Models\User;

test('desempata por segundas preferencias en resultados publicos privados y registro', function () {
    $this->withoutVite();

    $owner = User::factory()->create();

    $collection = GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Colección de prueba',
        'is_default' => true,
    ]);

    $poll = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Desempate real',
        'token' => 'SECONDCHOICETIE',
        'status' => 'closed',
        'ranking_slots' => 3,
        'single_choice' => false,
    ]);

    $names = [
        'Coloma',
        'Rococo: Deluxe Edition',
        'Power Grid',
        'Watson & Holmes',
        'Extra A',
        'Extra B',
    ];

    $games = collect($names)->map(fn ($name) => Game::create([
        'name' => $name,
        'min_players' => 1,
        'max_players' => 6,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]));

    $collection->games()->attach($games->pluck('id')->all());

    $candidates = $games->map(
        fn ($game) => $poll->candidates()->create([
            'game_id' => $game->id,
        ])
    );

    // Primera, segunda y tercera opción de cinco votantes.
    $ballots = [
        [0, 2, 1],
        [0, 2, 1],
        [1, 2, 3],
        [1, 2, 4],
        [3, 0, 5],
    ];

    foreach ($ballots as $index => $choices) {
        $participant = $poll->participants()->create([
            'display_name' => 'Votante ' . ($index + 1),
        ]);

        foreach ($choices as $position => $gameIndex) {
            $participant->votes()->create([
                'poll_id' => $poll->id,
                'poll_candidate_id' => $candidates[$gameIndex]->id,
                'position' => $position + 1,
            ]);
        }
    }

    $participant = $poll->participants()->firstOrFail();

    $public = $this->get(route('vote.participant.show', [
        'token' => $poll->token,
        'voteToken' => $participant->vote_token,
    ]))->assertOk();

    $results = $public->inertiaProps('results');

    expect(array_column($results, 'points'))
        ->toBe([8, 8, 8, 4, 1, 1])
        ->and(array_column($results, 'first_votes'))
        ->toBe([2, 2, 0, 1, 0, 0])
        ->and(array_column($results, 'second_votes'))
        ->toBe([1, 0, 4, 0, 0, 0])
        ->and(array_column($results, 'rank'))
        ->toBe([1, 2, 3, 4, 5, 5])
        ->and(array_column($results, 'is_winner'))
        ->toBe([true, false, false, false, false, false]);

    expect($results[0]['game']['name'])->toBe('Coloma');

    $this->actingAs($owner)
        ->get(route('polls.show', $poll))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('poll.has_clear_winner', true)
            ->where('results', $results)
        );

    $this->get(route('plays.create-from-poll', $poll))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('poll.has_clear_winner', true)
            ->where('defaults.game_ids', [$games[0]->id])
        );
});
