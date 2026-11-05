<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Mi colección',
        'is_default' => true,
    ]);
    $this->secondCollection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Juegos del grupo',
    ]);
    $this->foreignCollection = GameCollection::create([
        'created_by' => $this->otherUser->id,
        'name' => 'Colección ajena',
    ]);
    $this->makeGame = fn ($name, $min, $max, $active = true) => Game::create([
        'name' => $name,
        'min_players' => $min,
        'max_players' => $max,
        'game_mode' => 'competitive',
        'is_active' => $active,
    ]);
    $this->castle = ($this->makeGame)('The White Castle', 1, 4);
    $this->sky = ($this->makeGame)('Sky Team', 2, 2);
    $this->heat = ($this->makeGame)('Heat', 1, 6);
    $this->foreignGame = ($this->makeGame)('Juego ajeno', 1, 6);
    $this->inactiveGame = ($this->makeGame)('Archivado', 1, 6, false);
    $this->collection->games()->attach([$this->castle->id, $this->sky->id, $this->inactiveGame->id]);
    $this->secondCollection->games()->attach([$this->castle->id, $this->heat->id]);
    $this->foreignCollection->games()->attach([$this->castle->id, $this->foreignGame->id]);
});

test('game summaries count only the signed in users plays and agree with the game history', function () {
    foreach ([
        [$this->user, $this->castle, '2026-10-20'],
        [$this->user, $this->castle, '2026-10-24'],
        [$this->otherUser, $this->castle, '2026-10-29'],
        [$this->otherUser, $this->sky, '2026-10-28'],
    ] as [$user, $game, $date]) {
        Play::create([
            'created_by' => $user->id,
            'game_id' => $game->id,
            'played_at' => $date,
        ]);
    }

    $this->actingAs($this->user)->get(route('games.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/index')
            ->has('games', 3)
            ->where('games.0.id', $this->heat->id)
            ->where('games.0.total_plays', 0)
            ->where('games.0.last_played_at', null)
            ->where('games.1.id', $this->sky->id)
            ->where('games.1.total_plays', 0)
            ->where('games.1.last_played_at', null)
            ->where('games.2.id', $this->castle->id)
            ->where('games.2.total_plays', 2)
            ->where('games.2.last_played_at', '2026-10-24')
            ->where('games.2.collections', [
                ['id' => $this->secondCollection->id, 'name' => 'Juegos del grupo'],
                ['id' => $this->collection->id, 'name' => 'Mi colección'],
            ])
            ->has('collections', 2)
            ->where('selected_collection_id', null)
            ->where('filters', ['q' => '', 'players' => null])
        );

    $this->get(route('games.show', $this->castle))
        ->assertInertia(fn (Assert $page) => $page
            ->where('history.total_plays', 2)
            ->where('history.last_played_at', '2026-10-24')
            ->has('history.plays', 2)
        );
});

test('the collection filter selects only games in that owned collection', function () {
    $this->actingAs($this->user)->get(route('games.index', [
        'collection_id' => $this->secondCollection->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 2)
            ->where('games.0.id', $this->heat->id)
            ->where('games.1.id', $this->castle->id)
            ->where('selected_collection_id', $this->secondCollection->id)
        );
});

test('a foreign collection filter returns no games even if some are shared', function () {
    $this->actingAs($this->user)->get(route('games.index', [
        'collection_id' => $this->foreignCollection->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('games', 0));
});

test('name search trims whitespace and does not search the description', function () {
    $this->sky->update(['short_description' => 'The White Castle']);

    $this->actingAs($this->user)->get(route('games.index', ['q' => '  White Castle  ']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->castle->id)
            ->where('filters.q', 'White Castle')
        );
});

test('player count uses the supported inclusive range rather than ideal player counts', function ($players, $expectedNames) {
    $this->actingAs($this->user)->get(route('games.index', ['players' => $players]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('games', fn ($games) => $games->pluck('name')->all() === $expectedNames)
            ->where('filters.players', $players)
        );
})->with([
    'minimum' => [1, ['Heat', 'The White Castle']],
    'two players' => [2, ['Heat', 'Sky Team', 'The White Castle']],
    'maximum' => [4, ['Heat', 'The White Castle']],
    'larger group' => [6, ['Heat']],
    'no compatible games' => [7, []],
]);

test('collection name and player filters combine', function () {
    $this->actingAs($this->user)->get(route('games.index', [
        'collection_id' => $this->secondCollection->id,
        'q' => 'Castle',
        'players' => 4,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->castle->id)
            ->where('filters', ['q' => 'Castle', 'players' => 4])
        );
});

test('users with no collections see no games', function () {
    $this->actingAs(User::factory()->create())->get(route('games.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 0)
            ->has('collections', 0)
        );
});
