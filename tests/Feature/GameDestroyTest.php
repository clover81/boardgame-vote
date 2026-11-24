<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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
    $this->game->idealPlayerCounts()->createMany([
        ['player_count' => 3],
        ['player_count' => 4],
    ]);
    $this->expansion = $this->game->expansions()->create(['name' => 'Matcha']);
    $this->editUrl = route('games.edit', $this->game);
    $this->deleteUrl = route('games.destroy', $this->game);
    $this->gameState = fn () => $this->game->fresh()
        ?->load('collections', 'idealPlayerCounts', 'expansions')->toArray();
});

test('an unused game in owned collections can be deleted completely without affecting other games', function () {
    $anotherCollection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Otra colección',
    ]);
    $this->game->collections()->attach($anotherCollection);
    $otherGame = Game::create([
        'name' => 'Heat',
        'min_players' => 1,
        'max_players' => 6,
        'game_mode' => 'competitive',
    ]);
    $otherGame->collections()->attach($this->collection);
    $otherGame->idealPlayerCounts()->create(['player_count' => 6]);
    $otherGame->expansions()->create(['name' => 'Heavy Rain']);
    $otherGameState = $otherGame->fresh()->load('collections', 'idealPlayerCounts', 'expansions')->toArray();

    $this->actingAs($this->user)->from($this->editUrl)->delete($this->deleteUrl)
        ->assertRedirect(route('games.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Juego eliminado definitivamente.');

    $this->assertDatabaseMissing('games', ['id' => $this->game->id]);
    $this->assertDatabaseMissing('game_ideal_player_counts', ['game_id' => $this->game->id]);
    $this->assertDatabaseMissing('game_collection_game', ['game_id' => $this->game->id]);
    $this->assertDatabaseMissing('expansions', ['game_id' => $this->game->id]);
    $this->assertDatabaseCount('game_collections', 2);
    expect($otherGame->fresh()->load('collections', 'idealPlayerCounts', 'expansions')->toArray())
        ->toBe($otherGameState);
});

test('a game outside the current users collections cannot be deleted', function ($foreignCollection) {
    $this->game->collections()->detach();

    if ($foreignCollection) {
        $collection = GameCollection::create([
            'created_by' => User::factory()->create()->id,
            'name' => 'Colección ajena',
        ]);
        $this->game->collections()->attach($collection);
    }

    $before = ($this->gameState)();

    $this->actingAs($this->user)->delete($this->deleteUrl)->assertForbidden();

    expect(($this->gameState)())->toBe($before);
})->with(['unowned game' => [false], 'foreign collection' => [true]]);

test('a shared game cannot be deleted globally by an owner', function () {
    $collection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección compartida',
    ]);
    $this->game->collections()->attach($collection);
    $play = Play::create([
        'created_by' => $collection->created_by,
        'game_id' => $this->game->id,
        'expansion_id' => $this->expansion->id,
        'played_at' => '2026-10-28',
    ]);
    $before = ($this->gameState)();

    $this->actingAs($this->user)->from($this->editUrl)->delete($this->deleteUrl)
        ->assertRedirect($this->editUrl)
        ->assertSessionHasErrors([
            'game' => 'Este juego también está siendo utilizado por otra colección. Puedes quitarlo de tus colecciones, pero no eliminarlo globalmente.',
        ]);

    expect(($this->gameState)())->toBe($before);
    $this->assertDatabaseHas('plays', ['id' => $play->id]);
    $this->assertDatabaseCount('game_collection_game', 2);
    $this->assertDatabaseHas('game_collections', ['id' => $collection->id]);
    $this->assertDatabaseHas('game_collections', ['id' => $this->collection->id]);
});

test('deletion protects owned plays and player links', function () {
    $play = Play::create([
        'created_by' => $this->user->id,
        'game_id' => $this->game->id,
        'expansion_id' => $this->expansion->id,
        'played_at' => '2026-10-28',
    ]);

    $player = Player::create([
        'name' => 'Ana',
        'is_active' => true,
    ]);
    $play->players()->attach($player);

    $this->actingAs($this->user)
        ->from($this->editUrl)
        ->delete($this->deleteUrl)
        ->assertRedirect($this->editUrl)
        ->assertSessionHasErrors('game');

    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
    $this->assertDatabaseHas('plays', ['id' => $play->id]);
    $this->assertDatabaseHas('play_player', [
        'play_id' => $play->id,
        'player_id' => $player->id,
    ]);
    $this->assertDatabaseHas('expansions', [
        'id' => $this->expansion->id,
    ]);
});

test('deletion protects candidates and votes in every poll state', function ($status) {
    $poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Votación con historial',
        'token' => 'DESTROYTEST',
        'status' => $status,
    ]);

    $candidate = $poll->candidates()->create([
        'game_id' => $this->game->id,
    ]);

    $participant = $poll->participants()->create([
        'display_name' => 'Ana',
    ]);

    if ($status !== 'draft') {
        $participant->votes()->create([
            'poll_id' => $poll->id,
            'poll_candidate_id' => $candidate->id,
            'position' => 1,
        ]);
    }

    $this->actingAs($this->user)
        ->from($this->editUrl)
        ->delete($this->deleteUrl)
        ->assertRedirect($this->editUrl)
        ->assertSessionHasErrors('game');

    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
    $this->assertDatabaseHas('poll_candidates', [
        'id' => $candidate->id,
        'poll_id' => $poll->id,
    ]);
    $this->assertDatabaseHas('polls', [
        'id' => $poll->id,
        'status' => $status,
    ]);

    if ($status !== 'draft') {
        $this->assertDatabaseHas('votes', [
            'poll_participant_id' => $participant->id,
            'poll_candidate_id' => $candidate->id,
        ]);
    }
})->with(['draft', 'open', 'closed']);

test('a failure deleting an unused game rolls back all changes', function () {
    $tables = ['games', 'poll_candidates', 'votes', 'plays', 'play_player', 'game_ideal_player_counts', 'game_collection_game', 'expansions'];
    $snapshot = fn () => collect($tables)->mapWithKeys(
        fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]
    )->all();
    $before = $snapshot();
    $originalDispatcher = Game::getEventDispatcher();
    $testDispatcher = clone $originalDispatcher;
    $testDispatcher->listen('eloquent.deleting: '.Game::class, function () {
        throw new RuntimeException('Fallo simulado al borrar el juego.');
    });
    Game::setEventDispatcher($testDispatcher);
    $this->withoutExceptionHandling();

    try {
        $this->actingAs($this->user)->delete($this->deleteUrl);
        $this->fail('La eliminación debía fallar.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Fallo simulado al borrar el juego.');
    } finally {
        Game::setEventDispatcher($originalDispatcher);
    }

    expect($snapshot())->toBe($before);
});

test('deleting a game requires authentication', function () {
    $before = ($this->gameState)();

    $this->delete($this->deleteUrl)->assertRedirect(route('login'));

    expect(($this->gameState)())->toBe($before);
});

test('deleting a nonexistent game returns not found', function () {
    $before = ($this->gameState)();

    $this->actingAs($this->user)->delete(route('games.destroy', $this->game->id + 1000))
        ->assertNotFound();

    expect(($this->gameState)())->toBe($before);
});

test('deleting a game preserves another users historical play', function () {
    $otherUser = User::factory()->create();

    $otherCollection = GameCollection::create([
        'created_by' => $otherUser->id,
        'name' => 'Colección temporal',
    ]);

    $this->game->collections()->attach($otherCollection);

    $play = Play::create([
        'created_by' => $otherUser->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-10-28',
    ]);

    // El otro usuario retira el juego de su colección,
    // pero conserva su historial de partidas.
    $this->game->collections()->detach($otherCollection);

    // El usuario original intenta borrar el juego.
    $this->actingAs($this->user)
        ->from($this->editUrl)
        ->delete($this->deleteUrl);

    // No debe perderse el juego ni la partida ajena.
    $this->assertDatabaseHas('games', [
        'id' => $this->game->id,
    ]);

    $this->assertDatabaseHas('plays', [
        'id' => $play->id,
        'created_by' => $otherUser->id,
    ]);
});

test('deleting a game preserves historical plays without an owner', function () {
    $play = Play::create([
        'created_by' => null,
        'game_id' => $this->game->id,
        'expansion_id' => $this->expansion->id,
        'played_at' => '2026-10-28',
    ]);
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $play->players()->attach($player);
    $before = ($this->gameState)();

    $this->actingAs($this->user)->from($this->editUrl)->delete($this->deleteUrl)
        ->assertRedirect($this->editUrl)
        ->assertSessionHasErrors([
            'game' => 'No puedes eliminar este juego porque tiene partidas históricas de otras cuentas. Puedes quitarlo de tus colecciones.',
        ]);

    expect(($this->gameState)())->toBe($before);
    $this->assertDatabaseHas('plays', ['id' => $play->id, 'created_by' => null]);
    $this->assertDatabaseHas('play_player', ['play_id' => $play->id, 'player_id' => $player->id]);
});

test('deleting a game preserves another users poll and votes', function ($status) {
    $otherUser = User::factory()->create();

    $poll = Poll::create([
        'created_by' => $otherUser->id,
        'name' => 'Votación de otro usuario',
        'token' => 'FOREIGNPOLLTEST',
        'status' => $status,
    ]);

    $candidate = $poll->candidates()->create([
        'game_id' => $this->game->id,
    ]);

    $participant = $poll->participants()->create([
        'display_name' => 'Ana',
    ]);

    $vote = $participant->votes()->create([
        'poll_id' => $poll->id,
        'poll_candidate_id' => $candidate->id,
        'position' => 1,
    ]);

    $this->actingAs($this->user)
        ->from($this->editUrl)
        ->delete($this->deleteUrl)
        ->assertRedirect($this->editUrl)
        ->assertSessionHasErrors([
            'game' => 'No puedes eliminar este juego porque forma parte de votaciones de otras cuentas. Puedes quitarlo de tus colecciones.',
        ]);

    $this->assertDatabaseHas('games', [
        'id' => $this->game->id,
    ]);

    $this->assertDatabaseHas('poll_candidates', [
        'id' => $candidate->id,
    ]);

    $this->assertDatabaseHas('polls', [
        'id' => $poll->id,
        'created_by' => $otherUser->id,
        'status' => $status,
    ]);

    $this->assertDatabaseHas('votes', [
        'id' => $vote->id,
    ]);
})->with(['draft', 'open', 'closed']);

test('a foreign collection expansion prevents game deletion', function () {
    $otherUser = User::factory()->create();
    $foreignCollection = GameCollection::create([
        'created_by' => $otherUser->id,
        'name' => 'Expansiones de otra cuenta',
    ]);

    // La otra cuenta conserva la expansión, pero no el juego base.
    $foreignCollection->expansions()->attach($this->expansion->id);

    $this->assertDatabaseMissing('game_collection_game', [
        'game_id' => $this->game->id,
        'game_collection_id' => $foreignCollection->id,
    ]);

    $this->actingAs($this->user)
        ->from($this->editUrl)
        ->delete($this->deleteUrl)
        ->assertRedirect($this->editUrl)
        ->assertSessionHasErrors('game');

    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
    $this->assertDatabaseHas('expansions', ['id' => $this->expansion->id]);
    $this->assertDatabaseHas('game_collection_expansion', [
        'game_collection_id' => $foreignCollection->id,
        'expansion_id' => $this->expansion->id,
    ]);
    $this->assertDatabaseHas('game_collection_game', [
        'game_collection_id' => $this->collection->id,
        'game_id' => $this->game->id,
    ]);
});

test('deletion rechecks collection links after locking the game', function (bool $linkExpansion) {
    $foreign = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección añadida durante el borrado',
    ]);
    Route::bind('game', fn () => $this->game);

    $originalDispatcher = Game::getEventDispatcher();
    $dispatcher = clone $originalDispatcher;
    $dispatcher->listen('eloquent.retrieved: '.Game::class, function () use ($linkExpansion, $foreign) {
        if ($linkExpansion) {
            $foreign->expansions()->attach($this->expansion->id);
        } else {
            $foreign->games()->attach($this->game->id);
        }
    });
    Game::setEventDispatcher($dispatcher);

    try {
        $this->actingAs($this->user)->from($this->editUrl)
            ->delete($this->deleteUrl)
            ->assertRedirect($this->editUrl)
            ->assertSessionHasErrors('game');
    } finally {
        Game::setEventDispatcher($originalDispatcher);
    }

    $table = $linkExpansion ? 'game_collection_expansion' : 'game_collection_game';
    $key = $linkExpansion ? 'expansion_id' : 'game_id';
    $itemId = $linkExpansion ? $this->expansion->id : $this->game->id;

    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
    $this->assertDatabaseHas('expansions', ['id' => $this->expansion->id]);
    $this->assertDatabaseHas($table, ['game_collection_id' => $foreign->id, $key => $itemId]);
})->with(['game collection' => [false], 'expansion collection' => [true]]);
