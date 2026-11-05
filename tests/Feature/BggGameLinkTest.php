<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use App\Services\BggService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->collection = GameCollection::create(['created_by' => $this->user->id, 'name' => 'Mi colección']);
    $this->game = Game::create([
        'name' => 'Mi White Castle',
        'min_players' => 2,
        'max_players' => 3,
        'game_mode' => 'cooperative',
        'short_description' => 'Mi descripción',
        'additional_information' => 'Mis notas',
        'youtube_url' => 'https://example.com/tutorial',
    ]);
    $this->game->collections()->attach($this->collection);
    $this->game->idealPlayerCounts()->create(['player_count' => 3]);
    $this->searchUrl = route('games.bgg-link.search', $this->game);
    $this->linkUrl = route('games.bgg-link.store', [$this->game, 371942]);
    $this->returnUrl = route('games.edit', $this->game);
    $this->from($this->returnUrl);
    $this->data = [
        'id' => 371942,
        'name' => 'The White Castle',
        'year_published' => 2023,
        'image_url' => 'https://example.com/imported.jpg',
        'min_players' => 1,
        'max_players' => 4,
        'min_playtime' => 60,
        'max_playtime' => 90,
        'complexity' => 3.08,
        'bgg_url' => 'https://boardgamegeek.com/boardgame/371942',
    ];
});

test('link searches use the supplied query or fall back to the local game name without changing the game', function ($query) {
    $expected = $query === 'omitted' || $query === null ? $this->game->name : trim($query);
    $results = [['id' => 371942, 'name' => 'The White Castle', 'year_published' => 2023]];
    $this->mock(BggService::class)->shouldReceive('searchForLink')->once()->with($expected)->andReturn($results);
    $before = $this->game->fresh()->getAttributes();
    $url = $query === 'omitted' ? $this->searchUrl : route('games.bgg-link.search', [$this->game, 'q' => $query]);

    $this->actingAs($this->user)->getJson($url)->assertOk()->assertExactJson(['results' => $results]);

    expect($this->game->fresh()->getAttributes())->toBe($before);
})->with(['omitted', null, '  White Castle  ', '371942', 'https://boardgamegeek.com/boardgame/371942']);

test('an empty fallback name returns no search results without contacting BGG', function () {
    $this->game->update(['name' => '']);
    $this->mock(BggService::class)->shouldNotReceive('searchForLink');

    $this->actingAs($this->user)->getJson($this->searchUrl)->assertOk()->assertExactJson(['results' => []]);
});

test('link searches validate query input', function ($query) {
    $this->mock(BggService::class)->shouldNotReceive('searchForLink');

    $this->actingAs($this->user)->getJson(route('games.bgg-link.search', [$this->game, 'q' => $query]))
        ->assertUnprocessable()->assertJsonValidationErrors('q');
})->with(['too long' => str_repeat('n', 256), 'array' => [['not a string']]]);

test('link searches return a JSON error when the service fails', function () {
    $this->mock(BggService::class)->shouldReceive('searchForLink')->once()->andThrow(new RuntimeException('BGG no disponible.'));

    $this->actingAs($this->user)->getJson($this->searchUrl)->assertStatus(502)
        ->assertExactJson(['message' => 'No se ha podido buscar el juego en BoardGameGeek.']);
});

test('linking completes only missing fields and preserves local decisions memberships and history', function ($state) {
    if ($state !== 'empty') {
        $this->game->update([
            'image_url' => 'https://example.com/manual.jpg',
            'min_playtime' => $state === 'zero' ? 0 : 30,
            'max_playtime' => $state === 'zero' ? 0 : 45,
            'complexity' => $state === 'zero' ? 0 : 2.5,
        ]);
    }
    $this->game->update(['bgg_url' => 'https://example.com/old-link']);
    $expansion = $this->game->expansions()->create(['name' => 'Matcha manual', 'is_active' => false]);
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Partida histórica',
        'token' => 'LINKHISTORY',
        'status' => 'closed',
    ]);
    $candidate = $poll->candidates()->create(['game_id' => $this->game->id, 'expansion_id' => $expansion->id]);
    $participant = $poll->participants()->create(['player_id' => $player->id, 'display_name' => 'Ana']);
    $participant->votes()->create([
        'poll_id' => $poll->id,
        'poll_candidate_id' => $candidate->id,
        'position' => 1,
    ]);
    $play = Play::create([
        'created_by' => $this->user->id,
        'game_id' => $this->game->id,
        'expansion_id' => $expansion->id,
        'poll_id' => $poll->id,
        'played_at' => '2026-10-25',
    ]);
    $play->players()->attach($player);
    $tables = ['game_collections', 'game_collection_game', 'game_ideal_player_counts', 'expansions', 'plays', 'play_player', 'players', 'polls', 'poll_candidates', 'poll_participants', 'votes'];
    $snapshot = fn () => collect($tables)->mapWithKeys(
        fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]
    )->all();
    $before = $snapshot();
    $localFields = ['name', 'min_players', 'max_players', 'game_mode', 'short_description', 'additional_information', 'youtube_url', 'is_active'];
    $localData = $this->game->fresh()->only($localFields);
    $this->mock(BggService::class)->shouldReceive('gameForLink')->twice()->with(371942)->andReturn($this->data);

    foreach ([1, 2] as $attempt) {
        $this->actingAs($this->user)->post($this->linkUrl)->assertRedirect($this->returnUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Juego vinculado con BoardGameGeek. Se han completado los datos que faltaban.');
    }

    $game = $this->game->fresh();
    expect($game->bgg_id)->toBe(371942)
        ->and($game->bgg_url)->toBe($this->data['bgg_url'])
        ->and($game->only($localFields))->toBe($localData)
        ->and($game->image_url)->toBe($state === 'empty' ? $this->data['image_url'] : 'https://example.com/manual.jpg')
        ->and($game->min_playtime)->toBe($state === 'empty' ? 60 : ($state === 'zero' ? 0 : 30))
        ->and($game->max_playtime)->toBe($state === 'empty' ? 90 : ($state === 'zero' ? 0 : 45))
        ->and($game->complexity)->toBe($state === 'empty' ? '3.08' : ($state === 'zero' ? '0.00' : '2.50'));
    expect($snapshot())->toBe($before);
    $this->assertDatabaseCount('games', 1);

    $this->withoutVite();
    $this->get($this->returnUrl)->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/edit')
            ->where('game.bgg_id', 371942)
            ->where('game.bgg_url', $this->data['bgg_url'])
        );
})->with(['empty', 'populated', 'zero']);

test('a BGG ID already linked to another game is rejected before contacting BGG', function () {
    $duplicate = Game::create(['name' => 'Ya vinculado', 'bgg_id' => 371942, 'min_players' => 1, 'max_players' => 4, 'game_mode' => 'competitive']);
    $before = Game::orderBy('id')->get()->toArray();
    $this->mock(BggService::class)->shouldNotReceive('gameForLink');

    $this->actingAs($this->user)->post($this->linkUrl)->assertRedirect($this->returnUrl)
        ->assertSessionHasErrors(['bgg_link' => "Ese juego de BGG ya está vinculado a «{$duplicate->name}»."]);

    expect(Game::orderBy('id')->get()->toArray())->toBe($before);
});

test('a failed detail request does not change a local game', function () {
    $before = $this->game->fresh()->getAttributes();
    $this->mock(BggService::class)->shouldReceive('gameForLink')->once()->with(371942)->andThrow(new RuntimeException('BGG no disponible.'));

    $this->actingAs($this->user)->post($this->linkUrl)->assertRedirect($this->returnUrl)
        ->assertSessionHasErrors(['bgg_link' => 'No se han podido obtener los datos de BoardGameGeek.']);

    expect($this->game->fresh()->getAttributes())->toBe($before);
});

test('games outside owned collections cannot be searched or linked', function ($foreign) {
    $this->game->collections()->detach();
    if ($foreign) {
        $collection = GameCollection::create(['created_by' => User::factory()->create()->id, 'name' => 'Ajena']);
        $this->game->collections()->attach($collection);
    }
    $before = $this->game->fresh()->getAttributes();
    $this->mock(BggService::class)->shouldNotReceive('gameForLink')->shouldNotReceive('searchForLink');

    $this->actingAs($this->user)->getJson($this->searchUrl)->assertForbidden();
    $this->post($this->linkUrl)->assertForbidden();

    expect($this->game->fresh()->getAttributes())->toBe($before);
})->with(['uncollected' => false, 'foreign' => true]);

test('searching and linking existing games require authentication', function () {
    $this->mock(BggService::class)->shouldNotReceive('gameForLink')->shouldNotReceive('searchForLink');

    $this->getJson($this->searchUrl)->assertUnauthorized();
    $this->post($this->linkUrl)->assertRedirect(route('login'));
});

test('the linking route rejects nonnumeric BGG IDs', function () {
    $this->mock(BggService::class)->shouldNotReceive('gameForLink');

    $this->actingAs($this->user)->post(route('games.bgg-link.store', [$this->game, 'invalid']))->assertNotFound();
});
