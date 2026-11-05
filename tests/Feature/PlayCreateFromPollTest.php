<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->game = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);
    $this->collection = GameCollection::create(['created_by' => $this->user->id, 'name' => 'Colección propia']);
    $this->collection->games()->attach($this->game);
    $this->poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Partida del domingo',
        'token' => 'POLLTEST',
        'status' => 'closed',
    ]);
    $this->createRegisteredPlay = fn () => Play::create([
        'created_by' => $this->user->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-10-25',
    ]);
});

test('an unregistered closed poll opens the special form with its ID and existing defaults', function () {
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $expansion = $this->game->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $this->poll->participants()->create(['player_id' => $player->id, 'display_name' => 'Ana']);
    $this->poll->candidates()->create(['game_id' => $this->game->id, 'expansion_id' => $expansion->id]);

    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/create')
            ->where('poll.id', $this->poll->id)
            ->where('poll.name', 'Partida del domingo')
            ->where('players', [['id' => $player->id, 'name' => 'Ana']])
            ->where('games.0.id', $this->game->id)
            ->where('games.0.planned_expansion_id', $expansion->id)
            ->where('candidates.0.game.id', $this->game->id)
            ->where('candidates.0.game.name', $this->game->name)
            ->where('candidates.0.planned_expansion_id', $expansion->id)
            ->where('defaults', [
                'game_ids' => [$this->game->id],
                'expansion_ids' => [$this->game->id => $expansion->id],
                'player_ids' => [$player->id],
                'played_at' => now()->toDateString(),
            ])
        );
});

test('the form includes other active games from owned collections without duplicating candidates or shared games', function () {
    $collections = collect(['Mi colección', 'Juegos del grupo'])->map(fn ($name) => GameCollection::create([
        'created_by' => $this->user->id,
        'name' => $name,
    ]));
    $foreignCollection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección ajena',
    ]);
    $createGame = fn ($name, $active = true) => Game::create([
        'name' => $name,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => $active,
    ]);
    $otherGame = $createGame('Heat');
    $inactiveGame = $createGame('Archivado', false);
    $foreignGame = $createGame('Juego ajeno');
    $createGame('Sin colección');

    $this->game->collections()->attach($collections->pluck('id'));
    $otherGame->collections()->attach($collections->pluck('id'));
    $inactiveGame->collections()->attach($collections->first()->id);
    $foreignGame->collections()->attach($foreignCollection);
    $expansion = $otherGame->expansions()->create(['name' => 'Expansión activa', 'is_active' => true]);
    $otherGame->expansions()->create(['name' => 'Expansión archivada', 'is_active' => false]);
    $candidate = $this->poll->candidates()->create(['game_id' => $this->game->id]);

    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidates', [[
                'id' => $candidate->id,
                'game' => ['id' => $this->game->id, 'name' => $this->game->name],
                'planned_expansion_id' => null,
            ]])
            ->has('games', 2)
            ->where('games.0.id', $otherGame->id)
            ->where('games.1.id', $this->game->id)
            ->where('games.0.planned_expansion_id', null)
            ->where('games.0.expansions', [['id' => $expansion->id, 'name' => $expansion->name]])
            ->where('defaults.game_ids', [$this->game->id])
        );
});

test('an unavailable historical winner cannot be selected or preselected', function ($kind) {
    if ($kind === 'inactive') {
        $this->game->update(['is_active' => false]);
    } else {
        $this->game->collections()->detach();
        if ($kind === 'foreign') {
            $collection = GameCollection::create([
                'created_by' => User::factory()->create()->id,
                'name' => 'Colección ajena',
            ]);
            $this->game->collections()->attach($collection);
        }
    }
    $expansion = $this->game->expansions()->create(['name' => 'Prevista', 'is_active' => true]);
    $this->poll->candidates()->create(['game_id' => $this->game->id, 'expansion_id' => $expansion->id]);

    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 0)
            ->where('candidates.0.game.id', $this->game->id)
            ->where('poll.has_clear_winner', true)
            ->where('defaults.game_ids', [])
            ->where('defaults.expansion_ids', [])
        );
})->with(['inactive', 'uncollected', 'foreign']);

test('a poll with a registered play redirects to the poll without opening the form', function () {
    ($this->createRegisteredPlay)();

    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertRedirect(route('polls.show', $this->poll))
        ->assertSessionHasErrors([
            'play' => 'Las partidas de esta votación ya han sido registradas.',
        ]);

    $this->assertDatabaseCount('plays', 1);
});

test('only an unambiguously winning candidate is preselected', function ($votes, $hasWinner) {
    $otherGame = Game::create([
        'name' => 'Sky Team',
        'min_players' => 2,
        'max_players' => 2,
        'game_mode' => 'cooperative',
    ]);
    $this->collection->games()->attach($otherGame);
    $expansion = $otherGame->expansions()->create(['name' => 'Expansión prevista', 'is_active' => true]);
    $candidates = [
        'a' => $this->poll->candidates()->create(['game_id' => $this->game->id]),
        'b' => $this->poll->candidates()->create(['game_id' => $otherGame->id, 'expansion_id' => $expansion->id]),
    ];

    foreach ($votes as $index => [$candidate, $position]) {
        $participant = $this->poll->participants()->create(['display_name' => 'Votante '.$index]);
        $participant->votes()->create([
            'poll_id' => $this->poll->id,
            'poll_candidate_id' => $candidates[$candidate]->id,
            'position' => $position,
        ]);
    }

    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/create')
            ->where('poll.has_clear_winner', $hasWinner)
            ->where('defaults.game_ids', $hasWinner ? [$otherGame->id] : [])
            ->where('defaults.expansion_ids', $hasWinner ? [$otherGame->id => $expansion->id] : [])
            ->where('games.0.planned_expansion_id', $expansion->id)
        );
})->with([
    'more points' => [[['a', 2], ['b', 1]], true],
    'first votes break a points tie' => [[['a', 2], ['a', 2], ['b', 1]], true],
    'tied points and first votes' => [[['a', 1], ['b', 1]], false],
    'no votes' => [[], false],
]);

test('a poll without candidates has no game preselected', function () {
    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/create')
            ->where('defaults.game_ids', [])
            ->where('defaults.expansion_ids', [])
            ->has('games', 1)
            ->where('games.0.id', $this->game->id)
        );
});

test('default players include linked poll participants and exclude deleted or unlinked players', function () {
    $ana = Player::create(['name' => 'Ana', 'is_active' => true]);
    $bea = Player::create(['name' => 'Bea', 'is_active' => false]);
    $this->poll->participants()->create(['player_id' => $ana->id, 'display_name' => 'Ana']);
    $this->poll->participants()->create(['player_id' => null, 'display_name' => 'Jugador eliminado']);
    $this->poll->participants()->create(['player_id' => $bea->id, 'display_name' => 'Bea']);

    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/create')
            ->where('defaults.player_ids', [$ana->id, $bea->id])
            ->where('players', [
                ['id' => $ana->id, 'name' => 'Ana'],
                ['id' => $bea->id, 'name' => 'Bea'],
            ])
        );
});

test('an inactive planned expansion is not preselected for the winning game', function () {
    $expansion = $this->game->expansions()->create(['name' => 'Inactiva', 'is_active' => false]);
    $this->poll->candidates()->create(['game_id' => $this->game->id, 'expansion_id' => $expansion->id]);

    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/create')
            ->where('defaults.game_ids', [$this->game->id])
            ->where('defaults.expansion_ids', [])
            ->where('games.0.planned_expansion_id', null)
            ->where('games.0.expansions', [])
        );
});

test('another users poll cannot open the form or redirect to an existing play', function () {
    ($this->createRegisteredPlay)();
    $this->poll->update(['created_by' => User::factory()->create()->id]);

    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertForbidden();
});

test('nonclosed polls are rejected before checking for a registered play', function ($status) {
    ($this->createRegisteredPlay)();
    $this->poll->update(['status' => $status]);

    $this->actingAs($this->user)->get(route('plays.create-from-poll', $this->poll))
        ->assertNotFound();
})->with(['draft', 'open']);

test('manual play creation renders the manual form without a poll', function () {
    $collection = GameCollection::create(['created_by' => $this->user->id, 'name' => 'Mi colección']);
    $collection->games()->attach($this->game);

    $this->actingAs($this->user)->get(route('plays.create', ['game_id' => $this->game->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/create-manual')
            ->where('selected_game_id', $this->game->id)
            ->where('games.0.id', $this->game->id)
            ->missing('poll')
        );
});

test('manual play games are the unique active union of owned collections with only active expansions', function () {
    $collections = collect(['Mi colección', 'Del grupo'])->map(fn ($name) => GameCollection::create([
        'created_by' => $this->user->id,
        'name' => $name,
    ]));
    $foreignCollection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección ajena',
    ]);
    $createGame = fn ($name, $active = true) => Game::create([
        'name' => $name,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => $active,
    ]);
    $otherGame = $createGame('Heat');
    $inactiveGame = $createGame('Archivado', false);
    $foreignGame = $createGame('Juego ajeno');
    $createGame('Sin colección');
    $this->game->collections()->attach($collections->pluck('id'));
    $otherGame->collections()->attach([$collections->last()->id, $foreignCollection->id]);
    $inactiveGame->collections()->attach($collections->first()->id);
    $foreignGame->collections()->attach($foreignCollection);
    $zExpansion = $this->game->expansions()->create(['name' => 'Zeta', 'is_active' => true]);
    $aExpansion = $this->game->expansions()->create(['name' => 'Alfa', 'is_active' => true]);
    $this->game->expansions()->create(['name' => 'Inactiva', 'is_active' => false]);

    $this->actingAs($this->user)->get(route('plays.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/create-manual')
            ->has('games', 2)
            ->where('games.0.id', $otherGame->id)
            ->where('games.1.id', $this->game->id)
            ->where('games.1.expansions', [
                ['id' => $aExpansion->id, 'name' => 'Alfa'],
                ['id' => $zExpansion->id, 'name' => 'Zeta'],
            ])
            ->where('selected_game_id', null)
        );
});

test('manual play creation ignores preselection of unavailable games', function ($kind) {
    $this->game->collections()->detach();
    if ($kind === 'foreign') {
        $collection = GameCollection::create([
            'created_by' => User::factory()->create()->id,
            'name' => 'Colección ajena',
        ]);
        $collection->games()->attach($this->game);
    } elseif ($kind === 'inactive') {
        $collection = GameCollection::create(['created_by' => $this->user->id, 'name' => 'Mi colección']);
        $collection->games()->attach($this->game);
        $this->game->update(['is_active' => false]);
    }

    $gameId = $kind === 'nonexistent' ? $this->game->id + 1 : $this->game->id;

    $this->actingAs($this->user)->get(route('plays.create', ['game_id' => $gameId]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/create-manual')
            ->where('games', [])
            ->where('selected_game_id', null)
        );
})->with(['foreign', 'inactive', 'uncollected', 'nonexistent']);

test('opening the poll registration form requires authentication', function () {
    $this->get(route('plays.create-from-poll', $this->poll))
        ->assertRedirect(route('login'));
});
