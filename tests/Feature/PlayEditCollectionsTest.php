<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->game = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);
    $this->play = Play::create([
        'created_by' => $this->user->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-10-25',
    ]);
});

test('editing a play lists unique owned games including inactive games and only active expansions', function () {
    $collections = collect(['Mi colección', 'Del grupo'])->map(fn ($name) => GameCollection::create([
        'created_by' => $this->user->id,
        'name' => $name,
    ]));
    $foreignCollection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección ajena',
    ]);
    $createGame = fn ($name, $active = true) => Game::create([
        'name' => $name,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => $active,
    ]);
    $otherGame = $createGame('Heat');
    $inactiveGame = $createGame('Archivado', false);
    $foreignGame = $createGame('Juego ajeno');
    $createGame('Sin colección');
    $this->game->collections()->attach($collections->pluck('id'));
    $otherGame->collections()->attach([$collections->last()->id, $foreignCollection->id]);
    $inactiveGame->collections()->attach($collections->first()->id);
    $foreignGame->collections()->attach($foreignCollection);
    $zExpansion = $this->game->expansions()->create(['name' => 'Zeta', 'is_active' => true]);
    $aExpansion = $this->game->expansions()->create(['name' => 'Alfa', 'is_active' => true]);
    $this->game->expansions()->create(['name' => 'Inactiva', 'is_active' => false]);

    $this->actingAs($this->user)->get(route('plays.edit', $this->play))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/edit')
            ->where('play.id', $this->play->id)
            ->where('play.game_id', $this->game->id)
            ->has('games', 3)
            ->where('games.0.id', $inactiveGame->id)
            ->where('games.1.id', $otherGame->id)
            ->where('games.2.id', $this->game->id)
            ->where('games.2.expansions', [
                ['id' => $aExpansion->id, 'name' => 'Alfa'],
                ['id' => $zExpansion->id, 'name' => 'Zeta'],
            ])
        );
});

test('editing a play without owned collections returns no games', function () {
    $this->actingAs($this->user)->get(route('plays.edit', $this->play))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('games', [])
        );
});

test('another user cannot open a play for editing', function () {
    $this->actingAs(User::factory()->create())->get(route('plays.edit', $this->play))
        ->assertForbidden();
});

test('opening the play edit form requires authentication', function () {
    $this->get(route('plays.edit', $this->play))
        ->assertRedirect(route('login'));
});
