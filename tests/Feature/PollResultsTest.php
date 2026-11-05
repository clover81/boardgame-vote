<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('public and owner results agree and only a clear winner is preselected', function (
    $ballots,
    $expectedNames,
    $expectedPoints,
    $expectedRanks,
    $expectedWinners,
    $expectedTies,
    $winningGame,
    $additionalGame = null
) {
    $this->withoutVite();
    $owner = User::factory()->create();
    $collection = GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Colección de prueba',
        'is_default' => true,
    ]);
    $poll = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Resultados compartidos',
        'token' => 'RESULTSTEST',
        'status' => 'closed',
    ]);
    $gameNames = ['castle' => 'White Castle', 'heat' => 'Heat', 'other' => 'Sky Team'];
    if ($additionalGame !== null) {
        $gameNames['additional'] = $additionalGame;
    }
    $games = collect($gameNames)
        ->map(fn ($name) => Game::create([
            'name' => $name,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]));
    $collection->games()->attach($games->pluck('id')->all());
    $candidates = $games->map(fn ($game) => $poll->candidates()->create(['game_id' => $game->id]));

    foreach ($ballots as $index => $choices) {
        $participant = $poll->participants()->create(['display_name' => 'Votante '.$index]);

        foreach ($choices as $position => $choice) {
            $participant->votes()->create([
                'poll_id' => $poll->id,
                'poll_candidate_id' => $candidates[$choice]->id,
                'position' => $position + 1,
            ]);
        }
    }

    // The public results must be readable without signing in.
    if ($poll->participants()->doesntExist()) {
        $poll->participants()->create(['display_name' => 'Participante de prueba']);
    }
    $participant = $poll->participants()->firstOrFail();

    $publicResponse = $this->get(route('vote.participant.show', [
        'token' => $poll->token,
        'voteToken' => $participant->vote_token,
    ]));
    $publicResponse->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('vote/show')
        ->where('poll.has_clear_winner', $winningGame !== null)
        ->has('results', $games->count())
    );
    $results = $publicResponse->inertiaProps('results');

    expect(array_column(array_column($results, 'game'), 'name'))->toBe($expectedNames)
        ->and(array_column($results, 'points'))->toBe($expectedPoints)
        ->and(array_column($results, 'rank'))->toBe($expectedRanks)
        ->and(array_column($results, 'is_winner'))->toBe($expectedWinners)
        ->and(array_column($results, 'is_top_tie'))->toBe($expectedTies);

    $this->actingAs($owner)->get(route('polls.show', $poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('poll.has_clear_winner', $winningGame !== null)
            ->where('results', $results)
        );

    $this->get(route('plays.create-from-poll', $poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/create')
            ->where('poll.has_clear_winner', $winningGame !== null)
            ->where('defaults.game_ids', $winningGame ? [$games[$winningGame]->id] : [])
            ->where('defaults.expansion_ids', [])
        );
})->with([
    'three points and one first choice each is a real tie' => [
        [['castle', 'heat'], ['heat', 'castle']],
        ['Heat', 'White Castle', 'Sky Team'],
        [3, 3, 0], [1, 1, 3], [false, false, false], [true, true, false], null,
    ],
    'four points each is decided by first choices' => [
        [['castle', 'heat'], ['castle', 'heat'], ['heat', 'other']],
        ['White Castle', 'Heat', 'Sky Team'],
        [4, 4, 1], [1, 2, 3], [true, false, false], [false, false, false], 'castle',
    ],
    'a lower rank tie does not hide the clear winner' => [
        [['castle', 'heat'], ['castle', 'other']],
        ['White Castle', 'Heat', 'Sky Team'],
        [4, 1, 1], [1, 2, 2], [true, false, false], [false, false, false], 'castle',
    ],
    'a shared second place skips the third rank' => [
        [['castle', 'heat'], ['castle', 'other']],
        ['White Castle', 'Heat', 'Sky Team', 'Dune'],
        [4, 1, 1, 0], [1, 2, 2, 4], [true, false, false, false], [false, false, false, false], 'castle', 'Dune',
    ],
    'no votes does not select an alphabetical winner' => [
        [],
        ['Heat', 'Sky Team', 'White Castle'],
        [0, 0, 0], [1, 1, 1], [false, false, false], [true, true, true], null,
    ],
]);

test('requested score scenarios preserve players independently of the winner', function ($scores, $ranks, $hasWinner) {
    $this->withoutVite();
    $owner = User::factory()->create();
    $collection = GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Colección de prueba',
        'is_default' => true,
    ]);
    $poll = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Resultados y jugadores reales',
        'token' => 'SCORESANDPLAYERS',
        'status' => 'closed',
    ]);
    $playerIds = collect(['Alejandro', 'Juan', 'María'])->map(function ($name) use ($poll) {
        $player = Player::create(['name' => $name, 'is_active' => true]);
        $poll->participants()->create(['player_id' => $player->id, 'display_name' => $name]);

        return $player->id;
    })->all();
    $games = collect();
    $plannedExpansion = null;
    $voterIndex = 0;

    foreach ($scores as $index => [$points, $firstVotes]) {
        $game = Game::create([
            'name' => 'Juego ' . chr(65 + $index),
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]);
        $collection->games()->attach($game->id);
        $games->push($game);
        if ($index === 0) {
            $plannedExpansion = $game->expansions()->create(['name' => 'Prevista', 'is_active' => true]);
        }
        $candidate = $poll->candidates()->create([
            'game_id' => $game->id,
            'expansion_id' => $index === 0 ? $plannedExpansion->id : null,
        ]);

        foreach ([1 => $firstVotes, 2 => $points - ($firstVotes * 2)] as $position => $count) {
            for ($voteIndex = 0; $voteIndex < $count; $voteIndex++) {
                $participant = $poll->participants()->create(['display_name' => 'Votante ' . $voterIndex++]);
                $participant->votes()->create([
                    'poll_id' => $poll->id,
                    'poll_candidate_id' => $candidate->id,
                    'position' => $position,
                ]);
            }
        }
    }

    $participant = $poll->participants()->firstOrFail();

    $publicResponse = $this->get(route('vote.participant.show', [
        'token' => $poll->token,
        'voteToken' => $participant->vote_token,
    ]))->assertOk();
    $results = $publicResponse->inertiaProps('results');
    expect(array_column($results, 'points'))->toBe(array_column($scores, 0));
    expect(array_column($results, 'first_votes'))->toBe(array_column($scores, 1));
    expect(array_column($results, 'rank'))->toBe($ranks);
    expect(array_column($results, 'is_winner'))->toBe(array_map(fn ($rank) => $hasWinner && $rank === 1, $ranks));
    expect(array_column($results, 'is_top_tie'))->toBe(array_map(fn ($rank) => !$hasWinner && $rank === 1, $ranks));

    $this->actingAs($owner)->get(route('polls.show', $poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('poll.has_clear_winner', $hasWinner)
            ->where('results', $results)
        );

    $this->get(route('plays.create-from-poll', $poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('poll.has_clear_winner', $hasWinner)
            ->where('defaults.game_ids', $hasWinner ? [$games->first()->id] : [])
            ->where('defaults.expansion_ids', $hasWinner ? [
                $games->first()->id => $plannedExpansion->id,
            ] : [])
            ->where('defaults.player_ids', $playerIds)
            ->has('players', 3)
        );
})->with([
    'A: more points wins' => [[[8, 3], [6, 2]], [1, 2], true],
    'B: more first choices breaks a points tie' => [[[8, 3], [8, 2]], [1, 2], true],
    'C: equal points and first choices is a real tie' => [[[8, 3], [8, 3]], [1, 1], false],
    'D: a first place tie gives the next game third place' => [[[8, 3], [8, 3], [5, 2]], [1, 1, 3], false],
]);

test('public polls without closed results have no winner', function ($status, $hasCandidate) {
    $this->withoutVite();
    $poll = Poll::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Sin resultados',
        'token' => 'EMPTYRESULTS',
        'status' => $status,
    ]);

    if ($hasCandidate) {
        $game = Game::create([
            'name' => 'Heat',
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
        ]);
        $poll->candidates()->create(['game_id' => $game->id]);
    }

    $poll->participants()->create(['display_name' => 'Participante de prueba']);
    $participant = $poll->participants()->firstOrFail();

    $this->get(route('vote.participant.show', [
        'token' => $poll->token,
        'voteToken' => $participant->vote_token,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vote/show')
            ->where('poll.has_clear_winner', false)
            ->where('results', [])
        );
})->with([
    'an open poll' => ['open', true],
    'a closed poll without candidates' => ['closed', false],
]);
