<?php

use App\Jobs\SyncBggCollection;
use App\Models\GameCollection;
use App\Models\User;
use App\Services\BggCollectionImporter;
use App\Services\BggCollectionService;
use App\Services\BggExpansionImporter;

beforeEach(function () {
    $this->collection = GameCollection::forceCreate([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección de prueba',
        'bgg_username' => 'usuario_bgg',
        'bgg_sync_status' => 'pending',
        'bgg_last_synced_at' => '2026-11-02 18:53:03',
    ]);

    $this->bggPreviewResult = ['games' => [], 'expansions' => []];

    $this->mock(BggCollectionService::class)
        ->shouldReceive('preview')
        ->once()
        ->with('usuario_bgg')
        ->andReturnUsing(fn () => $this->bggPreviewResult);
});

test('conserva el contador de juegos si falla un lote parcialmente', function () {
    $this->mock(BggCollectionImporter::class)
        ->shouldReceive('importGames')
        ->once()
        ->andReturn([
            'imported' => [['id' => 11], ['id' => 12]],
            'errors' => [['bgg_id' => 13, 'error' => 'HTTP 429']],
            'remaining_before_import' => 3,
        ]);

    $this->mock(BggExpansionImporter::class)
        ->shouldNotReceive('run');

    $job = new SyncBggCollection($this->collection->id);

    expect(fn () => $job->handle(
        app(BggCollectionImporter::class),
        app(BggExpansionImporter::class),
        app(BggCollectionService::class)
    ))->toThrow(RuntimeException::class, 'Error importando juegos BGG');

    expect($this->collection->fresh()->bgg_sync_games_added)->toBe(2);

    $job->failed(new RuntimeException('Error importando juegos BGG'));

    expect($this->collection->fresh()->bgg_sync_status)->toBe('failed')
        ->and($this->collection->fresh()->bgg_sync_games_added)->toBe(2)
        ->and($this->collection->fresh()->bgg_last_synced_at)
        ->toBe('2026-11-02 18:53:03');
});

test('conserva los contadores cuando falla un lote de expansiones', function () {
    $this->mock(BggCollectionImporter::class)
        ->shouldReceive('importGames')
        ->once()
        ->andReturn([
            'imported' => [['id' => 21]],
            'errors' => [],
            'remaining_before_import' => 1,
        ]);

    $this->mock(BggExpansionImporter::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn([
            'imported' => [['id' => 31]],
            'errors' => [['bgg_id' => 32, 'error' => 'HTTP 500']],
            'pending' => [],
        ]);

    $job = new SyncBggCollection($this->collection->id);

    expect(fn () => $job->handle(
        app(BggCollectionImporter::class),
        app(BggExpansionImporter::class),
        app(BggCollectionService::class)
    ))->toThrow(RuntimeException::class, 'Error importando expansiones BGG');

    expect($this->collection->fresh()->bgg_sync_games_added)->toBe(1)
        ->and($this->collection->fresh()->bgg_sync_expansions_added)->toBe(1);
});

test('comparte una consulta BGG entre lotes y expansiones', function () {
    $preview = [
        'games' => array_map(
            fn ($id) => ['bgg_id' => $id, 'name' => "Juego {$id}", 'status' => 'new'],
            range(1001, 1021)
        ),
        'expansions' => [
            ['bgg_id' => 3001, 'name' => 'Expansión de prueba', 'status' => 'new'],
        ],
    ];

    $this->bggPreviewResult = $preview;

    $this->mock(BggCollectionImporter::class)
        ->shouldReceive('importGames')
        ->twice()
        ->with(\Mockery::type(GameCollection::class), 20, $preview)
        ->andReturn(
            [
                'imported' => array_map(fn ($id) => ['bgg_id' => $id], range(1001, 1020)),
                'errors' => [],
                'remaining_before_import' => 21,
            ],
            [
                'imported' => [['bgg_id' => 1021]],
                'errors' => [],
                'remaining_before_import' => 1,
            ]
        );

    $this->mock(BggExpansionImporter::class)
        ->shouldReceive('run')
        ->once()
        ->with(\Mockery::type(GameCollection::class), false, $preview)
        ->andReturn([
            'imported' => [['bgg_id' => 3001]],
            'errors' => [],
            'pending' => [],
        ]);

    (new SyncBggCollection($this->collection->id))->handle(
        app(BggCollectionImporter::class),
        app(BggExpansionImporter::class),
        app(BggCollectionService::class)
    );

    $collection = $this->collection->fresh();

    expect($collection->bgg_sync_status)->toBe('completed')
        ->and($collection->bgg_sync_games_added)->toBe(21)
        ->and($collection->bgg_sync_expansions_added)->toBe(1)
        ->and($collection->bgg_last_synced_at)->not->toBeNull();
});

test('registra expansiones pendientes sin perder las importaciones', function () {
    $this->mock(BggCollectionImporter::class)
        ->shouldReceive('importGames')
        ->once()
        ->andReturn([
            'imported' => [['id' => 21]],
            'errors' => [],
            'remaining_before_import' => 1,
        ]);

    $this->mock(BggExpansionImporter::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn([
            'imported' => [['id' => 31]],
            'errors' => [],
            'pending' => [
                [
                    'bgg_id' => 4004,
                    'name' => 'Expansión pendiente',
                    'reason' => 'No se encontró un juego base propio.',
                ],
            ],
        ]);

    (new SyncBggCollection($this->collection->id))->handle(
        app(BggCollectionImporter::class),
        app(BggExpansionImporter::class),
        app(BggCollectionService::class)
    );

    $collection = $this->collection->fresh();

    expect($collection->bgg_sync_status)->toBe('completed')
        ->and($collection->bgg_sync_games_added)->toBe(1)
        ->and($collection->bgg_sync_expansions_added)->toBe(1)
        ->and($collection->bgg_sync_expansions_pending)->toBe(1)
        ->and($collection->bgg_last_synced_at)->not->toBeNull();
});
