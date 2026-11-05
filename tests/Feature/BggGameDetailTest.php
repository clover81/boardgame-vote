<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use App\Services\BggService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->bggGame = [
        'bgg_id' => 371942,
        'name' => 'The White Castle',
        'image_url' => null,
        'bgg_url' => 'https://boardgamegeek.com/boardgame/371942',
    ];
});

test('BGG detail matches a manual title only in the current users collections', function ($kind, $matches) {
    $game = Game::create([
        'name' => $kind === 'different-name' ? 'White Castle' : 'The White Castle',
        'bgg_id' => $kind === 'different-id' ? 999 : null,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);

    if ($kind !== 'unassigned') {
        $collection = GameCollection::create([
            'created_by' => $kind === 'foreign' ? User::factory()->create()->id : $this->user->id,
            'name' => 'Colección',
        ]);
        $game->collections()->attach($collection);
    }

    $before = $game->fresh()->load('collections')->toArray();
    $this->mock(BggService::class)->shouldReceive('game')
        ->once()->with(371942)->andReturn($this->bggGame);

    $this->actingAs($this->user)->getJson(route('games.bgg.show', 371942))
        ->assertOk()
        ->assertExactJson([
            'game' => $this->bggGame,
            'existing_game' => null,
            'legacy_game' => $matches ? ['id' => $game->id, 'name' => $game->name] : null,
        ]);

    expect($game->fresh()->load('collections')->toArray())->toBe($before);
})->with([
    'owned manual game' => ['owned', true],
    'another users manual game' => ['foreign', false],
    'manual game without collections' => ['unassigned', false],
    'similar name is not an exact match' => ['different-name', false],
    'same name with a different BGG ID' => ['different-id', false],
]);

test('a BGG ID match takes priority over an owned manual game with the same title', function () {
    $manualGame = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Mi colección',
    ]);
    $manualGame->collections()->attach($collection);
    $linkedGame = Game::create([
        'name' => 'Título local de la ficha BGG',
        'bgg_id' => 371942,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $this->mock(BggService::class)->shouldReceive('game')
        ->once()->with(371942)->andReturn($this->bggGame);

    $this->actingAs($this->user)->getJson(route('games.bgg.show', 371942))
        ->assertOk()
        ->assertExactJson([
            'game' => $this->bggGame,
            'existing_game' => ['id' => $linkedGame->id, 'name' => $linkedGame->name],
            'legacy_game' => null,
        ]);

    expect($manualGame->fresh()->bgg_id)->toBeNull();
});

test('BGG detail service errors keep the existing JSON error response', function () {
    $this->mock(BggService::class)->shouldReceive('game')
        ->once()->with(371942)->andThrow(new RuntimeException('BGG no disponible.'));

    $this->actingAs($this->user)->getJson(route('games.bgg.show', 371942))
        ->assertStatus(502)
        ->assertExactJson([
            'message' => 'No se han podido obtener los datos del juego desde BoardGameGeek.',
        ]);
});

test('BGG detail requires authentication', function () {
    $this->mock(BggService::class)->shouldNotReceive('game');

    $this->getJson(route('games.bgg.show', 371942))->assertUnauthorized();
});
