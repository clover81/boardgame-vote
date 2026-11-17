<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Poll;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();

    $this->owner = User::factory()->create();

    $collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Colección de prueba',
        'is_default' => true,
    ]);

    $this->poll = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Tres preferencias',
        'token' => 'THREEVOTES',
        'status' => 'open',
        'ranking_slots' => 3,
        'single_choice' => false,
    ]);

    $games = collect(['White Castle', 'Heat', 'Cascadia'])
        ->map(fn ($name) => Game::create([
            'name' => $name,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]));

    $collection->games()->attach($games->pluck('id'));

    $this->candidates = $games->map(
        fn ($game) => $this->poll->candidates()->create([
            'game_id' => $game->id,
        ])
    );

    $this->participant = $this->poll->participants()->create([
        'display_name' => 'Ana',
    ]);

    $this->voteUrl = route('vote.participant.store', [
        'token' => $this->poll->token,
        'voteToken' => $this->participant->vote_token,
    ]);
});

test('el voto de tres preferencias exige juegos distintos y guarda tres posiciones', function () {
    $ids = $this->candidates->pluck('id')->all();

    $this->from($this->voteUrl)->post($this->voteUrl, [
        'first_candidate_id' => $ids[0],
        'second_candidate_id' => $ids[1],
    ])->assertSessionHasErrors('third_candidate_id');

    $this->assertDatabaseCount('votes', 0);

    $this->from($this->voteUrl)->post($this->voteUrl, [
        'first_candidate_id' => $ids[0],
        'second_candidate_id' => $ids[1],
        'third_candidate_id' => $ids[0],
    ])->assertSessionHasErrors('third_candidate_id');

    $this->assertDatabaseCount('votes', 0);

    $this->from($this->voteUrl)->post($this->voteUrl, [
        'first_candidate_id' => $ids[0],
        'second_candidate_id' => $ids[1],
        'third_candidate_id' => $ids[2],
        'ranking_slots' => 3,
    ])->assertSessionHasNoErrors();

    $saved = $this->participant->votes()
        ->orderBy('position')
        ->get();

    expect($saved->pluck('position')->all())->toBe([1, 2, 3])
        ->and($saved->pluck('poll_candidate_id')->all())->toBe($ids);

    $this->assertDatabaseCount('votes', 3);

    // Cambiar el voto no acumula puntos anteriores.
    $this->from($this->voteUrl)->post($this->voteUrl, [
        'first_candidate_id' => $ids[2],
        'second_candidate_id' => $ids[0],
        'third_candidate_id' => $ids[1],
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseCount('votes', 3);

    expect(
        $this->participant->votes()
            ->orderBy('position')
            ->pluck('poll_candidate_id')
            ->all()
    )->toBe([$ids[2], $ids[0], $ids[1]]);
});

test('los resultados públicos y privados coinciden con puntuación tres dos uno', function () {
    $ids = $this->candidates->pluck('id')->all();

    $ballots = [
        [$ids[0], $ids[1], $ids[2]],
        [$ids[1], $ids[0], $ids[2]],
        [$ids[0], $ids[2], $ids[1]],
    ];

    foreach ($ballots as $index => $choices) {
        $participant = $index === 0
            ? $this->participant
            : $this->poll->participants()->create([
                'display_name' => 'Jugador ' . $index,
            ]);

        foreach ($choices as $position => $candidateId) {
            $participant->votes()->create([
                'poll_id' => $this->poll->id,
                'poll_candidate_id' => $candidateId,
                'position' => $position + 1,
            ]);
        }
    }

    $this->poll->update(['status' => 'closed']);

    $public = $this->get(route('vote.participant.show', [
        'token' => $this->poll->token,
        'voteToken' => $this->participant->vote_token,
    ]))->assertOk();

    $results = $public->inertiaProps('results');

    expect(array_column($results, 'points'))->toBe([8, 6, 4])
        ->and(array_column($results, 'first_votes'))->toBe([2, 1, 0])
        ->and(array_column($results, 'third_votes'))->toBe([0, 1, 2])
        ->and(array_column($results, 'rank'))->toBe([1, 2, 3]);

    $private = $this->actingAs($this->owner)
        ->get(route('polls.show', $this->poll))
        ->assertOk();

    expect($private->inertiaProps('results'))->toBe($results);
});
