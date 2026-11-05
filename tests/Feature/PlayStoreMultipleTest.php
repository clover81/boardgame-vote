<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Mi colección',
    ]);
    $this->games = collect(['Sky Team', 'The White Castle', 'Heat'])->map(fn ($name) => Game::create([
        'name' => $name,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]));
    $this->collection->games()->attach($this->games->pluck('id')->all());
    $this->players = collect(['Alejandro', 'Ana'])->map(fn ($name) => Player::create([
        'created_by' => $this->user->id,
        'name' => $name,
        'is_active' => true,
    ]));
    $this->poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Empate',
        'token' => 'MULTIPLE',
        'status' => 'closed',
    ]);
    $this->payload = [
        'poll_id' => $this->poll->id,
        'game_ids' => $this->games->take(2)->pluck('id')->map(fn ($id) => (string) $id)->all(),
        'player_ids' => $this->players->pluck('id')->all(),
        'played_at' => '2026-10-31',
        'notes' => 'Jugamos los dos juegos empatados.',
    ];
});

test('multiple games create separate plays with shared details and only their own expansion', function () {
    $expansion = $this->games[1]->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $this->payload['expansion_ids'] = [
        $this->games[0]->id => null,
        $this->games[1]->id => (string) $expansion->id,
    ];
    // El array nuevo tiene prioridad sobre el campo antiguo.
    $this->payload['game_id'] = $this->games[2]->id;

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('polls.show', $this->poll))
        ->assertSessionHas('success', 'Partidas registradas correctamente.');

    $plays = Play::with('players')->orderBy('id')->get();
    expect($plays)->toHaveCount(2);
    $this->assertDatabaseCount('play_player', 4);
    foreach ($plays as $index => $play) {
        expect($play->created_by)->toBe($this->user->id)
            ->and($play->poll_id)->toBe($this->poll->id)
            ->and($play->game_id)->toBe($this->games[$index]->id)
            ->and($play->expansion_id)->toBe($index === 1 ? $expansion->id : null)
            ->and($play->played_at->toDateString())->toBe($this->payload['played_at'])
            ->and($play->notes)->toBe($this->payload['notes'])
            ->and($play->players->modelKeys())->toBe($this->players->pluck('id')->all());
    }

    $this->withoutVite();
    $this->get(route('polls.show', $this->poll))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('poll.plays', 2)
            ->where('poll.plays.0.game.name', 'Sky Team')
            ->where('poll.plays.0.expansion', null)
            ->where('poll.plays.0.players', $this->players->map(fn ($player) => [
                'id' => $player->id,
                'name' => $player->name,
            ])->all())
            ->where('poll.plays.1.game.name', 'The White Castle')
            ->where('poll.plays.1.expansion.name', 'Matcha')
            ->where('poll.plays.1.players', $this->players->map(fn ($player) => [
                'id' => $player->id,
                'name' => $player->name,
            ])->all())
        );

    foreach ($this->games->take(2) as $index => $game) {
        $this->get(route('games.show', $game))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.total_plays', 1)
                ->where('history.plays.0.id', $plays[$index]->id)
                ->where('history.plays.0.expansion', $index === 1
                    ? ['id' => $expansion->id, 'name' => 'Matcha']
                    : null)
            );
    }

    $this->get(route('plays.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('plays', 2)
            ->where('plays.0.game.name', 'The White Castle')
            ->where('plays.0.expansion.name', 'Matcha')
            ->where('plays.1.game.name', 'Sky Team')
            ->where('plays.1.expansion', null)
        );
});

test('an absent or empty game array is rejected even if a legacy game ID is provided', function ($input) {
    unset($this->payload['game_ids']);
    if ($input !== 'omitted') {
        $this->payload['game_ids'] = $input === 'null' ? null : [];
    }
    $this->payload['game_id'] = $this->games[0]->id;

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasErrors('game_ids');

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
})->with(['omitted', 'null', 'empty']);

test('invalid game arrays create no plays or player links', function ($input, $error) {
    $this->payload['game_ids'] = match ($input) {
        'empty' => [],
        'null' => null,
        'scalar' => 'invalid',
        'duplicate' => [$this->games[0]->id, $this->games[0]->id],
        'nonexistent' => [$this->games[0]->id, 999999],
    };

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasErrors($error);

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
})->with([
    ['empty', 'game_ids'],
    ['null', 'game_ids'],
    ['scalar', 'game_ids'],
    ['duplicate', 'game_ids.1'],
    ['nonexistent', 'game_ids.1'],
]);

test('multiple play registration validates every game and the poll before saving', function ($kind) {
    if ($kind === 'game') {
        $this->collection->games()->detach($this->games[1]->id);
    } else {
        $this->poll->update($kind === 'owner'
            ? ['created_by' => User::factory()->create()->id]
            : ['status' => 'open']);
    }

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasErrors($kind === 'game' ? 'game_ids' : 'poll_id');

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
})->with(['game', 'owner', 'status']);

test('an expansion of an unselected game is not assigned to any selected game', function () {
    $expansion = $this->games[2]->expansions()->create([
        'name' => 'Expansión de otro juego',
        'is_active' => true,
    ]);
    $this->payload['expansion_ids'] = [$this->games[0]->id => $expansion->id];

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)->assertSessionHasNoErrors();

    expect(Play::count())->toBe(2)
        ->and(Play::whereNotNull('expansion_id')->count())->toBe(0);
});

test('multiple manual plays can be stored without a poll', function () {
    unset($this->payload['poll_id']);

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasNoErrors()->assertRedirect(route('games.index'));

    expect(Play::count())->toBe(2)
        ->and(Play::whereNotNull('poll_id')->count())->toBe(0);
});

test('each game stores its own expansion when both have one', function () {
    $expansions = $this->games->take(2)->map(fn ($game) => $game->expansions()->create([
        'name' => 'Expansión de '.$game->name,
        'is_active' => true,
    ]));
    $this->payload['expansion_ids'] = [
        $this->games[0]->id => $expansions[0]->id,
        $this->games[1]->id => $expansions[1]->id,
    ];

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)->assertSessionHasNoErrors();

    foreach ($this->games->take(2) as $index => $game) {
        $this->assertDatabaseHas('plays', [
            'game_id' => $game->id,
            'expansion_id' => $expansions[$index]->id,
            'poll_id' => $this->poll->id,
        ]);
    }
});

test('a null per-game expansion takes priority over the legacy field', function () {
    $game = $this->games[1];
    $expansion = $game->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $this->payload['game_ids'] = [$game->id];
    $this->payload['expansion_id'] = $expansion->id;
    $this->payload['expansion_ids'] = [$game->id => null];

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)->assertSessionHasNoErrors();

    expect(Play::sole()->expansion_id)->toBeNull();
});

test('a single game ignores the old expansion field when its map entry is absent', function () {
    $game = $this->games[1];
    $expansion = $game->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $this->payload['game_ids'] = [$game->id];
    $this->payload['expansion_id'] = (string) $expansion->id;
    $this->payload['expansion_ids'] = [];

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)->assertSessionHasNoErrors();

    expect(Play::sole()->expansion_id)->toBeNull();
});

test('a closed poll with a single selected game creates exactly one play', function () {
    $game = $this->games[0];
    $this->poll->candidates()->create(['game_id' => $game->id]);
    $this->payload['game_ids'] = [(string) $game->id];
    $this->payload['expansion_ids'] = [$game->id => null];

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('polls.show', $this->poll))
        ->assertSessionHas('success', 'Partida registrada correctamente.');

    $this->assertDatabaseCount('plays', 1);
    $play = Play::with('players')->sole();
    expect($play->poll_id)->toBe($this->poll->id)
        ->and($play->game_id)->toBe($game->id)
        ->and($play->expansion_id)->toBeNull()
        ->and($play->players->modelKeys())->toBe($this->players->pluck('id')->all());
});

test('the old expansion field is ignored when storing multiple games', function () {
    $this->payload['expansion_id'] = $this->games[1]->expansions()->create([
        'name' => 'Matcha',
        'is_active' => true,
    ])->id;

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)->assertSessionHasNoErrors();

    expect(Play::count())->toBe(2)
        ->and(Play::whereNotNull('expansion_id')->count())->toBe(0);
});

test('a mismatched expansion becomes null without affecting another games valid expansion', function () {
    $expansion = $this->games[1]->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $this->payload['expansion_ids'] = [
        $this->games[0]->id => $expansion->id,
        $this->games[1]->id => $expansion->id,
    ];

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)->assertSessionHasNoErrors();

    expect(Play::where('game_id', $this->games[0]->id)->sole()->expansion_id)->toBeNull()
        ->and(Play::where('game_id', $this->games[1]->id)->sole()->expansion_id)->toBe($expansion->id);
});

test('invalid per-game expansions prevent all plays and player links from being saved', function ($kind) {
    $this->payload['expansion_ids'] = $kind === 'scalar'
        ? 'invalid'
        : [$this->games[1]->id => ($kind === 'nonexistent' ? 999999 : 'invalid')];

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasErrors($kind === 'scalar' ? 'expansion_ids' : 'expansion_ids.'.$this->games[1]->id);

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
})->with(['scalar', 'nonexistent', 'noninteger']);

test('reopening or resubmitting multiple registered plays leaves both plays unchanged', function () {
    $expansion = $this->games[1]->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $this->payload['expansion_ids'] = [
        $this->games[0]->id => null,
        $this->games[1]->id => $expansion->id,
    ];
    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)->assertSessionHasNoErrors();
    $originalPlays = Play::with('players')->orderBy('id')->get()->toArray();

    $this->get(route('plays.create-from-poll', $this->poll))
        ->assertRedirect(route('polls.show', $this->poll))
        ->assertSessionHasErrors([
            'play' => 'Las partidas de esta votación ya han sido registradas.',
        ]);

    foreach ([1, 2] as $attempt) {
        $this->post(route('plays.store'), $this->payload)
            ->assertRedirect(route('polls.show', $this->poll))
            ->assertSessionHasErrors([
                'play' => 'Las partidas de esta votación ya han sido registradas.',
            ]);
    }

    $this->assertDatabaseCount('plays', 2);
    $this->assertDatabaseCount('play_player', 4);
    expect(Play::with('players')->orderBy('id')->get()->toArray())->toBe($originalPlays);
});

test('a failure during the second play rolls back every play and player link', function () {
    $dispatcher = Play::getEventDispatcher();
    Play::setEventDispatcher(clone $dispatcher);

    try {
        Play::creating(function (Play $play) {
            if ($play->game_id === $this->games[1]->id) {
                throw new RuntimeException('Fallo al guardar la segunda partida.');
            }
        });

        $this->withoutExceptionHandling();
        expect(fn () => $this->actingAs($this->user)->post(route('plays.store'), $this->payload))
            ->toThrow(RuntimeException::class, 'Fallo al guardar la segunda partida.');
    } finally {
        Play::setEventDispatcher($dispatcher);
    }

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
});
