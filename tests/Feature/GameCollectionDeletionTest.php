<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;

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
