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

test('la baja BGG de un juego conserva su ficha y solo quita la asociacion', function () {
    $preview = [
        'games' => [['bgg_id' => 987]],
        'expansions' => [],
    ];

    Cache::put(
        $this->collection->bggPreviewCacheKey(),
        $preview,
        now()->addMinutes(5)
    );

    $this->mock(BggCollectionService::class)
        ->shouldReceive('preview')
        ->once()
        ->with('clover81')
        ->andReturn($preview);

    $this->actingAs($this->user)
        ->post(route('collections.bgg.remove', [$this->collection, $this->game]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('game_collection_game', [
        'game_collection_id' => $this->collection->id,
        'game_id' => $this->game->id,
    ]);

    $this->assertDatabaseHas('games', [
        'id' => $this->game->id,
    ]);
});

test('no aplica la baja si cambia la sincronización durante la comprobación', function (string $status, bool $isExpansion) {
    $this->collection->forceFill([
        'bgg_sync_status' => 'completed',
        'bgg_sync_started_at' => now()->subDay(),
        'bgg_last_synced_at' => now()->subDay(),
    ])->save();

    $preview = [
        'games' => [['bgg_id' => $isExpansion ? 123 : 987]],
        'expansions' => [],
    ];

    Cache::put(
        $this->collection->bggPreviewCacheKey(),
        $preview,
        now()->addMinutes(5)
    );

    $this->mock(BggCollectionService::class)
        ->shouldReceive('preview')
        ->once()
        ->with('clover81')
        ->andReturnUsing(function () use ($preview, $status) {
            $this->collection->forceFill([
                'bgg_sync_status' => $status,
                'bgg_sync_started_at' => now(),
                'bgg_last_synced_at' => $status === 'completed' ? now() : $this->collection->bgg_last_synced_at,
            ])->save();

            return $preview;
        });

    $url = $isExpansion
        ? route('collections.bgg.remove-expansion', [$this->collection, $this->expansion])
        : route('collections.bgg.remove', [$this->collection, $this->game]);
    $this->actingAs($this->user)
        ->post($url)
        ->assertRedirect()
        ->assertSessionHasErrors('bgg');

    $this->assertDatabaseHas('game_collection_game', [
        'game_collection_id' => $this->collection->id,
        'game_id' => $this->game->id,
    ]);

    $this->assertDatabaseHas('game_collection_expansion', [
        'game_collection_id' => $this->collection->id,
        'expansion_id' => $this->expansion->id,
    ]);

    $this->assertDatabaseHas('games', [
        'id' => $this->game->id,
    ]);
})->with([
    'juego con sincronización pendiente' => ['pending', false],
    'juego con sincronización en curso' => ['running', false],
    'juego con nueva sincronización completada' => ['completed', false],
    'expansión con nueva sincronización completada' => ['completed', true],
]);
