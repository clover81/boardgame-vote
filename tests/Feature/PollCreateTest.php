<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('poll creation exposes only active games from owned collections and owned game history', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $collection = GameCollection::create(['created_by' => $user->id, 'name' => 'Z principal', 'is_default' => true]);
    $emptyCollection = GameCollection::create(['created_by' => $user->id, 'name' => 'A vacía']);
    $foreignCollection = GameCollection::create(['created_by' => $otherUser->id, 'name' => 'Ajena']);
    $games = collect(['Z compartido', 'A propio', 'Inactivo', 'Ajeno', 'Sin colección'])
        ->map(fn ($name) => Game::create([
            'name' => $name,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => $name !== 'Inactivo',
        ]));
    [$sharedGame, $ownedGame, $inactiveGame, $foreignGame] = $games->all();
    $collection->games()->attach([$sharedGame->id, $ownedGame->id, $inactiveGame->id]);
    $foreignCollection->games()->attach([$sharedGame->id, $foreignGame->id]);
    $sharedGame->idealPlayerCounts()->create(['player_count' => 3]);
    $expansions = $sharedGame->expansions()->createMany([
        ['name' => 'Z expansión', 'is_active' => true],
        ['name' => 'A expansión', 'is_active' => true],
        ['name' => 'Inactiva', 'is_active' => false],
    ]);
    Play::create(['created_by' => $user->id, 'game_id' => $sharedGame->id, 'played_at' => '2026-10-20']);
    Play::create(['created_by' => $otherUser->id, 'game_id' => $sharedGame->id, 'played_at' => '2026-10-21']);
    Player::query()->insert([
        [
            'created_by' => $user->id,
            'name' => 'Z jugador',
            'is_active' => true,
        ],
        [
            'created_by' => $user->id,
            'name' => 'A jugador',
            'is_active' => true,
        ],
        [
            'created_by' => $user->id,
            'name' => 'Inactivo',
            'is_active' => false,
        ],
    ]);

    $this->actingAs($user)->get(route('polls.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/create')
            ->has('collections', 2)
            ->where('collections.0.id', $collection->id)
            ->where('collections.0.games_count', 3)
            ->where('collections.1.id', $emptyCollection->id)
            ->has('games', 2)
            ->where('games.0.id', $ownedGame->id)
            ->where('games.1.id', $sharedGame->id)
            ->where('games.1.collection_ids', [$collection->id])
            ->where('games.1.ideal_player_counts', [3])
            ->where('games.1.expansions', [
                ['id' => $expansions[1]->id, 'name' => 'A expansión'],
                ['id' => $expansions[0]->id, 'name' => 'Z expansión'],
            ])
            ->where('games.1.total_plays', 1)
            ->where('games.1.last_played_at', '2026-10-20')
            ->has('players', 2)
            ->where('players.0.name', 'A jugador')
            ->where('players.1.name', 'Z jugador')
        );
});

test('an account without collections receives no games and can still select its own active players', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $collection = GameCollection::create(['created_by' => User::factory()->create()->id, 'name' => 'Ajena']);
    $game = Game::create([
        'name' => 'Juego ajeno',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);
    $collection->games()->attach($game);
    $player = Player::create([
        'created_by' => $user->id,
        'name' => 'Ana',
        'is_active' => true,
    ]);

    $this->actingAs($user)->get(route('polls.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/create')
            ->has('games', 0)
            ->has('collections', 0)
            ->where('players', [['id' => $player->id, 'name' => 'Ana']])
        );
});
