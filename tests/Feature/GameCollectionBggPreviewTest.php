<?php

use App\Models\GameCollection;
use App\Models\User;
use App\Services\BggCollectionService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Mi colección',
    ]);
    $this->url = route('collections.bgg.preview', $this->collection);
    config(['services.bgg.collection_username' => '  clover81  ']);
});

test('the owner can preview the configured BGG collection without importing records', function () {
    $preview = [
        'summary' => [
            'games' => 1, 'games_existing' => 0, 'games_new' => 1,
            'expansions' => 1, 'expansions_existing' => 0, 'expansions_new' => 1,
        ],
        'games' => [['bgg_id' => 100, 'name' => 'Juego nuevo', 'status' => 'new']],
        'expansions' => [['bgg_id' => 200, 'name' => 'Expansión nueva', 'status' => 'new']],
    ];
    $before = $this->collection->fresh()->getAttributes();
    $this->mock(BggCollectionService::class)->shouldReceive('preview')
        ->once()->with('clover81')->andReturn($preview);

    $this->actingAs($this->user)->getJson($this->url)
        ->assertOk()
        ->assertExactJson([
            'collection' => ['id' => $this->collection->id, 'name' => 'Mi colección'],
            'username' => 'clover81',
            ...$preview,
        ]);

    $this->assertDatabaseCount('games', 0);
    $this->assertDatabaseCount('expansions', 0);
    expect($this->collection->fresh()->getAttributes())->toBe($before);
});

test('guests cannot request a BGG collection preview', function () {
    $this->mock(BggCollectionService::class)->shouldNotReceive('preview');

    $this->getJson($this->url)->assertUnauthorized();
});

test('another account cannot request a BGG collection preview', function () {
    $this->mock(BggCollectionService::class)->shouldNotReceive('preview');

    $this->actingAs(User::factory()->create())->getJson($this->url)
        ->assertForbidden();
});

test('a missing collection returns not found without requesting a BGG preview', function () {
    $this->mock(BggCollectionService::class)->shouldNotReceive('preview');

    $this->actingAs($this->user)
        ->getJson(route('collections.bgg.preview', $this->collection->id + 1000))
        ->assertNotFound();
});

test('a missing configured BGG username returns an explanatory validation response', function ($username) {
    config(['services.bgg.collection_username' => $username]);
    $this->mock(BggCollectionService::class)->shouldNotReceive('preview');

    $this->actingAs($this->user)->getJson($this->url)
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Configura el usuario de BGG.']);
})->with(['not configured' => [null], 'empty' => [''], 'whitespace' => ['   ']]);

test('BGG preview failures return a service unavailable response', function ($message) {
    $this->mock(BggCollectionService::class)->shouldReceive('preview')
        ->once()->with('clover81')->andThrow(new RuntimeException($message));

    $this->actingAs($this->user)->getJson($this->url)
        ->assertServiceUnavailable()
        ->assertExactJson(['message' => $message]);

    $this->assertDatabaseCount('games', 0);
    $this->assertDatabaseCount('expansions', 0);
})->with([
    'collection pending' => ['BGG está preparando la colección. Repite la consulta más tarde.'],
    'BGG error' => ['BGG ha respondido con HTTP 500'],
]);
