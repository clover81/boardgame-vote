<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;

test('a new game is attached to the selected collections owned by its creator', function () {
    $user = User::factory()->create();
    $collections = collect(['Mi colección', 'Otra colección'])->map(
        fn ($name) => GameCollection::create([
            'created_by' => $user->id,
            'name' => $name,
        ])
    );

    $this->actingAs($user)->post(route('games.store'), [
        'name' => 'Sky Team',
        'min_players' => 2,
        'max_players' => 2,
        'game_mode' => 'cooperative',
        'ideal_player_counts' => [2],
        'collection_ids' => $collections->pluck('id')->map('strval')->all(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('games.index'));

    $game = Game::where('name', 'Sky Team')->sole();

    expect($game->collections->modelKeys())->toEqualCanonicalizing(
        $collections->pluck('id')->all()
    );
    expect($game->idealPlayerCounts->pluck('player_count')->all())->toBe([2]);
    expect($game->is_active)->toBeTrue();
    foreach (['bgg_id', 'image_url', 'min_playtime', 'max_playtime', 'complexity', 'short_description', 'additional_information', 'bgg_url', 'youtube_url'] as $field) {
        expect($game->{$field})->toBeNull();
    }
});

test('new game creation saves the explicit fields and ignores unvalidated input', function () {
    $user = User::factory()->create();
    $collection = GameCollection::create(['created_by' => $user->id, 'name' => 'Mi colección']);
    $payload = [
        'name' => 'Juego nuevo',
        'bgg_id' => '371942',
        'image_url' => 'https://example.com/game.jpg',
        'min_players' => 1,
        'max_players' => 4,
        'min_playtime' => 60,
        'max_playtime' => 90,
        'complexity' => 2.5,
        'game_mode' => 'cooperative',
        'short_description' => 'Mi descripción',
        'additional_information' => 'Mis notas',
        'bgg_url' => 'https://boardgamegeek.com/boardgame/371942',
        'youtube_url' => 'https://example.com/tutorial',
        'ideal_player_counts' => ['3', '4'],
        'collection_ids' => [(string) $collection->id],
        'is_active' => false,
        'id' => 999999,
    ];

    $this->actingAs($user)->post(route('games.store'), $payload)
        ->assertSessionHasNoErrors()->assertRedirect(route('games.index'));

    $game = Game::sole();
    foreach (['name', 'image_url', 'min_players', 'max_players', 'min_playtime', 'max_playtime', 'game_mode', 'short_description', 'additional_information', 'bgg_url', 'youtube_url'] as $field) {
        expect($game->{$field})->toBe($payload[$field]);
    }
    expect($game->bgg_id)->toBe(371942);
    expect($game->complexity)->toBe('2.50');
    expect($game->is_active)->toBeTrue();
    expect($game->id)->not->toBe(999999);
    expect($game->idealPlayerCounts->pluck('player_count')->all())->toBe([3, 4]);
    expect($game->collections->modelKeys())->toBe([$collection->id]);
});

test('invalid collection selections are rejected before a game is created', function ($selection) {
    $user = User::factory()->create();
    $owned = GameCollection::create([
        'created_by' => $user->id,
        'name' => 'Mi colección',
    ]);
    $foreign = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección ajena',
    ]);

    $collectionIds = match ($selection) {
        'empty' => [],
        'foreign' => [$owned->id, $foreign->id],
        'missing' => [max($owned->id, $foreign->id) + 1],
        'duplicate' => [$owned->id, $owned->id],
        'non-integer' => ['invalid'],
    };

    $response = $this->actingAs($user)->post(route('games.store'), [
        'name' => 'Sky Team',
        'min_players' => 2,
        'max_players' => 2,
        'game_mode' => 'cooperative',
        'ideal_player_counts' => [2],
        'collection_ids' => $collectionIds,
    ]);

    $errorKey = match ($selection) {
        'duplicate', 'non-integer' => 'collection_ids.0',
        default => 'collection_ids',
    };

    $response->assertSessionHasErrors($errorKey);
    $this->assertDatabaseCount('games', 0);
    $this->assertDatabaseCount('game_collection_game', 0);
})->with(['empty', 'foreign', 'missing', 'duplicate', 'non-integer']);
