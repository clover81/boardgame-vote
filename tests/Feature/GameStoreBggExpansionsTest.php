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
    $this->payload = [
        'name' => 'Juego importado',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'ideal_player_counts' => [3, 4],
        'collection_ids' => [$this->collection->id],
        'bgg_id' => 123,
        'bgg_expansion_ids' => [101],
    ];
    $this->expansions = [
        ['bgg_id' => 101, 'name' => 'Expansión elegida', 'bgg_url' => 'https://boardgamegeek.com/boardgame/101'],
        ['bgg_id' => 102, 'name' => 'Expansión sin elegir', 'bgg_url' => 'https://boardgamegeek.com/boardgame/102'],
    ];
});

test('only selected expansions verified by BGG are created for the new game', function () {
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->once()->with(123)->andReturn($this->expansions);

    $this->actingAs($this->user)->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors()->assertRedirect(route('games.index'));

    $game = Game::sole();
    $expansion = $game->expansions()->sole();

    expect($game->bgg_id)->toBe(123);
    expect($game->collections->modelKeys())->toBe([$this->collection->id]);
    expect($game->idealPlayerCounts->pluck('player_count')->all())->toEqualCanonicalizing([3, 4]);
    expect($expansion->name)->toBe('Expansión elegida');
    expect($expansion->bgg_url)->toBe($this->expansions[0]['bgg_url']);
    expect($expansion->is_active)->toBeTrue();
    expect([$expansion->image_url, $expansion->youtube_url, $expansion->notes])->toBe([null, null, null]);
    $this->assertDatabaseCount('expansions', 1);
});

test('an expansion not declared for the BGG game is rejected before saving anything', function ($availableExpansions) {
    $this->payload['bgg_expansion_ids'] = [101, 999];
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->once()->with(123)->andReturn($availableExpansions);

    $this->actingAs($this->user)->post(route('games.store'), $this->payload)
        ->assertSessionHasErrors([
            'bgg_expansion_ids' => 'Una de las expansiones seleccionadas no pertenece a este juego en BoardGameGeek.',
        ]);

    $this->assertDatabaseCount('games', 0);
    $this->assertDatabaseCount('expansions', 0);
    $this->assertDatabaseCount('game_collection_game', 0);
    $this->assertDatabaseCount('game_ideal_player_counts', 0);
})->with([
    'foreign expansion' => [[
        [
            'bgg_id' => 101,
            'name' => 'Válida',
            'bgg_url' => 'https://boardgamegeek.com/boardgame/101',
        ],
    ]],
    'no expansion data' => [[]],
]);

test('a BGG failure is returned as a validation error without creating a game', function () {
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->once()->with(123)->andThrow(new RuntimeException('BGG no disponible.'));

    $this->actingAs($this->user)->post(route('games.store'), $this->payload)
        ->assertSessionHasErrors([
            'bgg_expansion_ids' => 'No se han podido consultar las expansiones en BoardGameGeek.',
        ]);

    $this->assertDatabaseCount('games', 0);
    $this->assertDatabaseCount('expansions', 0);
    $this->assertDatabaseCount('game_collection_game', 0);
});

test('unavailable collections are rejected before requesting BGG expansions or saving records', function ($missing) {
    $collectionId = $missing
        ? 999999
        : GameCollection::create([
            'created_by' => User::factory()->create()->id,
            'name' => 'Colección ajena',
        ])->id;
    $this->payload['collection_ids'][] = (string) $collectionId;
    $this->mock(BggService::class)->shouldNotReceive('expansionsForGame');
    $returnUrl = route('games.create');

    $this->actingAs($this->user)->from($returnUrl)->post(route('games.store'), $this->payload)
        ->assertRedirect($returnUrl)
        ->assertSessionHasErrors(['collection_ids' => 'Alguna colección seleccionada no te pertenece.'])
        ->assertSessionHasInput('collection_ids', $this->payload['collection_ids']);

    foreach (['games', 'expansions', 'game_collection_game', 'game_ideal_player_counts'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with(['foreign collection' => false, 'missing collection' => true]);

test('saving without selected BGG expansions does not contact BGG', function ($selection) {
    $this->payload['bgg_expansion_ids'] = $selection;
    $this->mock(BggService::class)->shouldNotReceive('expansionsForGame');

    $this->actingAs($this->user)->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors()->assertRedirect(route('games.index'));

    $this->assertDatabaseCount('games', 1);
    $this->assertDatabaseCount('expansions', 0);
})->with(['empty' => [[]], 'null' => [null]]);
