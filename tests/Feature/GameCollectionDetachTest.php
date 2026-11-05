<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->collections = collect(['Actual', 'Otra'])->map(fn ($name) => GameCollection::create([
        'created_by' => $this->user->id,
        'name' => $name,
    ]));
    $this->foreignCollection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección ajena',
    ]);
    $this->game = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);
    $this->game->collections()->attach($this->collections->first());
    $this->url = route('games.collections.detach', [$this->game, $this->collections->first()]);
});

test('detaching removes only the selected membership and preserves all game history', function ($stillOwned, $shared) {
    if ($stillOwned) {
        $this->game->collections()->attach($this->collections->last());
    }
    if ($shared) {
        $this->game->collections()->attach($this->foreignCollection);
    }
    $otherGame = Game::create([
        'name' => 'Heat',
        'min_players' => 1,
        'max_players' => 6,
        'game_mode' => 'competitive',
    ]);
    $otherGame->collections()->attach($this->collections->first());
    $this->game->idealPlayerCounts()->create(['player_count' => 3]);
    $expansion = $this->game->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Partida del domingo',
        'token' => 'DETACHTEST',
        'status' => 'closed',
    ]);
    $poll->gameCollections()->attach($this->collections->first());
    $candidate = $poll->candidates()->create(['game_id' => $this->game->id, 'expansion_id' => $expansion->id]);
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $participant = $poll->participants()->create(['player_id' => $player->id, 'display_name' => 'Ana']);
    $participant->votes()->create([
        'poll_id' => $poll->id,
        'poll_candidate_id' => $candidate->id,
        'position' => 1,
    ]);
    $play = Play::create([
        'created_by' => $this->user->id,
        'poll_id' => $poll->id,
        'game_id' => $this->game->id,
        'expansion_id' => $expansion->id,
        'played_at' => '2026-10-25',
    ]);
    $play->players()->attach($player);
    $tables = ['games', 'game_collections', 'game_ideal_player_counts', 'expansions', 'plays', 'play_player', 'players', 'polls', 'poll_game_collection', 'poll_candidates', 'poll_participants', 'votes'];
    $snapshot = fn () => collect($tables)->mapWithKeys(
        fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]
    )->all();
    $before = $snapshot();
    $expectedMemberships = DB::table('game_collection_game')
        ->whereNot(fn ($query) => $query->where('game_id', $this->game->id)
            ->where('game_collection_id', $this->collections->first()->id))
        ->orderBy('id')->get()->toJson();

    $returnUrl = route('games.show', $this->game);
    $this->actingAs($this->user)->from($returnUrl)->delete($this->url)
        ->assertRedirect($stillOwned ? $returnUrl : route('games.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', $stillOwned ? 'Juego eliminado de la colección.' : 'Juego eliminado de tu colección.');

    expect($snapshot())->toBe($before);
    expect(DB::table('game_collection_game')->orderBy('id')->get()->toJson())->toBe($expectedMemberships);

    $this->get($returnUrl)->assertStatus($stillOwned ? 200 : 403);
    if ($stillOwned) {
        $this->get($returnUrl)->assertInertia(fn (Assert $page) => $page
            ->has('collections', 1)->where('collections.0.id', $this->collections->last()->id));
    }
    if ($shared) {
        $this->actingAs(User::findOrFail($this->foreignCollection->created_by))->get($returnUrl)->assertOk();
    }
})->with(['another owned membership' => true, 'last owned membership' => false])
    ->with(['shared' => true, 'unshared' => false]);

test('foreign collections cannot be detached even when the game is also owned', function ($attached) {
    if ($attached) {
        $this->game->collections()->attach($this->foreignCollection);
    }
    $before = $this->game->collections()->pluck('game_collections.id')->all();

    $this->actingAs($this->user)->delete(route('games.collections.detach', [$this->game, $this->foreignCollection]))
        ->assertForbidden();

    expect($this->game->collections()->pluck('game_collections.id')->all())->toBe($before);
})->with(['attached' => true, 'unattached' => false]);

test('detaching from an owned collection without that game returns not found', function () {
    $this->actingAs($this->user)->delete(route('games.collections.detach', [$this->game, $this->collections->last()]))
        ->assertNotFound();

    $this->assertDatabaseCount('game_collection_game', 1);
});

test('repeating a successful detach returns not found and preserves the game', function () {
    $this->actingAs($this->user)->delete($this->url)->assertRedirect(route('games.index'));
    $this->delete($this->url)->assertNotFound();

    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
    $this->assertDatabaseCount('game_collection_game', 0);
});

test('detaching requires authentication', function () {
    $this->delete($this->url)->assertRedirect(route('login'));

    $this->assertDatabaseCount('game_collection_game', 1);
});

test('detaching nonexistent games or collections returns not found', function ($missing) {
    $this->actingAs($this->user)->delete(route('games.collections.detach', [
        $missing === 'game' ? 999999 : $this->game->id,
        $missing === 'collection' ? 999999 : $this->collections->first()->id,
    ]))->assertNotFound();

    $this->assertDatabaseCount('game_collection_game', 1);
})->with(['game', 'collection']);
