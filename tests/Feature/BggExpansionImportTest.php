<?php

use App\Models\Expansion;
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
    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Mi colección',
    ]);
    $this->game = Game::create([
        'name' => 'The White Castle',
        'bgg_id' => 371942,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $this->game->collections()->attach($this->collection);
    $this->bggExpansion = [
        'bgg_id' => 123,
        'name' => 'Matcha',
        'image_url' => 'https://example.com/matcha.jpg',
        'bgg_url' => 'https://boardgamegeek.com/boardgame/123',
    ];
    $this->url = route('games.bgg.expansions.import', [$this->game, 123]);
    $this->returnUrl = route('games.show', $this->game);
    $this->from($this->returnUrl);
});

test('a verified expansion is imported and repeating the request reuses the same row', function () {
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->twice()->with(371942)->andReturn([$this->bggExpansion]);

    foreach ([1, 2] as $attempt) {
        $this->actingAs($this->user)->post($this->url)
            ->assertRedirect($this->returnUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', $attempt === 1
                ? 'Expansión importada desde BoardGameGeek.'
                : 'La expansión existente se ha vinculado con BoardGameGeek.');
    }

    $expansion = Expansion::sole();
    $this->assertDatabaseCount('expansions', 1);
    expect($expansion->game_id)->toBe($this->game->id)
        ->and($expansion->bgg_id)->toBe(123)
        ->and($expansion->name)->toBe('Matcha')
        ->and($expansion->image_url)->toBe($this->bggExpansion['image_url'])
        ->and($expansion->bgg_url)->toBe($this->bggExpansion['bgg_url'])
        ->and($expansion->is_active)->toBeTrue()
        ->and($expansion->youtube_url)->toBeNull()
        ->and($expansion->notes)->toBeNull();

    $this->withoutVite();
    $this->get(route('games.edit', $this->game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/edit')
            ->where('game.bgg_id', 371942)
        );
    $this->get(route('games.show', $this->game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/show')
            ->where('game.expansions.0', [
                'id' => $expansion->id,
                'bgg_id' => 123,
                'name' => 'Matcha',
                'image_url' => $this->bggExpansion['image_url'],
                'bgg_url' => $this->bggExpansion['bgg_url'],
                'youtube_url' => null,
                'notes' => null,
                'is_active' => true,
            ])
        );
});

test('existing expansions fill only missing media while preserving personal data and local names', function ($linked, $hasImage, $hasLocalMedia) {
    $expansion = $this->game->expansions()->create([
        'bgg_id' => $linked ? 123 : null,
        'name' => $linked ? 'Nombre local de Matcha' : 'Matcha',
        'image_url' => $hasLocalMedia ? 'https://example.com/previous.jpg' : null,
        'bgg_url' => $hasLocalMedia ? 'https://example.com/manual-bgg' : null,
        'youtube_url' => 'https://example.com/my-tutorial',
        'notes' => 'Mis notas',
        'is_active' => false,
    ]);

    if (! $hasImage) {
        $this->bggExpansion['image_url'] = null;
    }

    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->once()->with(371942)->andReturn([$this->bggExpansion]);

    $this->actingAs($this->user)->post($this->url)
        ->assertRedirect($this->returnUrl)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'La expansión existente se ha vinculado con BoardGameGeek.');

    $expansion->refresh();
    $this->assertDatabaseCount('expansions', 1);
    expect($expansion->bgg_id)->toBe(123)
        ->and($expansion->name)->toBe($linked ? 'Nombre local de Matcha' : 'Matcha')
        ->and($expansion->image_url)->toBe($hasLocalMedia ? 'https://example.com/previous.jpg' : $this->bggExpansion['image_url'])
        ->and($expansion->bgg_url)->toBe($hasLocalMedia ? 'https://example.com/manual-bgg' : $this->bggExpansion['bgg_url'])
        ->and($expansion->youtube_url)->toBe('https://example.com/my-tutorial')
        ->and($expansion->notes)->toBe('Mis notas')
        ->and($expansion->is_active)->toBeTrue();
})->with([
    'manual expansion keeps media when BGG has an image' => [false, true, true],
    'manual expansion keeps media when BGG has no image' => [false, false, true],
    'linked expansion keeps media when BGG has an image' => [true, true, true],
    'linked expansion keeps media when BGG has no image' => [true, false, true],
    'manual expansion fills missing media' => [false, true, false],
    'manual expansion without local or BGG image' => [false, false, false],
    'linked expansion fills missing media' => [true, true, false],
    'linked expansion without local or BGG image' => [true, false, false],
]);

test('linking and reimporting a manual expansion preserve its ID and all history references', function () {
    $expansion = $this->game->expansions()->create([
        'name' => 'Matcha',
        'youtube_url' => 'https://example.com/tutorial',
        'notes' => 'Mis notas',
        'is_active' => false,
    ]);
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Votación con expansión manual',
        'token' => 'LINKEXPANSION',
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
        'poll_id' => $poll->id,
        'expansion_id' => $expansion->id,
        'played_at' => '2026-10-25',
    ]);
    $play->players()->attach($player);
    $tables = ['games', 'plays', 'players', 'play_player', 'polls', 'poll_candidates', 'poll_participants', 'votes'];
    $snapshot = fn () => collect($tables)->mapWithKeys(
        fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]
    )->all();
    $before = $snapshot();
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->twice()->with(371942)->andReturn([$this->bggExpansion]);

    foreach ([1, 2] as $attempt) {
        $this->actingAs($this->user)->post($this->url)
            ->assertRedirect($this->returnUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'La expansión existente se ha vinculado con BoardGameGeek.');
    }

    expect(Expansion::sole()->id)->toBe($expansion->id);
    expect($expansion->fresh()->bgg_id)->toBe(123);
    expect($snapshot())->toBe($before);
});

test('a BGG ID associated with another game is rejected without moving or modifying either expansion', function () {
    $otherGame = Game::create([
        'name' => 'Heat',
        'min_players' => 1,
        'max_players' => 6,
        'game_mode' => 'competitive',
    ]);
    $foreignExpansion = $otherGame->expansions()->create(['bgg_id' => 123, 'name' => 'Matcha']);
    $manualExpansion = $this->game->expansions()->create(['name' => 'Matcha']);
    $before = Expansion::orderBy('id')->get()->toArray();
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->once()->with(371942)->andReturn([$this->bggExpansion]);

    $this->actingAs($this->user)->post($this->url)
        ->assertRedirect($this->returnUrl)
        ->assertSessionHasErrors(['bgg_expansion' => 'Esta expansión ya está asociada a otro juego.']);

    expect(Expansion::orderBy('id')->get()->toArray())->toBe($before);
});

test('an expansion missing from the verified BGG list cannot be imported', function () {
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->once()->with(371942)->andReturn([]);

    $this->actingAs($this->user)->post($this->url)
        ->assertRedirect($this->returnUrl)
        ->assertSessionHasErrors(['bgg_expansion' => 'BoardGameGeek no reconoce esta expansión para este juego.']);

    $this->assertDatabaseCount('expansions', 0);
});

test('expansion import requires a game in the current users collections', function ($foreign) {
    $this->game->collections()->detach();

    if ($foreign) {
        $collection = GameCollection::create([
            'created_by' => User::factory()->create()->id,
            'name' => 'Colección ajena',
        ]);
        $this->game->collections()->attach($collection);
    }

    $this->mock(BggService::class)->shouldNotReceive('expansionsForGame');

    $this->actingAs($this->user)->post($this->url)->assertForbidden();
    $this->assertDatabaseCount('expansions', 0);
})->with(['unowned game' => [false], 'foreign collection' => [true]]);

test('a game without a BGG ID rejects expansion import before querying BGG', function () {
    $this->game->update(['bgg_id' => null]);
    $this->mock(BggService::class)->shouldNotReceive('expansionsForGame');

    $this->actingAs($this->user)->post($this->url)
        ->assertRedirect($this->returnUrl)
        ->assertSessionHasErrors(['bgg_expansion' => 'El juego base no está vinculado con BGG.']);
    $this->assertDatabaseCount('expansions', 0);
});

test('BGG import failures redirect back with an error without creating an expansion', function () {
    $this->mock(BggService::class)->shouldReceive('expansionsForGame')
        ->once()->with(371942)->andThrow(new RuntimeException('BGG no disponible.'));

    $this->actingAs($this->user)->post($this->url)
        ->assertRedirect($this->returnUrl)
        ->assertSessionHasErrors(['bgg_expansion' => 'No se ha podido importar la expansión desde BoardGameGeek.']);
    $this->assertDatabaseCount('expansions', 0);
});

test('expansion import requires authentication', function () {
    $this->mock(BggService::class)->shouldNotReceive('expansionsForGame');

    $this->post($this->url)->assertRedirect(route('login'));
    $this->assertDatabaseCount('expansions', 0);
});

test('the expansion import route only accepts numeric BGG IDs', function () {
    $this->mock(BggService::class)->shouldNotReceive('expansionsForGame');

    $this->actingAs($this->user)
        ->post(route('games.bgg.expansions.import', [$this->game, 'invalid']))
        ->assertNotFound();
    $this->assertDatabaseCount('expansions', 0);
});
