<?php

use App\Models\Game;
use App\Models\User;
use App\Services\BggService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->bggGame = [
        'bgg_id' => 371942,
        'name' => 'The White Castle',
        'bgg_url' => 'https://boardgamegeek.com/boardgame/371942',
    ];
    $this->expansions = [['bgg_id' => 420, 'name' => 'Matcha']];
});

test('BGG previews accept IDs and official URLs without importing records', function ($input) {
    $service = $this->mock(BggService::class);
    $service->shouldReceive('game')->once()->with(371942)->andReturn($this->bggGame);
    $service->shouldReceive('expansionsForGame')->once()->with(371942)->andReturn($this->expansions);

    $this->actingAs($this->user)
        ->getJson(route('games.import-bgg', ['url' => $input]))
        ->assertOk()
        ->assertExactJson([
            'game' => [...$this->bggGame, 'expansions' => $this->expansions],
            'existing_game' => null,
        ]);

    $this->assertDatabaseCount('games', 0);
    $this->assertDatabaseCount('expansions', 0);
})->with([
    'numeric ID' => ['371942'],
    'ID with whitespace' => [' 371942 '],
    'canonical URL' => ['https://boardgamegeek.com/boardgame/371942'],
    'URL with title and query' => ['https://boardgamegeek.com/boardgame/371942/the-white-castle?test=1'],
    'www over HTTP' => ['http://www.boardgamegeek.com/boardgame/371942/'],
    'uppercase host and scheme' => ['HTTPS://BOARDGAMEGEEK.COM/boardgame/371942'],
]);

test('invalid BGG preview inputs are rejected before contacting the service', function ($input) {
    $service = $this->mock(BggService::class);
    $service->shouldNotReceive('game');
    $service->shouldNotReceive('expansionsForGame');

    $this->actingAs($this->user)
        ->getJson(route('games.import-bgg', ['url' => $input]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'url' => 'Introduce una URL válida de BoardGameGeek o un ID numérico.',
        ]);
})->with([
    'zero' => ['0'],
    'negative ID' => ['-371942'],
    'text' => ['The White Castle'],
    'foreign host' => ['https://example.com/boardgame/371942'],
    'host suffix' => ['https://boardgamegeek.com.example.com/boardgame/371942'],
    'misleading user info' => ['https://boardgamegeek.com@example.com/boardgame/371942'],
    'nonweb protocol' => ['ftp://boardgamegeek.com/boardgame/371942'],
    'wrong resource' => ['https://boardgamegeek.com/boardgameexpansion/371942'],
    'malformed URL' => ['http://['],
]);

test('BGG preview service errors return a generic validation message', function ($stage) {
    $service = $this->mock(BggService::class);

    if ($stage === 'game') {
        $service->shouldReceive('game')->once()->with(371942)
            ->andThrow(new RuntimeException('Detalle interno del fallo.'));
        $service->shouldNotReceive('expansionsForGame');
    } else {
        $service->shouldReceive('game')->once()->with(371942)->andReturn($this->bggGame);
        $service->shouldReceive('expansionsForGame')->once()->with(371942)
            ->andThrow(new RuntimeException('Detalle interno del fallo.'));
    }

    $this->actingAs($this->user)
        ->getJson(route('games.import-bgg', ['url' => '371942']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'url' => 'No se han podido obtener los datos desde BoardGameGeek.',
        ]);

    $this->assertDatabaseCount('games', 0);
})->with(['game', 'expansions']);

test('previewing an existing BGG game preserves manually edited data and expansions', function () {
    $game = Game::create([
        'bgg_id' => 371942,
        'name' => 'Nombre editado',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'short_description' => 'Descripción editada',
        'additional_information' => 'Información editada',
        'youtube_url' => 'https://example.com/tutorial-manual',
    ]);
    $expansion = $game->expansions()->create(['name' => 'Expansión editada', 'notes' => 'Notas propias']);
    $beforeGame = $game->fresh()->getAttributes();
    $beforeExpansion = $expansion->fresh()->getAttributes();
    $service = $this->mock(BggService::class);
    $service->shouldReceive('game')->once()->with(371942)->andReturn($this->bggGame);
    $service->shouldReceive('expansionsForGame')->once()->with(371942)->andReturn($this->expansions);

    $this->actingAs($this->user)
        ->getJson(route('games.import-bgg', ['url' => '371942']))
        ->assertOk()
        ->assertJsonPath('existing_game.id', $game->id)
        ->assertJsonPath('game.expansions', $this->expansions);

    expect($game->fresh()->getAttributes())->toBe($beforeGame)
        ->and($expansion->fresh()->getAttributes())->toBe($beforeExpansion);
    $this->assertDatabaseCount('games', 1);
    $this->assertDatabaseCount('expansions', 1);
});
