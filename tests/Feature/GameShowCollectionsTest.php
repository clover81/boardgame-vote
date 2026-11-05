<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

test('the game page shows only its current users collections in the requested order', function () {
    $user = User::factory()->create();
    $game = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $collections = collect([
        ['name' => 'Zeta', 'is_default' => false],
        ['name' => 'Principal', 'is_default' => true],
        ['name' => 'Alfa', 'is_default' => false],
        ['name' => 'Sin este juego', 'is_default' => false],
    ])->map(fn ($attributes) => GameCollection::create([
        'created_by' => $user->id,
        ...$attributes,
    ]));
    $foreign = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección de Ana',
    ]);
    $game->collections()->attach([
        ...$collections->take(3)->pluck('id')->all(),
        $foreign->id,
    ]);

    $this->actingAs($user)->get(route('games.show', $game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/show')
            ->where('collections', [
                ['id' => $collections[2]->id, 'name' => 'Alfa', 'is_default' => false],
                ['id' => $collections[1]->id, 'name' => 'Principal', 'is_default' => true],
                ['id' => $collections[0]->id, 'name' => 'Zeta', 'is_default' => false],
            ])
            ->missing('game_collection_ids')
        );
});

test('the game page rejects games outside the current users collections', function ($hasForeignCollection) {
    $user = User::factory()->create();
    GameCollection::create(['created_by' => $user->id, 'name' => 'Sin este juego']);
    $game = Game::create([
        'name' => 'Sky Team',
        'min_players' => 2,
        'max_players' => 2,
        'game_mode' => 'cooperative',
    ]);
    if ($hasForeignCollection) {
        $foreign = GameCollection::create([
            'created_by' => User::factory()->create()->id,
            'name' => 'Colección de Ana',
        ]);
        $game->collections()->attach($foreign->id);
    }

    $this->actingAs($user)->get(route('games.show', $game))
        ->assertForbidden();
})->with(['foreign collection' => true, 'no collection' => false]);

test('a shared game page exposes only the current users play history', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $game = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    foreach ([$user, $otherUser] as $owner) {
        $collection = GameCollection::create(['created_by' => $owner->id, 'name' => 'Mi colección']);
        $game->collections()->attach($collection);
    }
    $game->idealPlayerCounts()->create(['player_count' => 3]);
    $zExpansion = $game->expansions()->create(['name' => 'Zeta', 'is_active' => true]);
    $aExpansion = $game->expansions()->create(['name' => 'Alfa', 'is_active' => false]);
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $olderPlay = Play::create([
        'created_by' => $user->id,
        'game_id' => $game->id,
        'played_at' => '2026-10-20',
    ]);
    $latestPlay = Play::create([
        'created_by' => $user->id,
        'game_id' => $game->id,
        'expansion_id' => $zExpansion->id,
        'played_at' => '2026-10-25',
        'notes' => 'Mi partida',
    ]);
    $latestPlay->players()->attach($player);
    Play::create([
        'created_by' => $otherUser->id,
        'game_id' => $game->id,
        'played_at' => '2026-10-29',
        'notes' => 'Partida privada',
    ]);

    $this->actingAs($user)->get(route('games.show', $game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/show')
            ->where('game.ideal_player_counts', [3])
            ->has('game.expansions', 2)
            ->where('game.expansions.0.id', $aExpansion->id)
            ->where('game.expansions.1.id', $zExpansion->id)
            ->where('history.total_plays', 2)
            ->where('history.last_played_at', '2026-10-25')
            ->has('history.plays', 2)
            ->where('history.plays.0.id', $latestPlay->id)
            ->where('history.plays.0.notes', 'Mi partida')
            ->where('history.plays.0.expansion', ['id' => $zExpansion->id, 'name' => 'Zeta'])
            ->where('history.plays.0.players', [['id' => $player->id, 'name' => 'Ana']])
            ->where('history.plays.1.id', $olderPlay->id)
        );

    $this->actingAs($otherUser)->get(route('games.show', $game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('history.total_plays', 1)
            ->where('history.last_played_at', '2026-10-29')
            ->where('history.plays.0.notes', 'Partida privada')
        );
});

test('opening the game page requires authentication', function () {
    $game = Game::create([
        'name' => 'Sky Team',
        'min_players' => 2,
        'max_players' => 2,
        'game_mode' => 'cooperative',
    ]);

    $this->get(route('games.show', $game))->assertRedirect(route('login'));
});
