<?php

use App\Models\Player;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('preselects an active player belonging to the signed in user', function () {
    $user = User::factory()->create();
    $player = Player::create([
        'created_by' => $user->id,
        'name' => 'Jugador de prueba',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('plays.create', ['player_id' => $player->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('plays/create-manual')
            ->where('selected_player_id', $player->id)
            ->etc());
});

it('keeps participants empty when opening normal manual registration', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('plays.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('plays/create-manual')
            ->where('selected_player_id', null)
            ->etc());
});

it('does not preselect players from other users accounts', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $player = Player::create([
        'created_by' => $other->id,
        'name' => 'Jugador ajeno',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('plays.create', ['player_id' => $player->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('plays/create-manual')
            ->where('selected_player_id', null)
            ->etc());
});

it('does not preselect inactive players', function () {
    $user = User::factory()->create();
    $player = Player::create([
        'created_by' => $user->id,
        'name' => 'Jugador inactivo',
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->get(route('plays.create', ['player_id' => $player->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('plays/create-manual')
            ->where('selected_player_id', null)
            ->etc());
});
