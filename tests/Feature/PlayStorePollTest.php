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
    $this->game = Game::create([
        'name' => 'Juego elegido para la partida',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Mi colección',
        'is_default' => true,
    ]);
    $this->collection->games()->attach($this->game);
    $this->player = Player::create([
        'created_by' => $this->user->id,
        'name' => 'Ana',
        'is_active' => true,
    ]);
    $this->poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Partida del domingo',
        'token' => 'POLLTEST',
        'status' => 'closed',
    ]);
    $this->payload = [
        'poll_id' => $this->poll->id,
        'game_ids' => [
            $this->game->id,
        ],
        'player_ids' => [$this->player->id],
        'played_at' => '2026-10-25',
        'notes' => 'Jugamos algo distinto de lo votado.',
    ];
});

test('registering a play preserves the poll list filters', function () {
    $back = '/polls?search=A%26B&collection_id='.$this->collection->id.'&status=closed';

    $this->actingAs($this->user)
        ->post(route('plays.store', ['back' => $back]), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('polls.show', ['poll' => $this->poll, 'back' => $back]));

    expect(Play::sole()->poll_id)->toBe($this->poll->id);
});

test('a closed poll can be linked to a play of a game outside its candidates', function () {
    $candidateGame = Game::create([
        'name' => 'Juego que se votó',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $this->poll->candidates()->create(['game_id' => $candidateGame->id]);
    $expansion = $this->game->expansions()->create(['name' => 'Expansión elegida', 'is_active' => true]);
    $this->payload['expansion_ids'] = [
        $this->game->id => $expansion->id,
    ];

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('polls.show', $this->poll))
        ->assertSessionHas('success', 'Partida registrada correctamente.');

    $play = Play::sole();
    expect($play->poll_id)->toBe($this->poll->id);
    expect($play->poll->id)->toBe($this->poll->id);
    expect($this->poll->play->id)->toBe($play->id);
    expect($play->created_by)->toBe($this->user->id);
    expect($play->game_id)->toBe($this->game->id);
    expect($play->expansion_id)->toBe($expansion->id);
    expect($play->notes)->toBe($this->payload['notes']);
    expect($play->players->modelKeys())->toBe([$this->player->id]);

    $this->withoutVite();
    $this->get(route('polls.show', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('poll.status', 'closed')
            ->where('poll.registered_play.id', $play->id)
            ->where('poll.registered_play.game.id', $this->game->id)
            ->where('poll.registered_play.expansion.id', $expansion->id)
            ->where('poll.registered_play.players.0.id', $this->player->id)
        );
});

test('poll defaults can be changed to the game expansion and players actually played', function ($changeGame) {
    $plannedExpansion = $this->game->expansions()->create([
        'name' => 'Expansión prevista',
        'is_active' => true,
    ]);
    $candidate = $this->poll->candidates()->create([
        'game_id' => $this->game->id,
        'expansion_id' => $plannedExpansion->id,
    ]);
    $absentPlayer = Player::create([
        'created_by' => $this->user->id,
        'name' => 'Bea',
        'is_active' => true,
    ]);
    foreach ([$this->player, $absentPlayer] as $player) {
        $participant = $this->poll->participants()->create([
            'player_id' => $player->id,
            'display_name' => $player->name,
        ]);

        $participant->votes()->create([
            'poll_id' => $this->poll->id,
            'poll_candidate_id' => $candidate->id,
            'position' => 1,
        ]);
    }

    $this->withoutVite();
    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('defaults.game_ids', [$this->game->id])
            ->where('defaults.expansion_ids', [
                $this->game->id => $plannedExpansion->id,
            ])
            ->where('defaults.player_ids', [$this->player->id, $absentPlayer->id])
        );

    if ($changeGame) {
        $otherGame = Game::create([
            'name' => 'Sky Team',
            'min_players' => 2,
            'max_players' => 2,
            'game_mode' => 'cooperative',
        ]);
        $this->collection->games()->attach($otherGame);
        $this->payload['game_ids'] = [
            $otherGame->id,
        ];
    }
    $this->payload['expansion_ids'] = [];

    $this->post(route('plays.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('polls.show', $this->poll));

    $play = Play::sole();
    expect($play->game_id)->toBe($this->payload['game_ids'][0]);
    expect($play->expansion_id)->toBeNull();
    expect($play->players->modelKeys())->toBe([$this->player->id]);
    expect($candidate->fresh()->expansion_id)->toBe($plannedExpansion->id);
    expect($this->poll->participants()->count())->toBe(2);
})->with([
    'same game without the planned expansion' => false,
    'a different game without an expansion' => true,
]);

test('a poll belonging to another user cannot be linked to a play', function () {
    $this->poll->update(['created_by' => User::factory()->create()->id]);

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasErrors(['poll_id' => 'La votación seleccionada no está disponible.']);

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
});

test('only closed polls can be linked to a play', function ($status) {
    $this->poll->update(['status' => $status]);

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasErrors([
            'poll_id' => 'La votación seleccionada no está disponible.',
        ]);

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
})->with(['draft', 'open']);

test('resubmitting a registered poll does not create another play', function () {
    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasNoErrors();

    $back = '/polls?search=domingo&status=closed';

    $this->post(route('plays.store', ['back' => $back]), $this->payload)
        ->assertRedirect(route('polls.show', ['poll' => $this->poll, 'back' => $back]))
        ->assertSessionHasErrors([
            'play' => 'Las partidas de esta votación ya han sido registradas.',
        ]);

    $this->assertDatabaseCount('plays', 1);
    $this->assertDatabaseCount('play_player', 1);
});

test('a nonexistent poll is rejected before saving a play', function () {
    $this->payload['poll_id'] = $this->poll->id + 1;

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasErrors('poll_id');

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
});

test('manual plays remain available without a poll', function ($input) {
    if ($input === 'omitted') {
        unset($this->payload['poll_id']);
    } else {
        $this->payload['poll_id'] = null;
    }

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('games.index'))
        ->assertSessionHas('success', 'Partida registrada correctamente.');

    expect(Play::sole()->poll_id)->toBeNull();
    expect(Play::sole()->poll)->toBeNull();
})->with(['omitted', 'null']);

test('store normalizes string IDs and accepts existing global players', function () {
    $this->player->update(['is_active' => false]);
    $expansion = $this->game->expansions()->create(['name' => 'Expansión elegida', 'is_active' => true]);
    $this->payload['game_ids'] = [
        (string) $this->game->id,
    ];
    $this->payload['poll_id'] = (string) $this->poll->id;
    $this->payload['expansion_ids'] = [
        $this->game->id => (string) $expansion->id,
    ];
    $this->payload['player_ids'] = [(string) $this->player->id];

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('polls.show', $this->poll));

    $play = Play::sole();
    expect($play->created_by)->toBe($this->user->id);
    expect($play->game_id)->toBe($this->game->id);
    expect($play->poll_id)->toBe($this->poll->id);
    expect($play->expansion_id)->toBe($expansion->id);
    expect($play->players->modelKeys())->toBe([$this->player->id]);
    expect($play->played_at->toDateString())->toBe($this->payload['played_at']);
    expect($play->notes)->toBe($this->payload['notes']);
});

test('invalid player selections never create a partial play', function ($kind) {
    if ($kind === 'missing') {
        unset($this->payload['player_ids']);
    } else {
        $this->payload['player_ids'] = match ($kind) {
            'empty' => [],
            'duplicate' => [$this->player->id, $this->player->id],
            'nonexistent' => [$this->player->id, 999999],
        };
    }

    $returnUrl = route('plays.create-from-poll', $this->poll);
    $this->actingAs($this->user)->from($returnUrl)->post(route('plays.store'), $this->payload)
        ->assertRedirect($returnUrl)
        ->assertSessionHasErrors(in_array($kind, ['missing', 'empty']) ? 'player_ids' : 'player_ids.1')
        ->assertSessionHasInput('game_ids', [$this->game->id]);

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
})->with(['missing', 'empty', 'duplicate', 'nonexistent']);

test('forged store requests cannot register games outside owned collections', function ($foreignCollection, $fromPoll) {
    $game = Game::create([
        'name' => 'Juego no disponible para el usuario',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    if ($foreignCollection) {
        $collection = GameCollection::create([
            'created_by' => User::factory()->create()->id,
            'name' => 'Colección ajena',
        ]);
        $collection->games()->attach($game);
    }
    $this->payload['game_ids'] = [
        $game->id,
    ];
    $expansion = $game->expansions()->create([
        'name' => 'Expansión inactiva de un juego no accesible',
        'is_active' => false,
    ]);
    $this->payload['expansion_ids'] = [
        $game->id => $expansion->id,
    ];
    if (! $fromPoll) {
        unset($this->payload['poll_id']);
    }

    $this->actingAs($this->user)->from(route('plays.create'))->post(route('plays.store'), $this->payload)
        ->assertRedirect(route('plays.create'))
        ->assertSessionHasErrors(['game_ids' => 'El juego seleccionado no pertenece a tu colección.'])
        ->assertSessionHasInput('game_ids', [$game->id]);

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
})->with([
    'manual with foreign game' => [true, false],
    'poll with foreign game' => [true, true],
    'manual with uncollected game' => [false, false],
    'poll with uncollected game' => [false, true],
]);

test('store accepts a game in another owned collection even when shared with another user', function () {
    $this->collection->games()->detach($this->game);
    $otherOwnedCollection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Juegos del grupo',
        'is_default' => false,
    ]);
    $foreignCollection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección ajena',
    ]);
    $this->game->collections()->attach([$otherOwnedCollection->id, $foreignCollection->id]);

    $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('polls.show', $this->poll));

    expect(Play::sole()->game_id)->toBe($this->game->id);
});

test('legacy expansions are checked against the game for manual and poll registrations', function ($kind, $fromPoll) {
    if ($kind === 'missing') {
        $this->payload['expansion_ids'] = [
            $this->game->id => 999999,
        ];
    } else {
        $game = $kind === 'foreign'
            ? Game::create([
                'name' => 'Otro juego',
                'min_players' => 1,
                'max_players' => 4,
                'game_mode' => 'competitive',
            ])
            : $this->game;
        $expansion = $game->expansions()->create([
            'name' => 'Expansión no válida',
            'is_active' => $kind !== 'inactive',
        ]);
        $this->payload['expansion_ids'] = [
            $this->game->id => $expansion->id,
        ];
    }

    if (! $fromPoll) {
        unset($this->payload['poll_id']);
    }

    $returnUrl = $fromPoll
        ? route('plays.create-from-poll', $this->poll)
        : route('plays.create');

    if ($kind !== 'missing') {
        $this->actingAs($this->user)->post(route('plays.store'), $this->payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect($fromPoll ? route('polls.show', $this->poll) : route('games.index'));

        expect(Play::sole()->expansion_id)->toBe($kind === 'foreign' ? null : $this->payload['expansion_ids'][$this->game->id]);

        return;
    }

    $this->actingAs($this->user)->from($returnUrl)->post(route('plays.store'), $this->payload)
        ->assertRedirect($returnUrl)
        ->assertSessionHasErrors("expansion_ids.{$this->game->id}")
        ->assertSessionHasInput('game_ids', [$this->game->id])
        ->assertSessionHasInput('expansion_ids', $this->payload['expansion_ids'])
        ->assertSessionHasInput('player_ids', [$this->player->id]);

    $this->assertDatabaseCount('plays', 0);
    $this->assertDatabaseCount('play_player', 0);
})->with([
    'manual with foreign expansion' => ['foreign', false],
    'poll with foreign expansion' => ['foreign', true],
    'manual with inactive expansion' => ['inactive', false],
    'poll with inactive expansion' => ['inactive', true],
    'manual with nonexistent expansion' => ['missing', false],
    'poll with nonexistent expansion' => ['missing', true],
]);
