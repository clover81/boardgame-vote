<?php

use App\Jobs\GenerateGameDescription;
use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Bus::fake();

    $this->owner = User::factory()->create();

    $this->collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Mi colección',
    ]);

    $this->payload = [
        'name' => 'Juego de prueba BGG',
        'bgg_id' => 987654,
        'min_players' => 2,
        'max_players' => 4,
        'game_modes' => ['competitive'],
        'ideal_player_counts' => [3],
        'collection_ids' => [$this->collection->id],
        'short_description' => '',
    ];
});

test('un juego nuevo de BGG prepara su descripción española', function () {
    Cache::put('bgg-game-v2-987654', [
        'bgg_id' => 987654,
        'description' => '<p>Aventuras y construcción de ciudades.</p>',
    ], now()->addHour());

    $this->actingAs($this->owner)
        ->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('games.index'));

    $game = Game::sole();

    expect($game->short_description)
        ->toBe('Aventuras y construcción de ciudades.')
        ->and($game->description_source)->toBe('bgg')
        ->and($game->full_description)->toBeNull();

    Bus::assertDispatched(
        GenerateGameDescription::class,
        fn ($job) =>
            $job->gameId === $game->id
            && $job->bggId === 987654
            && $job->ownerId === $this->owner->id
            && $job->queue === 'descriptions'
    );

    Bus::assertDispatchedTimes(GenerateGameDescription::class, 1);
});

test('la descripción manual nunca se envía a la IA', function () {
    Cache::put('bgg-game-v2-987654', [
        'bgg_id' => 987654,
        'description' => 'Descripción original de BGG.',
    ], now()->addHour());

    $this->payload['short_description'] =
        'Esta descripción la he escrito yo.';

    $this->actingAs($this->owner)
        ->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors();

    $game = Game::sole();

    expect($game->short_description)
        ->toBe('Esta descripción la he escrito yo.')
        ->and($game->description_source)->toBe('manual');

    Bus::assertNotDispatched(GenerateGameDescription::class);
});

test('sin datos BGG en caché no genera trabajos', function () {
    Cache::forget('bgg-game-v2-987654');

    $this->actingAs($this->owner)
        ->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors();

    $game = Game::sole();

    expect($game->short_description)->toBeNull()
        ->and($game->description_source)->toBeNull();

    Bus::assertNotDispatched(GenerateGameDescription::class);
});

test('un juego BGG existente conserva su descripción', function () {
    $game = Game::create([
        'name' => 'Juego ya existente',
        'bgg_id' => 987654,
        'min_players' => 2,
        'max_players' => 4,
        'short_description' => 'Mi descripción anterior.',
        'description_source' => 'manual',
        'is_active' => true,
    ]);

    Cache::put('bgg-game-v2-987654', [
        'bgg_id' => 987654,
        'description' => 'Descripción diferente de BGG.',
    ], now()->addHour());

    $this->actingAs($this->owner)
        ->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('games.show', $game));

    expect($game->fresh()->short_description)
        ->toBe('Mi descripción anterior.')
        ->and($game->fresh()->description_source)->toBe('manual')
        ->and($game->fresh()->collections->modelKeys())
        ->toBe([$this->collection->id]);

    Bus::assertNotDispatched(GenerateGameDescription::class);
});
