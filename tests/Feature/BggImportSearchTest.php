<?php

use App\Models\Game;
use App\Models\User;
use App\Services\BggService;

test('authenticated users receive BGG search results as JSON', function () {
    $results = [[
        'bgg_id' => 366013,
        'name' => 'Heat: Pedal to the Metal',
        'year_published' => 2022,
        'bgg_url' => 'https://boardgamegeek.com/boardgame/366013',
    ]];
    $this->mock(BggService::class)->shouldReceive('searchGames')
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
    $this->mock(BggService::class)->shouldReceive('searchGames')
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
    $this->mock(BggService::class)->shouldReceive('searchGames')
        ->once()->with('Heat')->andReturn([]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('games.search-bgg', ['query' => 'Heat']))
        ->assertOk()
        ->assertExactJson(['results' => []]);
});

test('invalid BGG queries are rejected before contacting the service', function () {
    $this->mock(BggService::class)->shouldNotReceive('searchGames');

    $this->actingAs(User::factory()->create())
        ->getJson(route('games.search-bgg', ['query' => 'H']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('query');
});

test('BGG search errors are returned as query validation errors', function () {
    $this->mock(BggService::class)->shouldReceive('searchGames')
        ->once()->with('Heat')->andThrow(new RuntimeException('BGG no disponible.'));

    $this->actingAs(User::factory()->create())
        ->getJson(route('games.search-bgg', ['query' => 'Heat']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['query' => 'BGG no disponible.']);
});

test('BGG searches require authentication', function () {
    $this->mock(BggService::class)->shouldNotReceive('searchGames');

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
