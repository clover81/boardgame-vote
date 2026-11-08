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
        'bgg_url' => 'https://boardgamegeek.com/boardgame/1',
        'youtube_url' => 'https://www.youtube.com/watch?v=tutorial',
    ]);
    $games[0]->collections()->attach($collections->pluck('id')->all());
    $games[0]->idealPlayerCounts()->createMany([['player_count' => 2], ['player_count' => 3]]);
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
        'is_active' => true,
    ]);
    $collection->games()->attach($game);
    $url = route('catalog.public', $owner->catalog_share_token);

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('catalog/index')
        ->has('games', 1)
    );

    $collection->is_public = false;
    $collection->save();

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('catalog/index')
        ->where('collections', [])
        ->where('games', [])
    );
});

test('an unknown catalog token returns not found', function () {
    $this->get(route('catalog.public', (string) Str::uuid()))
        ->assertNotFound();
});
