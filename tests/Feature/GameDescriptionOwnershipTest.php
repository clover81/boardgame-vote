<?php

use App\Jobs\GenerateGameDescription;
use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use App\Services\GameDescriptionGenerator;

beforeEach(function () {
    config()->set('services.game_descriptions.enabled', true);

    $this->owner = User::factory()->create();

    $this->collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Mi colección',
    ]);

    $this->game = Game::create([
        'bgg_id' => 987654,
        'name' => 'Juego de prueba',
        'min_players' => 2,
        'max_players' => 4,
        'short_description' => 'Original BGG',
        'description_source' => 'bgg',
        'is_active' => true,
    ]);
});

test('no consume IA si el juego ya no pertenece a ninguna colección', function () {
    $generator = $this->mock(GameDescriptionGenerator::class);
    $generator->shouldNotReceive('generate');

    (new GenerateGameDescription(
        $this->game->id, 987654, 'Original BGG',
        $this->owner->id
    ))->handle($generator);

    expect($this->game->fresh()->full_description)->toBeNull();
});

test('no consume IA para una colección de otro propietario', function () {
    $other = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Otra colección',
    ]);

    $other->games()->attach($this->game->id);

    $generator = $this->mock(GameDescriptionGenerator::class);
    $generator->shouldNotReceive('generate');

    (new GenerateGameDescription(
        $this->game->id, 987654, 'Original BGG',
        $this->owner->id
    ))->handle($generator);

    expect($this->game->fresh()->description_source)->toBe('bgg');
});

test('genera y guarda para un juego que sigue en mi colección', function () {
    $this->collection->games()->attach($this->game->id);

    $generator = $this->mock(GameDescriptionGenerator::class);
    $generator->shouldReceive('generate')->once()->andReturn([
        'short_description' => 'Resumen en español',
        'full_description' => 'Descripción completa en español',
    ]);

    (new GenerateGameDescription(
        $this->game->id, 987654, 'Original BGG',
        $this->owner->id
    ))->handle($generator);

    $game = $this->game->fresh();

    expect($game->description_source)->toBe('generated')
        ->and($game->short_description)->toBe('Resumen en español')
        ->and($game->full_description)->toBe('Descripción completa en español');
});

test('no guarda si se retira el juego mientras responde la IA', function () {
    $this->collection->games()->attach($this->game->id);

    $generator = $this->mock(GameDescriptionGenerator::class);
    $generator->shouldReceive('generate')->once()->andReturnUsing(
        function () {
            $this->collection->games()->detach($this->game->id);

            return [
                'short_description' => 'Resumen generado',
                'full_description' => 'Descripción generada',
            ];
        }
    );

    (new GenerateGameDescription(
        $this->game->id, 987654, 'Original BGG',
        $this->owner->id
    ))->handle($generator);

    expect($this->game->fresh()->description_source)->toBe('bgg')
        ->and($this->game->fresh()->full_description)->toBeNull();
});

test('los trabajos antiguos siguen funcionando si el juego pertenece a una colección', function () {
    $this->collection->games()->attach($this->game->id);

    $generator = $this->mock(GameDescriptionGenerator::class);
    $generator->shouldReceive('generate')->once()->andReturn([
        'short_description' => 'Resumen generado',
        'full_description' => 'Descripción generada',
    ]);

    (new GenerateGameDescription(
        $this->game->id, 987654, 'Original BGG'
    ))->handle($generator);

    expect($this->game->fresh()->description_source)->toBe('generated');
});


test('un trabajo antiguo serializado sin ownerId sigue funcionando', function () {
    $this->collection->games()->attach($this->game->id);

    $generator = $this->mock(GameDescriptionGenerator::class);
    $generator->shouldReceive('generate')->once()->andReturn([
        'short_description' => 'Resumen español',
        'full_description' => 'Descripción completa española',
    ]);

    $job = new GenerateGameDescription(
        $this->game->id,
        987654,
        'Original BGG'
    );

    // Simular un trabajo guardado antes de existir ownerId.
    unset($job->ownerId);

    $restored = unserialize(serialize($job), [
        'allowed_classes' => true,
    ]);

    $restored->handle($generator);

    expect($this->game->fresh()->description_source)
        ->toBe('generated');
});
