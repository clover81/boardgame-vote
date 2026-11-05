<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->collections = collect(['Mi colección', 'Del grupo'])->map(fn ($name) => GameCollection::create([
        'created_by' => $this->user->id,
        'name' => $name,
    ]));
    $this->games = collect(['The White Castle', 'Heat'])->map(fn ($name) => Game::create([
        'name' => $name,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]));
    $this->games->first()->collections()->attach($this->collections->pluck('id'));
    $this->games->last()->collections()->attach($this->collections->last());
    $players = collect(['Ana', 'Bea'])->map(fn ($name) => Player::create([
        'created_by' => $this->user->id,
        'name' => $name,
        'is_active' => true,
    ]));
    $this->payload = [
        'name' => 'Partida del domingo',
        'collection_ids' => $this->collections->pluck('id')->all(),
        'player_ids' => $players->pluck('id')->all(),
        'candidate_ids' => $this->games->pluck('id')->all(),
    ];
});

test('poll candidates can come from the union of selected owned collections', function ($candidateCount) {
    $this->payload['candidate_ids'] = array_slice($this->payload['candidate_ids'], 0, $candidateCount);

    $this->actingAs($this->user)->post(route('polls.store'), $this->payload)
        ->assertSessionHasNoErrors();

    $poll = Poll::sole();
    expect($poll->status)->toBe('draft');
    expect($poll->created_by)->toBe($this->user->id);
    expect($poll->candidates->pluck('game_id')->all())->toBe($this->payload['candidate_ids']);
    expect($poll->gameCollections->modelKeys())->toEqualCanonicalizing($this->collections->pluck('id')->all());
    expect($poll->participants->pluck('player_id')->all())->toBe($this->payload['player_ids']);
})->with(['one candidate' => [1], 'multiple candidates' => [2]]);

test('poll store rejects inactive games and games outside selected collections before creating records', function ($kind) {
    if ($kind === 'inactive') {
        $this->games->last()->update(['is_active' => false]);
    } else {
        $game = Game::create([
            'name' => 'Juego fuera de la selección',
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]);
        if ($kind !== 'uncollected') {
            $collection = GameCollection::create([
                'created_by' => $kind === 'foreign' ? User::factory()->create()->id : $this->user->id,
                'name' => 'Otra colección',
            ]);
            $collection->games()->attach($game);
        }
        $this->payload['candidate_ids'][1] = $game->id;
    }

    $this->actingAs($this->user)->post(route('polls.store'), $this->payload)
        ->assertSessionHasErrors([
            'candidate_ids' => 'Algún juego candidato no pertenece a las colecciones seleccionadas.',
        ]);

    $this->assertDatabaseCount('polls', 0);
    $this->assertDatabaseCount('poll_candidates', 0);
    $this->assertDatabaseCount('poll_participants', 0);
})->with(['inactive', 'unselected_owned', 'foreign', 'uncollected']);

test('poll store rejects another users collection even when its games are otherwise available', function () {
    $collection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección ajena',
    ]);
    $this->payload['collection_ids'][] = $collection->id;

    $this->actingAs($this->user)->post(route('polls.store'), $this->payload)
        ->assertSessionHasErrors([
            'collection_ids' => 'Alguna de las colecciones seleccionadas no está disponible.',
        ]);

    $this->assertDatabaseCount('polls', 0);
});

test('poll store requires at least two players and one candidate game', function ($field, $count) {
    $this->payload[$field] = array_slice($this->payload[$field], 0, $count);

    $this->actingAs($this->user)->post(route('polls.store'), $this->payload)
        ->assertSessionHasErrors($field);

    $this->assertDatabaseCount('polls', 0);
    $this->assertDatabaseCount('poll_candidates', 0);
    $this->assertDatabaseCount('poll_participants', 0);
})->with([
    'one player' => ['player_ids', 1],
    'no candidates' => ['candidate_ids', 0],
]);

test('poll store normalizes string IDs and saves collections participants notes and the planned expansion', function () {
    $game = $this->games->first();
    $expansion = $game->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $expectedPlayerIds = $this->payload['player_ids'];
    $expectedGameIds = $this->payload['candidate_ids'];
    foreach (['collection_ids', 'player_ids', 'candidate_ids'] as $field) {
        $this->payload[$field] = array_map('strval', $this->payload[$field]);
    }
    $this->payload['candidate_notes'] = [$game->id => str_repeat('n', 1000)];
    $this->payload['candidate_expansion_ids'] = [$game->id => (string) $expansion->id];

    $response = $this->actingAs($this->user)->post(route('polls.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Votación creada correctamente.');

    $poll = Poll::sole();
    $response->assertRedirect(route('polls.show', $poll));
    expect($poll->created_by)->toBe($this->user->id);
    expect($poll->status)->toBe('draft');
    expect($poll->token)->toMatch('/^[A-Z0-9]{6}$/');
    expect($poll->gameCollections->modelKeys())->toEqualCanonicalizing($this->collections->pluck('id')->all());
    expect($poll->participants->pluck('player_id')->all())->toBe($expectedPlayerIds);
    expect($poll->participants->pluck('display_name')->all())->toBe(['Ana', 'Bea']);
    expect($poll->candidates->pluck('game_id')->all())->toBe($expectedGameIds);
    $candidate = $poll->candidates->firstWhere('game_id', $game->id);
    expect($candidate->expansion_id)->toBe($expansion->id);
    expect($candidate->note)->toBe(str_repeat('n', 1000));
    expect($poll->candidates->last()->expansion_id)->toBeNull();
    expect($poll->candidates->last()->note)->toBeNull();
    $this->assertDatabaseCount('poll_game_collection', 2);
});

test('inactive players are rejected before creating a poll', function () {
    Player::findOrFail($this->payload['player_ids'][0])->update(['is_active' => false]);

    $this->actingAs($this->user)->from(route('polls.create'))->post(route('polls.store'), $this->payload)
        ->assertRedirect(route('polls.create'))
        ->assertSessionHasErrors(['player_ids' => 'Alguno de los jugadores seleccionados no está disponible.'])
        ->assertSessionHasInput('name', $this->payload['name']);

    foreach (['polls', 'poll_game_collection', 'poll_participants', 'poll_candidates'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});

test('planned expansions must be active and belong to their exact candidate game', function ($wrongGame) {
    $game = $this->games->first();
    $expansion = ($wrongGame ? $this->games->last() : $game)->expansions()->create([
        'name' => 'Expansión no disponible',
        'is_active' => $wrongGame,
    ]);
    $this->payload['candidate_expansion_ids'] = [$game->id => $expansion->id];

    $this->actingAs($this->user)->from(route('polls.create'))->post(route('polls.store'), $this->payload)
        ->assertRedirect(route('polls.create'))
        ->assertSessionHasErrors([
            "candidate_expansion_ids.$game->id" => 'La expansión seleccionada no pertenece a este juego.',
        ])
        ->assertSessionHasInput('name', $this->payload['name']);

    foreach (['polls', 'poll_game_collection', 'poll_participants', 'poll_candidates'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with(['another games expansion' => true, 'inactive expansion' => false]);

test('nonexistent selected IDs are rejected without creating partial poll records', function ($field) {
    if ($field === 'candidate_expansion_ids') {
        $key = $this->games->first()->id;
    } else {
        $key = 0;
    }
    $this->payload[$field][$key] = 999999;

    $this->actingAs($this->user)->post(route('polls.store'), $this->payload)
        ->assertSessionHasErrors("$field.$key");

    foreach (['polls', 'poll_game_collection', 'poll_participants', 'poll_candidates'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with(['collection_ids', 'player_ids', 'candidate_ids', 'candidate_expansion_ids']);

test('candidate notes longer than one thousand characters are rejected', function () {
    $gameId = $this->games->first()->id;
    $this->payload['candidate_notes'] = [$gameId => str_repeat('n', 1001)];

    $this->actingAs($this->user)->post(route('polls.store'), $this->payload)
        ->assertSessionHasErrors("candidate_notes.$gameId");

    foreach (['polls', 'poll_game_collection', 'poll_participants', 'poll_candidates'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});
