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
            ->where('filters', ['mode' => '', 'players' => null, 'q' => '', 'sort' => 'name', 'duration' => null, 'difficulty' => ''
            ])
        );

    $this->get(route('games.show', $this->castle))
        ->assertInertia(fn (Assert $page) => $page
            ->where('history.total_plays', 2)
            ->where('history.last_played_at', '2026-10-24')
            ->has('history.plays', 2)
        );
});

test('private collection counters exclude inactive games', function () {
    $this->actingAs($this->user)
        ->get(route('games.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 3)
            ->where('collections.0.id', $this->collection->id)
            ->where('collections.0.games_count', 2)
            ->where('collections.1.id', $this->secondCollection->id)
            ->where('collections.1.games_count', 2)
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

test('search prioritizes titles even when a description contains the same words', function () {
    $this->sky->update(['short_description' => 'The White Castle']);

    $this->actingAs($this->user)->get(route('games.index', ['q' => '  White Castle  ']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->castle->id)
            ->where('filters.q', 'White Castle')
        );
});

test('private search ignores straight and curly apostrophes in titles', function () {
    $this->castle->update(['name' => "King's Dilemma"]);
    $this->sky->update(['name' => 'Queen’s Gambit']);

    foreach (['Kings Dilemma', 'King’s Dilemma'] as $query) {
        $this->actingAs($this->user)
            ->get(route('games.index', ['q' => $query]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('games', 1)
                ->where('games.0.id', $this->castle->id)
            );
    }

    foreach (['Queens Gambit', "Queen's Gambit"] as $query) {
        $this->actingAs($this->user)
            ->get(route('games.index', ['q' => $query]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('games', 1)
                ->where('games.0.id', $this->sky->id)
            );
    }
});

test('private search accepts punctuation and numeric terms', function (string $name, string $query) {
    $this->castle->update(['name' => $name]);

    $this->actingAs($this->user)->get(route('games.index', ['q' => $query]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->castle->id)
            ->where('filters.q', $query)
        );
})->with([
    'comma in the search' => ['Star Wars: Outer Rim', 'outer rim, star'],
    'colon in the title' => ['Star Wars: Outer Rim', 'Star Wars Outer Rim'],
    'zero as search' => ['Project 0', '0'],
]);

test('a single letter prioritizes titles beginning with that letter', function () {
    // The White Castle contiene la letra s pero no empieza por ella.
    $this->actingAs($this->user)->get(route('games.index', ['q' => 's']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->sky->id)
            ->where('filters.q', 's')
        );
});

test('search falls back to the short description when no title matches', function () {
    $this->sky->update(['short_description' => 'Una aventura de espiritus y aviacion']);

    $this->actingAs($this->user)->get(route('games.index', ['q' => 'espiritus']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->sky->id)
        );
});

test('title priority only considers games matching the other selected filters', function () {
    $this->sky->update(['short_description' => 'Viaje por un castillo medieval']);

    // La ficha con "Castle" en el título no coincide con este filtro de colección.
    $this->actingAs($this->user)->get(route('games.index', [
        'collection_id' => $this->secondCollection->id,
        'q' => 'medieval',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('games', 0)
    );

    // En la colección principal solo hay descripción coincidente.
    $this->actingAs($this->user)->get(route('games.index', [
        'collection_id' => $this->collection->id,
        'q' => 'medieval',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('games', 1)
        ->where('games.0.id', $this->sky->id)
    );
});

test('player count shows only games ideal for that exact group size', function ($players, $expectedNames) {
    $this->castle->idealPlayerCounts()->createMany([['player_count' => 3], ['player_count' => 4]]);
    $this->sky->idealPlayerCounts()->create(['player_count' => 2]);
    $this->heat->idealPlayerCounts()->createMany([['player_count' => 3], ['player_count' => 5]]);
    // No incluir juegos de otros usuarios ni archivados, aunque sean ideales para 3.
    $this->foreignGame->idealPlayerCounts()->create(['player_count' => 3]);
    $this->inactiveGame->idealPlayerCounts()->create(['player_count' => 3]);

    $this->actingAs($this->user)->get(route('games.index', ['players' => $players]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('games', fn ($games) => $games->pluck('name')->all() === $expectedNames)
            ->where('filters.players', $players)
        );
})->with([
    'no game ideal for one' => [1, []],
    'two players' => [2, ['Sky Team']],
    'three players' => [3, ['Heat', 'The White Castle']],
    'four players: Heat allows four but it is not ideal' => [4, ['The White Castle']],
    'five players' => [5, ['Heat']],
    'six is allowed by Heat but not ideal' => [6, []],
    'no ideal games' => [7, []],
]);

test('player filter supports ideal groups larger than twelve', function () {
    $this->heat->update(['max_players' => 16]);
    $this->heat->idealPlayerCounts()->create(['player_count' => 15]);

    $this->actingAs($this->user)
        ->get(route('games.index', ['players' => 15]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->heat->id)
            ->where('filters.players', 15)
        );
});

test('collection name and player filters combine', function () {
    $this->castle->idealPlayerCounts()->create(['player_count' => 4]);

    $this->actingAs($this->user)->get(route('games.index', [
        'collection_id' => $this->secondCollection->id,
        'q' => 'Castle',
        'players' => 4,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->castle->id)
            ->where('filters', ['mode' => '', 'players' => 4, 'q' => 'Castle', 'sort' => 'name', 'duration' => null, 'difficulty' => ''
            ])
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

test('games can be sorted by name descending', function () {
    $this->actingAs($this->user)->get(route('games.index', ['sort' => 'name_desc']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('games', fn ($games) => $games->pluck('name')->all() === [
                'The White Castle', 'Sky Team', 'Heat',
            ])
            ->where('filters.sort', 'name_desc')
        );
});

test('recently played sorting uses the signed in users history and puts unplayed games last', function () {

    // La ordenación se prueba con juegos ideales para dos jugadores.
    foreach ([$this->castle, $this->sky, $this->heat] as $game) {
        $game->idealPlayerCounts()->firstOrCreate(['player_count' => 2]);
    }
    foreach ([
        [$this->user, $this->castle, '2026-10-02'],
        [$this->user, $this->heat, '2026-10-20'],
        [$this->otherUser, $this->sky, '2026-11-01'],
    ] as [$user, $game, $date]) {
        Play::create([
            'created_by' => $user->id,
            'game_id' => $game->id,
            'played_at' => $date,
        ]);
    }

    $this->actingAs($this->user)->get(route('games.index', [
        'sort' => 'recent',
        'players' => 2,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('games', fn ($games) => $games->pluck('name')->all() === [
                'Heat', 'The White Castle', 'Sky Team',
            ])
            ->where('filters.sort', 'recent')
            ->where('filters.players', 2)
        );
});

test('most played sorting counts only the signed in users plays', function () {
    foreach ([
        [$this->user, $this->castle],
        [$this->user, $this->castle],
        [$this->user, $this->heat],
        [$this->otherUser, $this->sky],
        [$this->otherUser, $this->sky],
        [$this->otherUser, $this->sky],
    ] as [$user, $game]) {
        Play::create([
            'created_by' => $user->id,
            'game_id' => $game->id,
            'played_at' => '2026-10-20',
        ]);
    }

    $this->actingAs($this->user)->get(route('games.index', ['sort' => 'most_played']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('games', fn ($games) => $games->pluck('name')->all() === [
                'The White Castle', 'Heat', 'Sky Team',
            ])
            ->where('filters.sort', 'most_played')
        );
});

test('invalid sorting options fall back to alphabetical ordering', function () {
    $this->actingAs($this->user)->get(route('games.index', ['sort' => 'invalid']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('games', fn ($games) => $games->pluck('name')->all() === [
                'Heat', 'Sky Team', 'The White Castle',
            ])
            ->where('filters.sort', 'name')
        );
});

test('private catalog filters time and difficulty with the same ranges as public catalog', function () {
    $this->castle->update(['min_playtime' => 30, 'max_playtime' => 45, 'complexity' => 1.8]);
    $this->sky->update(['min_playtime' => 35, 'max_playtime' => 60, 'complexity' => 2.7]);
    $this->heat->update(['min_playtime' => 90, 'max_playtime' => 120, 'complexity' => 3.6]);

    foreach ([
        ['60', 'light', 'The White Castle'],
        ['60', 'medium', 'Sky Team'],
        ['180', 'heavy', 'Heat'],
    ] as [$duration, $difficulty, $expected]) {
        $this->actingAs($this->user)->get(route('games.index', [
            'duration' => $duration,
            'difficulty' => $difficulty,
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.name', $expected)
        );
    }
});

test('private mode filter supports solo and unclassified games', function () {
    $this->castle->update([
        'max_players' => 1,
        'game_mode' => null,
        'game_modes' => [],
    ]);

    $this->sky->update([
        'game_mode' => null,
        'game_modes' => [],
    ]);

    $this->heat->update([
        'game_mode' => 'competitive',
        'game_modes' => ['competitive'],
    ]);

    $this->actingAs($this->user)
        ->get(route('games.index', ['mode' => 'solo']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->castle->id)
            ->where('filters.mode', 'solo'));

    $this->get(route('games.index', ['mode' => 'unclassified']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->sky->id)
            ->where('filters.mode', 'unclassified'));
});

test('non-text filter values use the default collection filters', function () {
    $this->actingAs($this->user)->get(route('games.index', [
        'q' => ['Heat'],
        'difficulty' => ['light'],
        'mode' => ['competitive'],
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/index')
            ->has('games', 3)
            ->where('filters.q', '')
            ->where('filters.difficulty', '')
            ->where('filters.mode', '')
        );
});

test('mode filter supports legacy classifications without overriding current modes', function () {
    $this->castle->update([
        'game_mode' => 'competitive',
        'game_modes' => null,
    ]);

    $this->sky->update([
        'game_mode' => 'competitive',
        'game_modes' => ['cooperative'],
    ]);

    $this->heat->update([
        'game_mode' => 'competitive',
        'game_modes' => [],
    ]);

    $this->actingAs($this->user)
        ->get(route('games.index', ['mode' => 'competitive']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 2)
            ->where('games.0.id', $this->heat->id)
            ->where('games.1.id', $this->castle->id)
        );

    $this->get(route('games.index', ['mode' => 'cooperative']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->sky->id)
        );
});


test('empty mode lists respect legacy classifications in solo and unclassified filters', function () {
    $this->castle->update([
        'max_players' => 1,
        'game_mode' => 'competitive',
        'game_modes' => [],
    ]);

    $this->sky->update([
        'game_mode' => 'cooperative',
        'game_modes' => [],
    ]);

    $this->heat->update([
        'game_mode' => null,
        'game_modes' => [],
    ]);

    $this->actingAs($this->user)
        ->get(route('games.index', ['mode' => 'solo']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 0));

    $this->get(route('games.index', ['mode' => 'unclassified']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.id', $this->heat->id));
});
