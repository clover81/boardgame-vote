<?php

use App\Jobs\GenerateGameDescription;
use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use App\Services\BggService;
use App\Services\GameDescriptionGenerator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Bus::fake();

    $this->owner = User::factory()->create();

    $this->collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Colección de prueba',
    ]);

    $this->game = Game::create([
        'bgg_id' => 987654,
        'name' => 'Juego de prueba',
        'min_players' => 2,
        'max_players' => 4,
        'short_description' => 'Aventureros y castillos con cartas.',
        'description_source' => 'bgg',
        'is_active' => true,
    ]);

    $this->collection->games()->attach($this->game->id);
});

test('vista previa no consulta BGG ni encola trabajos', function () {
    $this->mock(BggService::class)
        ->shouldNotReceive('game');

    $this->artisan('games:descriptions-recover', [
        '--owner' => $this->owner->id,
        '--limit' => 3,
    ])
        ->expectsOutput('Juegos propios sin trabajo: 1')
        ->assertExitCode(0);

    Bus::assertNotDispatched(GenerateGameDescription::class);
});

test('solo recupera juegos del propietario indicado', function () {
    $otherOwner = User::factory()->create();

    $otherCollection = GameCollection::create([
        'created_by' => $otherOwner->id,
        'name' => 'Colección ajena',
    ]);

    $otherGame = Game::create([
        'bgg_id' => 123456,
        'name' => 'Juego ajeno',
        'min_players' => 2,
        'max_players' => 4,
        'short_description' => 'Texto ajeno.',
        'description_source' => 'bgg',
        'is_active' => true,
    ]);

    $otherCollection->games()->attach($otherGame->id);

    $this->mock(BggService::class)
        ->shouldReceive('game')
        ->once()
        ->with(987654)
        ->andReturn([
            'bgg_id' => 987654,
            'description' => '<p>Aventureros y castillos con cartas.</p>',
        ]);

    $this->artisan('games:descriptions-recover', [
        '--owner' => $this->owner->id,
        '--limit' => 5,
        '--apply' => true,
    ])->assertExitCode(0);

    Bus::assertDispatched(
        GenerateGameDescription::class,
        fn ($job) =>
            $job->gameId === $this->game->id &&
            $job->ownerId === $this->owner->id &&
            $job->queue === 'descriptions'
    );

    Bus::assertDispatchedTimes(GenerateGameDescription::class, 1);
});

test('encolar no ejecuta OpenAI ni altera las fichas', function () {
    $before = $this->game->fresh()->getAttributes();

    $this->mock(GameDescriptionGenerator::class)
        ->shouldNotReceive('generate');

    $this->mock(BggService::class)
        ->shouldReceive('game')
        ->once()
        ->andReturn([
            'bgg_id' => 987654,
            'description' => 'Aventureros y castillos con cartas.',
        ]);

    $this->artisan('games:descriptions-recover', [
        '--owner' => $this->owner->id,
        '--apply' => true,
    ])->assertExitCode(0);

    Bus::assertDispatched(GenerateGameDescription::class);

    expect($this->game->fresh()->getAttributes())->toBe($before);
});

test('no encola si el texto BGG no coincide con el original', function () {
    $this->mock(BggService::class)
        ->shouldReceive('game')
        ->once()
        ->andReturn([
            'bgg_id' => 987654,
            'description' => 'Una descripción completamente diferente.',
        ]);

    $this->artisan('games:descriptions-recover', [
        '--owner' => $this->owner->id,
        '--apply' => true,
    ])->assertExitCode(0);

    Bus::assertNotDispatched(GenerateGameDescription::class);

    expect($this->game->fresh()->description_source)->toBe('bgg');
});

test('no prepara un trabajo si el juego ya está en cola', function () {
    DB::table('jobs')->insert([
        'queue' => 'descriptions',
        'payload' => json_encode([
            'displayName' => GenerateGameDescription::class,
            'data' => [
                'command' =>
                    's:6:"gameId";i:' . $this->game->id . ';',
            ],
        ]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    $this->mock(BggService::class)
        ->shouldNotReceive('game');

    $this->artisan('games:descriptions-recover', [
        '--owner' => $this->owner->id,
        '--apply' => true,
    ])
        ->expectsOutput('Juegos propios sin trabajo: 0')
        ->assertExitCode(0);

    Bus::assertNotDispatched(GenerateGameDescription::class);
});
