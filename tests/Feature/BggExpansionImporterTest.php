<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use App\Services\BggCollectionService;
use App\Services\BggExpansionImporter;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $user = User::factory()->create();

    $this->collection = GameCollection::create([
        'created_by' => $user->id,
        'name' => 'Colección de prueba',
    ]);

    // Configurar expresamente el usuario BGG para esta prueba.
    $this->collection->forceFill([
        'bgg_username' => 'tester_bgg',
    ])->save();

    $this->ownGame = Game::create([
        'name' => 'Juego propio',
        'bgg_id' => 1001,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);

    $this->otherGame = Game::create([
        'name' => 'Juego ajeno',
        'bgg_id' => 2002,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);

    $this->collection->games()->attach($this->ownGame->id);

    $this->expansion = $this->otherGame->expansions()->create([
        'name' => 'Expansión existente',
        'bgg_id' => 3003,
    ]);

    config(['services.bgg.token' => 'token-de-prueba']);

    // Impedir peticiones reales a BGG durante las pruebas.
    Http::fake([
        '*' => Http::response('<items></items>', 200),
    ]);

    $this->mock(BggCollectionService::class, function ($mock) {
        $mock->shouldReceive('preview')
            ->once()
            ->with('tester_bgg')
            ->andReturn([
                'games' => [
                    ['bgg_id' => 1001, 'name' => 'Juego propio'],
                ],
                'expansions' => [
                    ['bgg_id' => 3003, 'name' => 'Expansión existente'],
                ],
            ]);
    });
});

test('no asocia una expansión cuyo juego base no pertenece a la colección', function () {
    $resultado = app(BggExpansionImporter::class)
        ->run($this->collection, false);

    $this->assertDatabaseMissing('game_collection_expansion', [
        'game_collection_id' => $this->collection->id,
        'expansion_id' => $this->expansion->id,
    ]);

    expect($resultado['pending'])->toHaveCount(1)
        ->and($resultado['pending'][0]['reason'])
        ->toBe('El juego base no pertenece a esta colección.');

    expect($this->expansion->fresh()->game_id)
        ->toBe($this->otherGame->id);
});

test('permite asociar una expansión existente cuando el juego base es propio', function () {
    $this->expansion->update([
        'game_id' => $this->ownGame->id,
    ]);

    $resultado = app(BggExpansionImporter::class)
        ->run($this->collection, false);

    $this->assertDatabaseHas('game_collection_expansion', [
        'game_collection_id' => $this->collection->id,
        'expansion_id' => $this->expansion->id,
    ]);

    expect($resultado['existing'])->toHaveCount(1)
        ->and($resultado['pending'])->toBeEmpty()
        ->and($this->expansion->fresh()->name)
        ->toBe('Expansión existente');
});
