<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use App\Services\BggService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Mi colección',
    ]);
    $this->payload = [
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'game_modes' => ['competitive'],
        'ideal_player_counts' => [3, 4],
        'collection_ids' => [$this->collection->id],
        'bgg_url' => 'https://boardgamegeek.com/boardgame/371942/the-white-castle',
    ];
    $this->mock(BggService::class)->shouldNotReceive('getGame');
});

test('an existing BGG game is attached without changing any game data or history', function ($input) {
    $otherCollection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección de otro usuario',
    ]);
    $existingGame = Game::create([
        'name' => 'Juego existente',
        'bgg_id' => 371942,
        'min_players' => 2,
        'max_players' => 2,
        'game_mode' => 'cooperative',
        'short_description' => 'Mi descripción',
        'additional_information' => 'Mis notas',
        'youtube_url' => 'https://example.com/mi-tutorial',
    ]);
    $existingGame->collections()->attach($otherCollection);
    $existingGame->idealPlayerCounts()->create(['player_count' => 3]);
    $expansion = $existingGame->expansions()->create(['name' => 'Mi expansión']);
    $play = Play::create([
        'created_by' => $this->user->id,
        'game_id' => $existingGame->id,
        'expansion_id' => $expansion->id,
        'played_at' => '2026-10-28',
        'notes' => 'Partida histórica',
    ]);
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $play->players()->attach($player);
    $poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Votación histórica',
        'token' => 'BGGHISTORY',
        'status' => 'closed',
    ]);
    $candidate = $poll->candidates()->create(['game_id' => $existingGame->id]);
    $gameData = $existingGame->fresh()->getAttributes();
    $history = $existingGame->fresh()->load('idealPlayerCounts', 'expansions', 'plays.players')
        ->only(['idealPlayerCounts', 'expansions', 'plays']);
    $this->payload = array_replace($this->payload, [
        'image_url' => 'https://example.com/bgg-image.jpg',
        'min_playtime' => 60,
        'max_playtime' => 90,
        'complexity' => 2.5,
        'short_description' => 'No sustituir descripción',
        'additional_information' => 'No sustituir notas',
        'youtube_url' => 'https://example.com/otro-tutorial',
    ]);

    if ($input === 'id') {
        $this->payload['bgg_id'] = 371942;
    } elseif ($input === 'null') {
        $this->payload['bgg_id'] = null;
    }

    foreach ([1, 2] as $attempt) {
        $this->actingAs($this->user)->post(route('games.store'), $this->payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('games.show', $existingGame))
            ->assertSessionHas('success', 'El juego ya estaba importado desde BGG y se ha añadido a tu colección.');
    }

    $this->assertDatabaseCount('games', 1);
    $this->assertDatabaseCount('game_collection_game', 2);
    $this->assertDatabaseCount('game_ideal_player_counts', 1);
    $existingGame->refresh();
    expect($existingGame->getAttributes())->toBe($gameData)
        ->and($existingGame->load('idealPlayerCounts', 'expansions', 'plays.players')
            ->only(['idealPlayerCounts', 'expansions', 'plays']))->toEqual($history);
    $this->assertDatabaseHas('poll_candidates', ['id' => $candidate->id, 'game_id' => $existingGame->id]);
    expect($existingGame->collections->modelKeys())->toEqualCanonicalizing([
        $this->collection->id,
        $otherCollection->id,
    ]);
    expect($existingGame->idealPlayerCounts->pluck('player_count')->all())->toBe([3]);
})->with(['id', 'omitted', 'null']);

test('a new game stores the supplied BGG ID or extracts it from its URL', function ($input) {
    if ($input === 'id') {
        $this->payload['bgg_id'] = 371942;
        unset($this->payload['bgg_url']);
    } elseif ($input === 'null') {
        $this->payload['bgg_id'] = null;
    }

    $this->actingAs($this->user)->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors()->assertRedirect(route('games.index'));

    $game = Game::sole();
    expect($game->bgg_id)->toBe(371942);
    expect($game->bgg_url)->toBe($this->payload['bgg_url'] ?? null);
})->with(['id', 'omitted', 'null']);

test('a matched game is not updated or attached when a selected collection belongs to another user', function ($input) {
    $otherCollection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección ajena',
    ]);
    $existingGame = Game::create([
        'name' => $input === 'manual' ? 'The White Castle' : 'Juego existente',
        'bgg_id' => $input === 'manual' ? null : 371942,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $existingGame->collections()->attach($otherCollection);

    if ($input === 'manual') {
        $existingGame->collections()->attach($this->collection);
    }

    $before = $existingGame->fresh()->load('collections')->toArray();
    $this->payload['collection_ids'][] = $otherCollection->id;

    if ($input === 'id' || $input === 'manual') {
        $this->payload['bgg_id'] = 371942;
    }

    $returnUrl = route('games.create');
    $this->actingAs($this->user)->from($returnUrl)->post(route('games.store'), $this->payload)
        ->assertRedirect($returnUrl)
        ->assertSessionHasErrors([
            'collection_ids' => 'Alguna colección seleccionada no te pertenece.',
        ])
        ->assertSessionHasInput('collection_ids', $this->payload['collection_ids']);

    $this->assertDatabaseCount('games', 1);
    $this->assertDatabaseCount('game_collection_game', $input === 'manual' ? 2 : 1);
    expect($existingGame->fresh()->load('collections')->toArray())->toBe($before);
})->with(['id', 'url', 'manual']);

test('name matching reuses only an owned manual game with no BGG ID', function ($kind, $input, $reuse) {
    $manualGame = Game::create([
        'name' => $kind === 'different-name' ? 'White Castle' : 'The White Castle',
        'bgg_id' => $kind === 'different-id' ? 999 : null,
        'min_players' => 2,
        'max_players' => 2,
        'game_mode' => 'cooperative',
        'short_description' => 'Mi descripción anterior',
    ]);
    $manualGame->idealPlayerCounts()->create(['player_count' => 2]);
    $previousCollection = null;

    if ($kind !== 'unassigned') {
        $previousCollection = GameCollection::create([
            'created_by' => $kind === 'foreign' ? User::factory()->create()->id : $this->user->id,
            'name' => 'Colección anterior',
        ]);
        $manualGame->collections()->attach($previousCollection);
    }

    $before = $manualGame->fresh()->load('collections', 'idealPlayerCounts')->toArray();

    if ($input === 'id') {
        $this->payload['bgg_id'] = 371942;
    }

    $response = $this->actingAs($this->user)->post(route('games.store'), $this->payload);
    $response->assertSessionHasNoErrors();

    if ($reuse) {
        $response->assertRedirect(route('games.show', $manualGame))
            ->assertSessionHas('success', 'El juego que ya tenías se ha vinculado con BoardGameGeek.');
        $this->assertDatabaseCount('games', 1);
        $manualGame->refresh();

        expect($manualGame->bgg_id)->toBe(371942)
            ->and($manualGame->min_players)->toBe(2)
            ->and($manualGame->max_players)->toBe(2)
            ->and($manualGame->game_mode)->toBe('cooperative')
            ->and($manualGame->short_description)->toBe('Mi descripción anterior')
            ->and($manualGame->idealPlayerCounts->pluck('player_count')->all())->toBe([2])
            ->and($manualGame->collections->modelKeys())->toEqualCanonicalizing([
                $previousCollection->id,
                $this->collection->id,
            ]);
    } else {
        $response->assertRedirect(route('games.index'));
        $this->assertDatabaseCount('games', 2);
        expect($manualGame->fresh()->load('collections', 'idealPlayerCounts')->toArray())->toBe($before);
        $newGame = Game::where('bgg_id', 371942)->sole();
        expect($newGame->id)->not->toBe($manualGame->id)
            ->and($newGame->collections->modelKeys())->toBe([$this->collection->id]);
    }
})->with([
    'owned manual game with explicit ID' => ['owned', 'id', true],
    'owned manual game with ID from URL' => ['owned', 'url', true],
    'another users manual game' => ['foreign', 'id', false],
    'manual game without collections' => ['unassigned', 'id', false],
    'same name with another BGG ID' => ['different-id', 'id', false],
    'similar name' => ['different-name', 'id', false],
]);

test('linking an owned manual game fills only gaps and preserves its decisions and complete history', function ($state) {
    $missing = in_array($state, ['null', 'empty URLs'], true);
    $game = Game::create([
        'name' => 'The White Castle',
        'min_players' => 2,
        'max_players' => 2,
        'game_mode' => 'cooperative',
        'image_url' => $missing ? ($state === 'null' ? null : '') : 'https://example.com/manual.jpg',
        'bgg_url' => $missing ? ($state === 'null' ? null : '') : 'https://example.com/manual-bgg',
        'min_playtime' => $missing ? null : ($state === 'zero' ? 0 : 30),
        'max_playtime' => $missing ? null : ($state === 'zero' ? 0 : 45),
        'complexity' => $missing ? null : ($state === 'zero' ? 0 : 3),
        'short_description' => 'Descripción manual',
        'additional_information' => 'Información manual',
        'youtube_url' => 'https://example.com/manual-tutorial',
    ]);
    $game->collections()->attach($this->collection);
    $game->idealPlayerCounts()->create(['player_count' => 3]);
    $expansion = $game->expansions()->create(['name' => 'Mi expansión', 'is_active' => false]);
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Votación histórica',
        'token' => 'LEGACYHISTORY',
        'status' => 'closed',
    ]);
    $candidate = $poll->candidates()->create(['game_id' => $game->id, 'expansion_id' => $expansion->id]);
    $participant = $poll->participants()->create(['player_id' => $player->id, 'display_name' => 'Ana']);
    $participant->votes()->create([
        'poll_id' => $poll->id,
        'poll_candidate_id' => $candidate->id,
        'position' => 1,
    ]);
    foreach (['2026-10-20', '2026-10-25'] as $index => $date) {
        $play = Play::create([
            'created_by' => $this->user->id,
            'game_id' => $game->id,
            'poll_id' => $index === 0 ? $poll->id : null,
            'expansion_id' => $expansion->id,
            'played_at' => $date,
            'notes' => 'Partida histórica',
        ]);
        $play->players()->attach($player);
    }
    $tables = ['game_ideal_player_counts', 'expansions', 'plays', 'players', 'play_player', 'polls', 'poll_candidates', 'poll_participants', 'votes'];
    $snapshot = fn () => collect($tables)->mapWithKeys(
        fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]
    )->all();
    $historyBefore = $snapshot();
    $otherCollection = GameCollection::create(['created_by' => $this->user->id, 'name' => 'Otra colección']);
    $this->payload = array_replace($this->payload, [
        'name' => '  The White Castle  ',
        'bgg_id' => '371942',
        'image_url' => 'https://example.com/imported.jpg',
        'min_playtime' => 60,
        'max_playtime' => 90,
        'complexity' => 2.5,
        'short_description' => 'Descripción importada',
        'additional_information' => 'Información importada',
        'youtube_url' => 'https://example.com/imported-tutorial',
        'collection_ids' => [(string) $otherCollection->id],
    ]);

    $this->actingAs($this->user)->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('games.show', $game))
        ->assertSessionHas('success', 'El juego que ya tenías se ha vinculado con BoardGameGeek.');

    $game->refresh();
    expect($game->bgg_id)->toBe(371942)
        ->and($game->name)->toBe('The White Castle')
        ->and($game->min_players)->toBe(2)
        ->and($game->max_players)->toBe(2)
        ->and($game->game_mode)->toBe('cooperative')
        ->and($game->short_description)->toBe('Descripción manual')
        ->and($game->additional_information)->toBe('Información manual')
        ->and($game->youtube_url)->toBe('https://example.com/manual-tutorial')
        ->and($game->image_url)->toBe($missing ? $this->payload['image_url'] : 'https://example.com/manual.jpg')
        ->and($game->bgg_url)->toBe($missing ? $this->payload['bgg_url'] : 'https://example.com/manual-bgg')
        ->and($game->min_playtime)->toBe($missing ? 60 : ($state === 'zero' ? 0 : 30))
        ->and($game->max_playtime)->toBe($missing ? 90 : ($state === 'zero' ? 0 : 45))
        ->and($game->complexity)->toBe($missing ? '2.50' : ($state === 'zero' ? '0.00' : '3.00'))
        ->and($game->collections->modelKeys())->toEqualCanonicalizing([$this->collection->id, $otherCollection->id]);
    expect($snapshot())->toBe($historyBefore);

    $linkedState = $game->getAttributes();
    $this->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('games.show', $game))
        ->assertSessionHas('success', 'El juego ya estaba importado desde BGG y se ha añadido a tu colección.');
    expect($game->fresh()->getAttributes())->toBe($linkedState);
    expect($snapshot())->toBe($historyBefore);
    $this->assertDatabaseCount('games', 1);
    $this->assertDatabaseCount('game_collection_game', 2);
})->with(['null', 'empty URLs', 'populated', 'zero']);

test('a BGG ID match takes priority over an owned manual title when saving', function () {
    $manualGame = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $manualGame->collections()->attach($this->collection);
    $linkedGame = Game::create([
        'name' => 'Nombre anterior de la ficha BGG',
        'bgg_id' => 371942,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $this->payload['bgg_id'] = 371942;

    $this->actingAs($this->user)->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('games.show', $linkedGame));

    $this->assertDatabaseCount('games', 2);
    expect($manualGame->fresh()->bgg_id)->toBeNull()
        ->and($linkedGame->fresh()->name)->toBe('Nombre anterior de la ficha BGG')
        ->and($linkedGame->collections->modelKeys())->toBe([$this->collection->id]);
});

test('reimporting an existing BGG game preserves populated optional values', function ($input) {
    $game = Game::create([
        'name' => 'The White Castle',
        'bgg_id' => 371942,
        'image_url' => 'https://example.com/previous.jpg',
        'bgg_url' => 'https://boardgamegeek.com/boardgame/371942/previous',
        'min_players' => 1,
        'max_players' => 4,
        'min_playtime' => 30,
        'max_playtime' => 90,
        'complexity' => 3,
        'game_mode' => 'competitive',
    ]);
    $this->payload['bgg_id'] = 371942;
    unset($this->payload['bgg_url']);

    if ($input === 'null') {
        $this->payload['image_url'] = null;
        $this->payload['bgg_url'] = null;
    }

    $this->actingAs($this->user)->post(route('games.store'), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('games.show', $game));

    $game->refresh();
    expect($game->image_url)->toBe('https://example.com/previous.jpg')
        ->and($game->bgg_url)->toBe('https://boardgamegeek.com/boardgame/371942/previous')
        ->and($game->min_playtime)->toBe(30)
        ->and($game->max_playtime)->toBe(90)
        ->and($game->complexity)->toBe('3.00');
})->with(['omitted', 'null']);
