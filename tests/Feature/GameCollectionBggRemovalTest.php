<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use App\Services\BggCollectionService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'BGG de prueba',
    ]);
    $this->collection->forceFill(['bgg_username' => 'clover81'])->save();

    $this->game = Game::create([
        'bgg_id' => 123,
        'name' => 'Juego base conservado',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $this->collection->games()->attach($this->game->id);
    $this->expansion = $this->game->expansions()->create([
        'bgg_id' => 456,
        'name' => 'Expansión retirada de BGG',
    ]);
    $this->collection->expansions()->attach($this->expansion->id);
    Cache::forget($this->collection->bggPreviewCacheKey());

    // Simula una vista previa real ya consultada por el usuario.
    Cache::put($this->collection->bggPreviewCacheKey(), [
        'games' => [['bgg_id' => 123]],
        'expansions' => [],
    ], now()->addMinutes(5));
});

test('permite confirmar la baja de la última expansión tras una segunda comprobación independiente', function () {
    $this->mock(BggCollectionService::class)->shouldReceive('preview')
        ->once()->with('clover81')->andReturn([
            'games' => [['bgg_id' => 123]],
            'expansions' => [],
        ]);

    $this->actingAs($this->user)
        ->post(route('collections.bgg.remove-expansion', [$this->collection, $this->expansion]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('game_collection_expansion', [
        'game_collection_id' => $this->collection->id,
        'expansion_id' => $this->expansion->id,
    ]);
    $this->assertDatabaseHas('expansions', ['id' => $this->expansion->id]);
    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
});

test('no elimina nada si BGG limita las peticiones HTTP 429', function () {
    $this->mock(BggCollectionService::class)->shouldReceive('preview')
        ->once()->with('clover81')
        ->andThrow(new RuntimeException('BGG está limitando consultas (HTTP 429)'));

    $this->actingAs($this->user)
        ->post(route('collections.bgg.remove-expansion', [$this->collection, $this->expansion]))
        ->assertRedirect()->assertSessionHasErrors('bgg');

    $this->assertDatabaseHas('game_collection_expansion', [
        'game_collection_id' => $this->collection->id,
        'expansion_id' => $this->expansion->id,
    ]);
});

test('no confirma la baja si la expansión vuelve a figurar como propia en BGG', function () {
    $this->mock(BggCollectionService::class)->shouldReceive('preview')
        ->once()->with('clover81')->andReturn([
            'games' => [['bgg_id' => 123]],
            'expansions' => [['bgg_id' => 456]],
        ]);

    $this->actingAs($this->user)
        ->post(route('collections.bgg.remove-expansion', [$this->collection, $this->expansion]))
        ->assertRedirect()->assertSessionHasErrors('bgg');

    $this->assertDatabaseHas('game_collection_expansion', [
        'game_collection_id' => $this->collection->id,
        'expansion_id' => $this->expansion->id,
    ]);
});
