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
    ]);
    $ideal = $game->idealPlayerCounts()->create(['player_count' => 2]);
    $before = $game->fresh()->getAttributes();
    $this->mock(BggCollectionService::class)->shouldReceive('preview')
        ->once()->with('mi-usuario')->andReturn([
            'games' => [['bgg_id' => 100, 'name' => 'Juego', 'status' => $status]],
        ]);
    $bgg = $this->mock(BggService::class);
    if ($status === 'new') {
        $bgg->shouldReceive('game')->once()->with(100)->andReturn([
            ...$this->details,
            'suggested_ideal_player_counts' => [3, 4],
        ]);
    } else {
        $bgg->shouldNotReceive('game');
    }

    $result = app(BggCollectionImporter::class)->importGames($this->collection);

    expect($result['errors'])->toBe([])
        ->and($game->fresh()->getAttributes())->toBe($before)
        ->and($game->idealPlayerCounts()->pluck('id')->all())->toBe([$ideal->id])
        ->and($game->idealPlayerCounts()->pluck('player_count')->all())->toBe([2])
        ->and($this->collection->games()->pluck('games.id')->all())->toBe([$game->id]);
})->with(['existing', 'new']);
