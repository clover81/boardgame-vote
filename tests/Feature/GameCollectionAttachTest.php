<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->ownedCollections = collect(['Actual', 'Nueva'])->map(
        fn ($name) => GameCollection::create([
            'created_by' => $this->user->id,
            'name' => $name,
        ])
    );
    $this->foreignCollection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección de Ana',
    ]);
    $this->game = Game::create([
        'name' => 'The White Castle',
        'bgg_id' => 371942,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $this->originalCollectionIds = [
        $this->ownedCollections[0]->id,
        $this->foreignCollection->id,
    ];
    $this->game->collections()->attach($this->originalCollectionIds);
});

test('adding collections preserves all memberships and can be repeated without duplicates', function () {
    $this->actingAs($this->user);

    foreach (range(1, 2) as $attempt) {
        $this->post(route('games.collections.attach', $this->game), [
            'collection_ids' => [$this->ownedCollections[1]->id],
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('games.show', $this->game))
            ->assertSessionHas('success', 'Juego añadido a las colecciones seleccionadas.');
    }

    expect($this->game->collections->modelKeys())->toEqualCanonicalizing([
        ...$this->originalCollectionIds,
        $this->ownedCollections[1]->id,
    ]);
    expect($this->game->fresh()->name)->toBe('The White Castle');
    $this->assertDatabaseCount('games', 1);
    $this->assertDatabaseCount('game_collection_game', 3);
});

test('invalid collection additions are rejected without changing any memberships', function ($selection) {
    $collectionIds = match ($selection) {
        'foreign' => [$this->ownedCollections[1]->id, $this->foreignCollection->id],
        'nonexistent' => [$this->foreignCollection->id + 1],
        'empty' => [],
        'duplicate' => [$this->ownedCollections[1]->id, $this->ownedCollections[1]->id],
        'non-integer' => ['invalid'],
    };

    $this->actingAs($this->user)
        ->post(route('games.collections.attach', $this->game), ['collection_ids' => $collectionIds])
        ->assertSessionHasErrors(match ($selection) {
            'duplicate', 'non-integer' => 'collection_ids.0',
            default => 'collection_ids',
        });

    expect($this->game->collections->modelKeys())->toEqualCanonicalizing($this->originalCollectionIds);
    $this->assertDatabaseCount('game_collection_game', 2);
})->with(['foreign', 'nonexistent', 'empty', 'duplicate', 'non-integer']);

test('guests cannot add games to collections', function () {
    $this->post(route('games.collections.attach', $this->game), [
        'collection_ids' => [$this->ownedCollections[1]->id],
    ])->assertRedirect(route('login'));

    $this->assertDatabaseCount('game_collection_game', 2);
});
