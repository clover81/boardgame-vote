<?php

use App\Models\Game;
use App\Models\User;
use App\Services\BggService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('authenticated users receive BGG search results as JSON', function () {
    $results = [[
        'bgg_id' => 366013,
        'name' => 'Heat: Pedal to the Metal',
        'year_published' => 2022,
        'bgg_url' => 'https://boardgamegeek.com/boardgame/366013',
    ]];
    $this->mock(BggService::class)->shouldReceive('search')
        ->once()->with('Heat')->andReturn($results);

    $this->actingAs(User::factory()->create())
        ->getJson(route('games.search-bgg', ['query' => 'Heat']))
        ->assertOk()
        ->assertExactJson(['results' => [[...$results[0], 'existing_game_id' => null]]]);
});

test('BGG search results identify existing games by BGG ID and preserve the result order', function () {
    $existingGame = Game::create([
        'name' => 'Título local',
        'bgg_id' => 366013,
        'min_players' => 1,
        'max_players' => 6,
        'game_mode' => 'competitive',
    ]);
    $results = [
        ['bgg_id' => 999, 'name' => 'Heat', 'year_published' => null, 'bgg_url' => 'https://boardgamegeek.com/boardgame/999'],
        ['bgg_id' => 366013, 'name' => 'Heat', 'year_published' => 2022, 'bgg_url' => 'https://boardgamegeek.com/boardgame/366013'],
    ];
    $this->mock(BggService::class)->shouldReceive('search')
        ->once()->with('Heat')->andReturn($results);

    $this->actingAs(User::factory()->create())
        ->getJson(route('games.search-bgg', ['query' => 'Heat']))
        ->assertOk()
        ->assertExactJson(['results' => [
            [...$results[0], 'existing_game_id' => null],
            [...$results[1], 'existing_game_id' => $existingGame->id],
        ]]);
});

test('a BGG search with no matches returns an empty JSON results array', function () {
    $this->mock(BggService::class)->shouldReceive('search')
        ->once()->with('Heat')->andReturn([]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('games.search-bgg', ['query' => 'Heat']))
        ->assertOk()
        ->assertExactJson(['results' => []]);
});

test('invalid BGG queries are rejected before contacting the service', function () {
    $this->mock(BggService::class)->shouldNotReceive('search');

    $this->actingAs(User::factory()->create())
        ->getJson(route('games.search-bgg', ['query' => 'H']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('query');
});

test('BGG search errors are returned as query validation errors', function () {
    $this->mock(BggService::class)->shouldReceive('search')
        ->once()->with('Heat')->andThrow(new RuntimeException('BGG no disponible.'));

    $this->actingAs(User::factory()->create())
        ->getJson(route('games.search-bgg', ['query' => 'Heat']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['query' => 'BGG no disponible.']);
});

test('BGG searches require authentication', function () {
    $this->mock(BggService::class)->shouldNotReceive('search');

    $this->getJson(route('games.search-bgg', ['query' => 'Heat']))
        ->assertUnauthorized();
});

test('the existing BGG preview route uses the current game and expansion methods', function () {
    $url = 'https://boardgamegeek.com/boardgame/366013';
    $game = ['bgg_id' => 366013, 'name' => 'Heat', 'bgg_url' => $url, 'expansions' => []];
    $service = $this->mock(BggService::class);
    $service->shouldReceive('game')
        ->once()->with(366013)->andReturn($game);
    $service->shouldReceive('expansionsForGame')
        ->once()->with(366013)->andReturn([]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('games.import-bgg', ['url' => $url]))
        ->assertOk()
        ->assertExactJson(['game' => $game, 'existing_game' => null]);
});

test('BGG import search works with the real service', function () {
    config([
        'services.bgg.token' => 'token-de-prueba',
        'services.bgg.base_url' => 'https://boardgamegeek.com/xmlapi2',
    ]);

    $query = 'Heat prueba de integracion';
    Cache::forget('bgg-search-with-images-v1-'.md5(mb_strtolower($query)));

    Http::fake([
        '*/search*' => Http::response(
            '<items><item type="boardgame" id="366013"><name type="primary" value="Heat: Pedal to the Metal" /><yearpublished value="2022" /></item></items>',
            200
        ),
        '*/thing*' => Http::response(
            '<items><item type="boardgame" id="366013"><image>https://example.test/heat.jpg</image></item></items>',
            200
        ),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('games.search-bgg', ['query' => $query]))
        ->assertOk()
        ->assertJsonPath('results.0.bgg_id', 366013)
        ->assertJsonPath('results.0.name', 'Heat: Pedal to the Metal')
        ->assertJsonPath('results.0.image_url', 'https://example.test/heat.jpg')
        ->assertJsonPath('results.0.existing_game_id', null);

    Http::assertSentCount(2);
});
