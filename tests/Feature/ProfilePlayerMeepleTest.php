<?php

use App\Models\Player;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a user explicitly linked to a player receives only that player color', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $miki = User::factory()->create(['name' => 'Miki']);
    $player = Player::create([
        'created_by' => $owner->id,
        'name' => 'Miki',
        'color' => 'yellow',
        'is_active' => true,
    ]);

    $miki->forceFill(['profile_player_id' => $player->id])->save();

    $this->actingAs($miki)->get('/games')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile_player_color', 'yellow')
            ->where('player_colors', [])
            ->etc());
});

test('the link does not depend on matching names', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $mikel = User::factory()->create(['name' => 'Mikel']);
    $miguel = Player::create([
        'created_by' => $owner->id,
        'name' => 'Miguel',
        'color' => 'red',
        'is_active' => true,
    ]);

    $mikel->forceFill(['profile_player_id' => $miguel->id])->save();

    $this->actingAs($mikel)->get('/games')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile_player_color', 'red')
            ->etc());
});

test('matching a name does not automatically expose another users player color', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $miki = User::factory()->create(['name' => 'Miki']);
    Player::create([
        'created_by' => $owner->id,
        'name' => 'Miki',
        'color' => 'yellow',
        'is_active' => true,
    ]);

    $this->actingAs($miki)->get('/games')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile_player_color', null)
            ->etc());
});
