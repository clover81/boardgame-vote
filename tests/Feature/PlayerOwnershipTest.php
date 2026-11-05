<?php

use App\Models\Player;
use App\Models\User;

test('a user cannot deactivate a player created by another account', function () {
    // Dos cuentas diferentes.
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    // El primer usuario crea un jugador.
    $this->actingAs($owner)
        ->post(route('players.store'), [
            'name' => 'Jugador privado',
        ])
        ->assertRedirect(route('players.index'));

    $player = Player::where('name', 'Jugador privado')
        ->firstOrFail();

    // El segundo usuario intenta desactivarlo.
    $this->actingAs($otherUser)
        ->patch(route('players.toggle', $player));

    // El jugador debe seguir activo.
    $this->assertDatabaseHas('players', [
        'id' => $player->id,
        'is_active' => true,
    ]);
});

test('a user only sees players belonging to their account', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    Player::create([
        'created_by' => $owner->id,
        'name' => 'Jugador propio',
        'is_active' => true,
    ]);

    Player::create([
        'created_by' => $otherUser->id,
        'name' => 'Jugador ajeno',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('players.index'))
        ->assertOk()
        ->assertInertia(
            fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('players/index')
                ->has('players', 1)
                ->where('players.0.name', 'Jugador propio')
                ->etc()
        );
});

test('a user cannot view another accounts player profile', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $player = Player::create([
        'created_by' => $owner->id,
        'name' => 'Jugador privado',
        'is_active' => true,
    ]);

    // Otra cuenta intenta acceder directamente a la ficha.
    $this->actingAs($otherUser)
        ->get(route('players.show', $player))
        ->assertForbidden();
});

test('a user cannot include another accounts player in a poll', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    // Colección y juego del usuario propietario.
    $collection = \App\Models\GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Mi colección',
    ]);

    $game = \App\Models\Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);

    $collection->games()->attach($game);

    // Un jugador de cada cuenta.
    $ownPlayer = Player::create([
        'created_by' => $owner->id,
        'name' => 'Jugador propio',
        'is_active' => true,
    ]);

    $foreignPlayer = Player::create([
        'created_by' => $otherUser->id,
        'name' => 'Jugador ajeno',
        'is_active' => true,
    ]);

    // Intentamos crear una votación con ambos jugadores.
    $this->actingAs($owner)
        ->post(route('polls.store'), [
            'name' => 'Votación de prueba',
            'collection_ids' => [$collection->id],
            'player_ids' => [
                $ownPlayer->id,
                $foreignPlayer->id,
            ],
            'candidate_ids' => [$game->id],
        ])
        ->assertSessionHasErrors('player_ids');

    // No debe crearse ninguna votación.
    $this->assertDatabaseCount('polls', 0);
});

test('poll creation only displays players belonging to the current user', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    Player::create([
        'created_by' => $owner->id,
        'name' => 'Jugador propio',
        'is_active' => true,
    ]);

    Player::create([
        'created_by' => $otherUser->id,
        'name' => 'Jugador ajeno',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('polls.create'))
        ->assertOk()
        ->assertInertia(
            fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('polls/create')
                ->has('players', 1)
                ->where('players.0.name', 'Jugador propio')
                ->etc()
        );
});

test('a user cannot register a play with another accounts player', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $collection = \App\Models\GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Mi colección',
    ]);

    $game = \App\Models\Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);

    $collection->games()->attach($game);

    $ownPlayer = Player::create([
        'created_by' => $owner->id,
        'name' => 'Jugador propio',
        'is_active' => true,
    ]);

    $foreignPlayer = Player::create([
        'created_by' => $otherUser->id,
        'name' => 'Jugador ajeno',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->post(route('plays.store'), [
            'game_ids' => [$game->id],
            'player_ids' => [
                $ownPlayer->id,
                $foreignPlayer->id,
            ],
            'played_at' => '2026-11-01',
        ])
        ->assertSessionHasErrors('player_ids');

    $this->assertDatabaseCount('plays', 0);
});

test('manual play creation only displays players from the current account', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    Player::create([
        'created_by' => $owner->id,
        'name' => 'Jugador propio',
        'is_active' => true,
    ]);

    Player::create([
        'created_by' => $otherUser->id,
        'name' => 'Jugador ajeno',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('plays.create'))
        ->assertOk()
        ->assertInertia(
            fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('plays/create-manual')
                ->has('players', 1)
                ->where('players.0.name', 'Jugador propio')
                ->etc()
        );
});

test('play editing only displays players from the current account', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $game = \App\Models\Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);

    $ownPlayer = Player::create([
        'created_by' => $owner->id,
        'name' => 'Jugador propio',
        'is_active' => true,
    ]);

    Player::create([
        'created_by' => $otherUser->id,
        'name' => 'Jugador ajeno',
        'is_active' => true,
    ]);

    $play = \App\Models\Play::create([
        'created_by' => $owner->id,
        'game_id' => $game->id,
        'played_at' => '2026-11-01',
    ]);

    $play->players()->attach($ownPlayer);

    $this->actingAs($owner)
        ->get(route('plays.edit', $play))
        ->assertOk()
        ->assertInertia(
            fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('plays/edit')
                ->has('players', 1)
                ->where('players.0.name', 'Jugador propio')
                ->etc()
        );
});

test('a user cannot update a play with another accounts player', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $collection = \App\Models\GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Mi colección',
    ]);

    $game = \App\Models\Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);

    $collection->games()->attach($game);

    $ownPlayer = Player::create([
        'created_by' => $owner->id,
        'name' => 'Jugador propio',
        'is_active' => true,
    ]);

    $foreignPlayer = Player::create([
        'created_by' => $otherUser->id,
        'name' => 'Jugador ajeno',
        'is_active' => true,
    ]);

    $play = \App\Models\Play::create([
        'created_by' => $owner->id,
        'game_id' => $game->id,
        'played_at' => '2026-11-01',
    ]);

    $play->players()->attach($ownPlayer);

    // Intentamos sustituir al jugador propio por uno ajeno.
    $this->actingAs($owner)
        ->put(route('plays.update', $play), [
            'game_id' => $game->id,
            'played_at' => '2026-11-01',
            'player_ids' => [$foreignPlayer->id],
        ])
        ->assertSessionHasErrors('player_ids');

    // La partida debe conservar su participante original.
    expect($play->fresh()->players->modelKeys())
        ->toBe([$ownPlayer->id]);
});

test('game recommendations reject players from another account', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $collection = \App\Models\GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Mi colección',
    ]);

    $ownPlayer = Player::create([
        'created_by' => $owner->id,
        'name' => 'Jugador propio',
        'is_active' => true,
    ]);

    $foreignPlayer = Player::create([
        'created_by' => $otherUser->id,
        'name' => 'Jugador ajeno',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->getJson(route('polls.recommendations', [
            'player_ids' => [
                $ownPlayer->id,
                $foreignPlayer->id,
            ],
            'collection_ids' => [$collection->id],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('player_ids.1');
});

test('game recommendations work with players from the current account', function () {
    $user = User::factory()->create();

    $collection = \App\Models\GameCollection::create([
        'created_by' => $user->id,
        'name' => 'Mi colección',
    ]);

    $game = \App\Models\Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);

    $collection->games()->attach($game);

    $player1 = Player::create([
        'created_by' => $user->id,
        'name' => 'Ana',
        'is_active' => true,
    ]);

    $player2 = Player::create([
        'created_by' => $user->id,
        'name' => 'Bea',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->getJson(route('polls.recommendations', [
            'player_ids' => [
                $player1->id,
                $player2->id,
            ],
            'collection_ids' => [$collection->id],
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'recommendations')
        ->assertJsonPath(
            'recommendations.0.game_id',
            $game->id
        )
        ->assertJsonPath(
            'recommendations.0.selected_players',
            2
        )
        ->assertJsonPath(
            'recommendations.0.group_plays',
            0
        )
        ->assertJsonPath(
            'recommendations.0.rank',
            1
        );
});
