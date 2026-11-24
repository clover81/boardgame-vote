<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();

    $this->user = User::factory()->create();

    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Colección BGG',
        'is_default' => false,
    ]);

    $this->game = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'is_active' => true,
    ]);

    $this->collection->games()->attach($this->game->id);
});

test('a collection cannot be deleted during BGG synchronization', function (string $status) {
    $this->collection->forceFill([
        'bgg_username' => 'clover81',
        'bgg_sync_status' => $status,
    ])->save();

    $this->actingAs($this->user)
        ->from(route('collections.index'))
        ->delete(route('collections.destroy', $this->collection))
        ->assertRedirect(route('collections.index'))
        ->assertSessionHasErrors('collection');

    $this->assertDatabaseHas('game_collections', [
        'id' => $this->collection->id,
        'bgg_sync_status' => $status,
    ]);

    $this->assertDatabaseHas('game_collection_game', [
        'game_collection_id' => $this->collection->id,
        'game_id' => $this->game->id,
    ]);
})->with(['pending', 'running']);

test('a collection can be deleted after synchronization is no longer active', function (string $status) {
    $this->collection->forceFill([
        'bgg_username' => 'clover81',
        'bgg_sync_status' => $status,
    ])->save();

    $this->actingAs($this->user)
        ->from(route('collections.index'))
        ->delete(route('collections.destroy', $this->collection))
        ->assertRedirect(route('collections.index'))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('game_collections', [
        'id' => $this->collection->id,
    ]);

    $this->assertDatabaseHas('games', [
        'id' => $this->game->id,
    ]);
})->with(['idle', 'completed', 'failed']);

test('a historical play keeps its game when its collection is deleted', function () {
    $player = Player::create([
        'created_by' => $this->user->id,
        'name' => 'Jugador de prueba',
        'is_active' => true,
    ]);
    $play = Play::create([
        'created_by' => $this->user->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-01-15',
    ]);
    $play->players()->attach($player->id);

    $this->actingAs($this->user)
        ->from(route('collections.index'))
        ->delete(route('collections.destroy', $this->collection))
        ->assertSessionHasNoErrors();

    $this->get(route('plays.edit', $play))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/edit')
            ->where('games.0.id', $this->game->id));

    $data = [
        'game_id' => $this->game->id,
        'player_ids' => [$player->id],
        'played_at' => '2026-01-15',
        'notes' => 'Nota corregida',
    ];
    $this->put(route('plays.update', $play), $data)
        ->assertRedirect(route('plays.index'))
        ->assertSessionHasNoErrors();

    $otherGame = Game::create(['name' => 'Juego ajeno', 'min_players' => 1, 'max_players' => 4]);
    $this->put(route('plays.update', $play), array_replace($data, ['game_id' => $otherGame->id]))
        ->assertNotFound();

    $this->assertDatabaseHas('plays', [
        'id' => $play->id,
        'game_id' => $this->game->id,
        'notes' => 'Nota corregida',
    ]);
});
