<?php

use App\Models\Player;
use App\Models\User;

it('creates a player with a selected color and preserves the previous API', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post(route('players.store'), ['name' => 'Lola', 'color' => 'pink'])
        ->assertRedirect(route('players.index'));

    $this->assertDatabaseHas('players', [
        'created_by' => $user->id, 'name' => 'Lola', 'color' => 'pink',
    ]);

    $this->post(route('players.store'), ['name' => 'Jugador antiguo'])
        ->assertRedirect(route('players.index'));
});

it('edits a players name and color without changing activity or ownership', function () {
    $user = User::factory()->create();
    $player = Player::create([
        'created_by' => $user->id,
        'name' => 'Ana', 'color' => 'pink', 'is_active' => false,
    ]);
    $this->actingAs($user)->get(route('players.edit', $player))->assertOk();
    $this->patch(route('players.update', $player), [
        'name' => 'Ana María', 'color' => 'teal',
    ])->assertRedirect(route('players.index'));
    $this->assertDatabaseHas('players', [
        'id' => $player->id, 'created_by' => $user->id,
        'name' => 'Ana María', 'color' => 'teal', 'is_active' => false,
    ]);
});

it('rejects invalid colors and keeps the record unchanged', function () {
    $user = User::factory()->create();
    $player = Player::create(['created_by' => $user->id, 'name' => 'Miki', 'color' => 'yellow', 'is_active' => true]);
    $this->actingAs($user)->patch(route('players.update', $player), [
        'name' => 'Nuevo nombre', 'color' => 'url(javascript:alert(1))',
    ])->assertSessionHasErrors('color');
    $this->assertDatabaseHas('players', ['id' => $player->id, 'name' => 'Miki', 'color' => 'yellow']);
});

it('forbids editing players belonging to another account', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $player = Player::create(['created_by' => $owner->id, 'name' => 'Privado', 'color' => 'violet', 'is_active' => true]);

    $this->actingAs($other)->get(route('players.edit', $player))->assertForbidden();
    $this->patch(route('players.update', $player), ['name' => 'Intruso', 'color' => 'red'])->assertForbidden();
    $this->assertDatabaseHas('players', ['id' => $player->id, 'name' => 'Privado', 'color' => 'violet']);
});
