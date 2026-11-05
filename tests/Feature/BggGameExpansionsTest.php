<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use App\Services\BggService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Mi colección',
    ]);
    $this->game = Game::create([
        'name' => 'The White Castle',
        'bgg_id' => 371942,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $this->game->collections()->attach($this->collection);
    $this->url = route('games.bgg.expansions', $this->game);
});

test('BGG expansions identify saved IDs for this game without changing the catalog', function () {
    $saved = $this->game->expansions()->create([
        'bgg_id' => '123',
        'name' => 'Matcha guardada',
        'is_active' => false,
    ]);
    $manual = $this->game->expansions()->create(['name' => 'Expansión manual']);
    $otherGame = Game::create([
        'name' => 'Heat',
        'min_players' => 1,
        'max_players' => 6,
        'game_mode' => 'competitive',
    ]);
    $otherGame->expansions()->create(['bgg_id' => 789, 'name' => 'Expansión de otro juego']);
    $expansions = [
        ['bgg_id' => 123, 'name' => 'Matcha', 'image_url' => null],
        ['bgg_id' => 456, 'name' => 'Expansión manual', 'image_url' => null],
        ['bgg_id' => 789, 'name' => 'Otra expansión', 'image_url' => null],
    ];
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->once()->with(371942)->andReturn($expansions);

    $this->actingAs($this->user)->getJson($this->url)
        ->assertOk()
        ->assertExactJson(['expansions' => [
            [...$expansions[0], 'already_imported' => true],
            [...$expansions[1], 'already_imported' => false],
            [...$expansions[2], 'already_imported' => false],
        ]]);

    $this->assertDatabaseCount('expansions', 3);
    expect($saved->fresh()->name)->toBe('Matcha guardada')
        ->and($saved->fresh()->is_active)->toBeFalse()
        ->and($manual->fresh()->bgg_id)->toBeNull();
});

test('expansions cannot be queried for a game outside the users collections', function ($foreign) {
    $this->game->collections()->detach();

    if ($foreign) {
        $collection = GameCollection::create([
            'created_by' => User::factory()->create()->id,
            'name' => 'Colección ajena',
        ]);
        $this->game->collections()->attach($collection);
    }

    $this->mock(BggService::class)->shouldNotReceive('expansionsForGame');

    $this->actingAs($this->user)->getJson($this->url)->assertForbidden();
})->with(['unowned game' => [false], 'foreign collection' => [true]]);

test('an owned game without a BGG ID returns an explanatory validation response', function () {
    $this->game->update(['bgg_id' => null]);
    $this->mock(BggService::class)->shouldNotReceive('expansionsForGame');

    $this->actingAs($this->user)->getJson($this->url)
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'El juego no está vinculado a BGG.',
        ]);
});

test('an owner can query expansions when the game is also shared with another user', function () {
    $collection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección compartida',
    ]);
    $this->game->collections()->attach($collection);
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->once()->with(371942)->andReturn([]);

    $this->actingAs($this->user)->getJson($this->url)
        ->assertOk()
        ->assertExactJson(['expansions' => []]);
});

test('BGG expansion lookup failures return a 502 response', function () {
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->once()->with(371942)->andThrow(new RuntimeException('BGG no disponible.'));

    $this->actingAs($this->user)->getJson($this->url)
        ->assertStatus(502)
        ->assertExactJson([
            'message' => 'No se han podido consultar las expansiones en BoardGameGeek.',
        ]);
});

test('BGG expansion lookups require authentication', function () {
    $this->mock(BggService::class)->shouldNotReceive('expansionsForGame');

    $this->getJson($this->url)->assertUnauthorized();
});

test('a nonexistent game returns not found without querying BGG', function () {
    $this->mock(BggService::class)->shouldNotReceive('expansionsForGame');

    $this->actingAs($this->user)
        ->getJson(route('games.bgg.expansions', $this->game->id + 1000))
        ->assertNotFound();
});
