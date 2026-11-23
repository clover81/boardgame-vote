<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use App\Services\BggCollectionImporter;
use App\Services\BggCollectionService;
use App\Services\BggService;

beforeEach(function () {
    $this->collection = GameCollection::forceCreate([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección BGG',
        'bgg_username' => 'mi-usuario',
    ]);
    $this->details = [
        'bgg_id' => 100,
        'name' => 'Juego importado',
        'min_players' => 2,
        'max_players' => 4,
    ];
});

test('newly imported games save unique ideal player counts within their supported range', function () {
    $this->mock(BggCollectionService::class)->shouldReceive('preview')
        ->once()->with('mi-usuario')->andReturn([
            'games' => [['bgg_id' => 100, 'name' => 'Juego', 'status' => 'new']],
        ]);
    $this->mock(BggService::class)->shouldReceive('game')
        ->once()->with(100)->andReturn([
            ...$this->details,
            'suggested_ideal_player_counts' => [1, 2, '2', 3, '4', 5, 0, -1],
        ]);

    $result = app(BggCollectionImporter::class)->importGames($this->collection);
    $game = Game::sole();

    expect($result['errors'])->toBe([])
        ->and($result['imported'])->toHaveCount(1)
        ->and($game->idealPlayerCounts()->orderBy('player_count')->pluck('player_count')->all())
        ->toBe([2, 3, 4])
        ->and($this->collection->games()->pluck('games.id')->all())->toBe([$game->id]);
});

test('games without BGG suggestions import without ideal player counts', function ($suggestions) {
    $this->mock(BggCollectionService::class)->shouldReceive('preview')
        ->once()->with('mi-usuario')->andReturn([
            'games' => [['bgg_id' => 100, 'name' => 'Juego', 'status' => 'new']],
        ]);
    $details = $this->details;
    if ($suggestions !== null) {
        $details['suggested_ideal_player_counts'] = $suggestions;
    }
    $this->mock(BggService::class)->shouldReceive('game')
        ->once()->with(100)->andReturn($details);

    $result = app(BggCollectionImporter::class)->importGames($this->collection);

    expect($result['errors'])->toBe([])
        ->and(Game::sole()->idealPlayerCounts()->count())->toBe(0);
})->with(['missing suggestions' => [null], 'empty suggestions' => [[]]]);

test('existing games keep their manually configured ideal counts even with an outdated preview', function ($status) {
    $game = Game::create([
        ...$this->details,
        'name' => 'Nombre propio',
        'game_mode' => 'competitive',
        'game_modes' => ['competitive'],
        'image_url' => 'https://example.com/portada-propia.jpg',
        'short_description' => 'Resumen escrito por mí',
        'full_description' => 'Descripción extensa personalizada',
        'description_source' => 'manual',
        'additional_information' => 'Notas personales',
        'bgg_url' => 'https://example.com/enlace-propio',
        'youtube_url' => 'https://example.com/tutorial-propio',
    ]);
    $ideal = $game->idealPlayerCounts()->create(['player_count' => 2]);
    $before = $game->fresh()->getAttributes();
    $this->mock(BggCollectionService::class)->shouldReceive('preview')
        ->once()->with('mi-usuario')->andReturn([
            'games' => [['bgg_id' => 100, 'name' => 'Juego', 'status' => $status]],
        ]);
    // La base de datos local prevalece sobre una vista previa antigua.
    // Nunca volver a consultar ni sobrescribir un juego existente.
    $this->mock(BggService::class)->shouldNotReceive('game');

    $result = app(BggCollectionImporter::class)->importGames($this->collection);

    expect($result['errors'])->toBe([])
        ->and($game->fresh()->getAttributes())->toBe($before)
        ->and($game->idealPlayerCounts()->pluck('id')->all())->toBe([$ideal->id])
        ->and($game->idealPlayerCounts()->pluck('player_count')->all())->toBe([2])
        ->and($this->collection->games()->pluck('games.id')->all())->toBe([$game->id]);
})->with(['existing', 'new']);

test('reutiliza la consulta BGG en varios lotes sin repetir juegos', function () {
    $preview = [
        'games' => [
            ['bgg_id' => 100, 'name' => 'Juego A', 'status' => 'new'],
            ['bgg_id' => 101, 'name' => 'Juego B', 'status' => 'new'],
        ],
        'expansions' => [],
    ];

    // La vista previa ya está disponible: no se consulta otra vez.
    $this->mock(BggCollectionService::class)
        ->shouldNotReceive('preview');

    $bgg = $this->mock(BggService::class);

    foreach ([100, 101] as $id) {
        $bgg->shouldReceive('game')
            ->once()
            ->with($id)
            ->andReturn([
                'bgg_id' => $id,
                'name' => "Juego {$id}",
                'min_players' => 1,
                'max_players' => 4,
            ]);
    }

    $importer = app(BggCollectionImporter::class);

    $first = $importer->importGames($this->collection, 1, $preview);
    $second = $importer->importGames($this->collection, 1, $preview);
    $third = $importer->importGames($this->collection, 1, $preview);

    expect(array_column($first['imported'], 'bgg_id'))->toBe([100])
        ->and(array_column($second['imported'], 'bgg_id'))->toBe([101])
        ->and($third['imported'])->toBe([])
        ->and($first['remaining_before_import'])->toBe(2)
        ->and($second['remaining_before_import'])->toBe(1)
        ->and($third['remaining_before_import'])->toBe(0)
        ->and($first['errors'])->toBe([])
        ->and($second['errors'])->toBe([])
        ->and($third['errors'])->toBe([]);

    $this->assertDatabaseCount('games', 2);

    expect($this->collection->games()->count())->toBe(2);
});
