<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;

test('a new game is attached to the selected collections owned by its creator', function ($returnToCollection) {
    $user = User::factory()->create();
    $collections = collect(['Mi colección', 'Otra colección'])->map(
        fn ($name) => GameCollection::create([
            'created_by' => $user->id,
            'name' => $name,
        ])
    );

    $filters = $returnToCollection ? ['collection_id' => $collections->last()->id, 'q' => 'Sky Team'] : [];
    $query = $returnToCollection ? ['back' => route('games.index', $filters, false)] : [];

    $this->actingAs($user)->post(route('games.store', $query), [
        'name' => 'Sky Team',
        'min_players' => 2,
        'max_players' => 2,
        'game_mode' => 'cooperative',
        'game_modes' => ['cooperative'],
        'ideal_player_counts' => [2],
        'collection_ids' => $collections->pluck('id')->map('strval')->all(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('games.index', $filters));

    $game = Game::where('name', 'Sky Team')->sole();

    expect($game->collections->modelKeys())->toEqualCanonicalizing(
        $collections->pluck('id')->all()
    );
    expect($game->idealPlayerCounts->pluck('player_count')->all())->toBe([2]);
    expect($game->is_active)->toBeTrue();
    foreach (['bgg_id', 'image_url', 'min_playtime', 'max_playtime', 'complexity', 'short_description', 'additional_information', 'bgg_url', 'youtube_url'] as $field) {
        expect($game->{$field})->toBeNull();
    }
})->with([false, true]);

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
        'game_modes' => ['cooperative'],
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

test('game duration accepts either bound but rejects zero and reversed ranges', function (?int $min, ?int $max, bool $valid) {
    $user = User::factory()->create();
    $collection = GameCollection::create(['created_by' => $user->id, 'name' => 'Mi colección']);
    $payload = [
        'name' => 'Juego de prueba',
        'min_players' => 1,
        'max_players' => 4,
        'min_playtime' => $min,
        'max_playtime' => $max,
        'game_modes' => [],
        'ideal_player_counts' => [],
        'collection_ids' => [$collection->id],
    ];
    $this->actingAs($user);

    $created = $this->post(route('games.store'), $payload);
    if ($valid) {
        $created->assertSessionHasNoErrors();
        $game = Game::sole();
        expect($game->min_playtime)->toBe($min)->and($game->max_playtime)->toBe($max);

        $game->update(['min_playtime' => null, 'max_playtime' => null]);
        $this->put(route('games.update', $game), $payload)->assertSessionHasNoErrors();
        expect($game->fresh()->min_playtime)->toBe($min)
            ->and($game->fresh()->max_playtime)->toBe($max);

        return;
    }

    $created->assertSessionHasErrors('max_playtime');
    $this->assertDatabaseCount('games', 0);
    $game = Game::create(['name' => 'Juego de prueba', 'min_players' => 1, 'max_players' => 4]);
    $game->collections()->attach($collection);
    $this->put(route('games.update', $game), $payload)->assertSessionHasErrors('max_playtime');
    expect($game->fresh()->max_playtime)->toBeNull();
})->with([
    'only minimum' => [30, null, true],
    'only maximum' => [null, 60, true],
    'zero maximum' => [null, 0, false],
    'reversed range' => [90, 60, false],
]);

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
        'game_modes' => ['cooperative'],
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

test('the new game form preselects the requested owned collection', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $default = GameCollection::create(['created_by' => $user->id, 'name' => 'Principal', 'is_default' => true]);
    $selected = GameCollection::create(['created_by' => $user->id, 'name' => 'Juegos del grupo']);
    $foreign = GameCollection::create(['created_by' => User::factory()->create()->id, 'name' => 'Ajena']);

    $this->actingAs($user);
    foreach ([[$selected->id, $selected->id], [$foreign->id, $default->id], [null, $default->id]] as [$requested, $expected]) {
        $this->get(route('games.create', ['collection_id' => $requested]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('games/create')
                ->where('default_collection_ids', [$expected])
            );
    }
});

test('rejects ideal player counts below the minimum supported players', function () {
    $user = User::factory()->create();
    $collection = GameCollection::create([
        'created_by' => $user->id,
        'name' => 'Mi colección',
    ]);

    $this->actingAs($user)->post(route('games.store'), [
        'name' => 'Juego de prueba',
        'min_players' => 2,
        'max_players' => 4,
        'game_modes' => ['competitive'],
        'ideal_player_counts' => [1, 3],
        'collection_ids' => [$collection->id],
    ])->assertSessionHasErrors('ideal_player_counts.0');

    $this->assertDatabaseMissing('games', [
        'name' => 'Juego de prueba',
    ]);
});

test('a game can be created without ideal player recommendations', function () {
    $user = User::factory()->create();
    $collection = GameCollection::create([
        'created_by' => $user->id,
        'name' => 'Mi colección',
    ]);

    $this->actingAs($user)->post(route('games.store'), [
        'name' => 'Juego sin recomendaciones BGG',
        'min_players' => 1,
        'max_players' => 4,
        'game_modes' => [],
        'ideal_player_counts' => [],
        'collection_ids' => [$collection->id],
    ])->assertSessionHasNoErrors()
      ->assertRedirect(route('games.index'));

    $game = Game::where('name', 'Juego sin recomendaciones BGG')->sole();

    expect($game->idealPlayerCounts)->toHaveCount(0);
});
