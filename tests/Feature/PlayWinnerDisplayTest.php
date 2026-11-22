<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('play history distinguishes winners from participants without a victory', function () {
    $this->withoutVite();

    $user = User::factory()->create();

    $game = Game::create([
        'name' => 'Partida de prueba',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);

    $collection = GameCollection::create([
        'created_by' => $user->id,
        'name' => 'Principal',
    ]);
    $collection->games()->attach($game);

    $winner = Player::create([
        'created_by' => $user->id,
        'name' => 'Ganadora',
        'is_active' => true,
    ]);

    $other = Player::create([
        'created_by' => $user->id,
        'name' => 'Participante',
        'is_active' => true,
    ]);

    $play = Play::create([
        'created_by' => $user->id,
        'game_id' => $game->id,
        'played_at' => '2026-11-18',
    ]);

    $play->players()->sync([
        $winner->id => ['is_winner' => true],
        $other->id => ['is_winner' => false],
    ]);

    $this->actingAs($user)
        ->get(route('plays.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/index')
            ->where('plays.0.players.0.is_winner', true)
            ->where('plays.0.players.1.is_winner', false)
        );

    $this->get(route('games.show', $game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/show')
            ->where('history.plays.0.players.0.is_winner', true)
            ->where('history.plays.0.players.1.is_winner', false)
        );

    $play->players()->updateExistingPivot(
        $winner->id,
        ['is_winner' => false]
    );

    $this->get(route('plays.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('plays.0.players.0.is_winner', false)
            ->where('plays.0.players.1.is_winner', false)
        );
});
