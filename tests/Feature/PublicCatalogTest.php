<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('guests only receive active games and public collections belonging to the catalog owner', function () {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->forceFill(['catalog_share_token' => (string) Str::uuid()])->save();
    $otherUser = User::factory()->create();
    $collections = collect([
        ['created_by' => $owner->id, 'name' => 'A pública', 'is_public' => true],
        ['created_by' => $owner->id, 'name' => 'Z pública', 'is_public' => true],
        ['created_by' => $owner->id, 'name' => 'Privada', 'is_public' => false],
        ['created_by' => $otherUser->id, 'name' => 'Ajena pública', 'is_public' => true],
    ])->map(fn ($attributes) => GameCollection::forceCreate($attributes));
    $games = collect(['Compartido', 'Inactivo', 'Privado', 'Ajeno'])
        ->map(fn ($name) => Game::create([
            'name' => $name,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => $name !== 'Inactivo',
        ]));
    // Shared membership must neither duplicate games nor expose private memberships.
    $games[0]->update([
        'complexity' => 0,
        'full_description' => 'Descripción completa',
        'additional_information' => 'Información adicional',
        'bgg_url' => 'https://boardgamegeek.com/boardgame/1',
        'youtube_url' => 'https://www.youtube.com/watch?v=tutorial',
    ]);
    $games[0]->collections()->attach($collections->pluck('id')->all());
    $games[0]->idealPlayerCounts()->createMany([['player_count' => 3], ['player_count' => 2], ['player_count' => 5]]);
    $games[1]->collections()->attach($collections[0]);
    $games[2]->collections()->attach($collections[2]);
    $games[3]->collections()->attach($collections[3]);

    $this->get(route('catalog.public', $owner->catalog_share_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/index')
            ->where('auth.user', null)
            ->where('collections', $collections->take(2)->map(fn ($collection) => [
                'id' => $collection->id,
                'name' => $collection->name,
            ])->all())
            ->has('games', 1)
            ->where('games.0.id', $games[0]->id)
            ->where('games.0.bgg_url', $games[0]->bgg_url)
            ->where('games.0.youtube_url', $games[0]->youtube_url)
            ->where('games.0.complexity', null)
            ->where('games.0.ideal_player_counts', [2, 3])
            ->has('games.0.collections', 2)
            ->where('games.0.collections.0.id', $collections[0]->id)
            ->where('games.0.collections.1.id', $collections[1]->id)
            ->missing('games.0.collections.0.created_by')
            ->missing('games.0.plays')
            ->missing('games.0.votes')
            ->missing('players')
            ->missing('polls')
            ->missing('plays')
            ->missing('owner')
        );

    $this->get(route('catalog.games.show', [$owner->catalog_share_token, $games[0]->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->where('game.full_description', 'Descripción completa')
            ->where('game.additional_information', 'Información adicional')
            ->where('game.complexity', null)
            ->where('game.ideal_player_counts', [2, 3])
        );
});

test('hiding the last public collection removes its games from the catalog', function () {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->forceFill(['catalog_share_token' => (string) Str::uuid()])->save();
    $collection = GameCollection::forceCreate([
        'created_by' => $owner->id,
        'name' => 'Colección pública',
        'is_public' => true,
    ]);
    $game = Game::create([
        'name' => 'Sky Team',
        'min_players' => 1,
        'max_players' => 2,
        'game_mode' => 'cooperative',
        'complexity' => 2.25,
        'is_active' => true,
    ]);
    $collection->games()->attach($game);
    $url = route('catalog.public', $owner->catalog_share_token);

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('catalog/index')
        ->has('games', 1)
        ->where('games.0.complexity', '2.25')
    );

    $collection->is_public = false;
    $collection->save();

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('catalog/index')
        ->where('collections', [])
        ->where('games', [])
    );
});

test('public catalog keeps games with invalid player ranges but omits their player data', function ($min, $max) {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->forceFill(['catalog_share_token' => (string) Str::uuid()])->save();
    $collection = GameCollection::forceCreate([
        'created_by' => $owner->id,
        'name' => 'Colección pública',
        'is_public' => true,
    ]);
    $game = Game::create(['name' => 'Juego', 'min_players' => $min, 'max_players' => $max, 'is_active' => true]);
    $game->idealPlayerCounts()->create(['player_count' => 2]);
    $collection->games()->attach($game);

    $this->get(route('catalog.public', $owner->catalog_share_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.min_players', null)
            ->where('games.0.max_players', null)
            ->where('games.0.ideal_player_counts', [])
        );

    $this->get(route('catalog.games.show', [$owner->catalog_share_token, $game->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('game.min_players', null)
            ->where('game.max_players', null)
            ->where('game.ideal_player_counts', [])
        );
})->with(['zero' => [0, 1], 'reversed' => [4, 2]]);

test('an unknown catalog token returns not found', function () {
    $this->get(route('catalog.public', (string) Str::uuid()))
        ->assertNotFound();
});


test('el catalogo publico tiene su vista previa de WhatsApp', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $owner->forceFill([
        'catalog_share_token' => (string) Str::uuid(),
    ])->save();

    $this->get(route('catalog.public', $owner->catalog_share_token))
        ->assertOk()
        ->assertSee(
            'property="og:title" content="🎲 Catálogo de juegos · Clover"',
            false
        )
        ->assertSee(
            'property="og:description" content="Explora los juegos de mesa de Clover, consulta sus fichas, filtra por jugadores y encuentra tutoriales."',
            false
        )
        ->assertDontSee(
            'name="robots" content="noindex, nofollow"',
            false
        );
});


test('los enlaces filtrados conservan sus parametros en Open Graph', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $owner->forceFill([
        'catalog_share_token' => (string) Str::uuid(),
    ])->save();

    $baseUrl = route('catalog.public', $owner->catalog_share_token);
    $filteredUrl = $baseUrl . '?view=filtered&search=Ark';

    // Comprobar la ruta y los parámetros sin depender
    // del puerto configurado en el entorno de pruebas.
    $catalogPath = preg_quote(
        (string) parse_url($baseUrl, PHP_URL_PATH),
        '~'
    );

    $filteredResponse = $this->get($filteredUrl)->assertOk();

    $this->assertMatchesRegularExpression(
        '~property="og:url" content="https?://[^"]*'
            . $catalogPath
            . '\?(?:view=filtered&amp;search=Ark|search=Ark&amp;view=filtered)"~',
        $filteredResponse->getContent()
    );

    $normalResponse = $this->get($baseUrl)->assertOk();

    $this->assertMatchesRegularExpression(
        '~property="og:url" content="https?://[^"]*'
            . $catalogPath
            . '"~',
        $normalResponse->getContent()
    );
});

test('la vista previa de la ficha utiliza el resumen y la portada', function ($summary, $expected) {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->forceFill(['catalog_share_token' => (string) Str::uuid()])->save();
    $collection = GameCollection::forceCreate([
        'created_by' => $owner->id,
        'name' => 'Colección pública',
        'is_public' => true,
    ]);
    $game = Game::create([
        'name' => 'Juego de prueba',
        'min_players' => 2,
        'max_players' => 4,
        'short_description' => $summary,
        'image_url' => 'https://example.test/portada.jpg',
        'is_active' => true,
    ]);
    $collection->games()->attach($game);

    $this->get(route('catalog.games.show', [$owner->catalog_share_token, $game->id]))
        ->assertOk()
        ->assertSee('property="og:title" content="🎲 Juego de prueba · Clover"', false)
        ->assertSee('property="og:description" content="'.e($expected).'"', false)
        ->assertSee('property="og:image" content="https://example.test/portada.jpg"', false);
})->with([
    'resumen limpio' => ["  Tu equipo   coopera\n &amp; explora.  ", 'Tu equipo coopera & explora.'],
    'resumen recortado' => [str_repeat('a', 180), str_repeat('a', 160).'...'],
    'sin resumen' => [null, 'Consulta la ficha de Juego de prueba y accede a sus tutoriales.'],
]);
