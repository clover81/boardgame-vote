<?php

use App\Models\Expansion;
use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
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
    $this->expansion = $this->game->expansions()->create([
        'bgg_id' => 123,
        'name' => 'Zeta',
        'image_url' => 'https://example.com/expansion.jpg',
        'bgg_url' => 'https://boardgamegeek.com/boardgame/123',
        'youtube_url' => 'https://example.com/tutorial',
        'notes' => 'Mis notas',
        'is_active' => true,
    ]);
    $this->editUrl = route('games.edit', $this->game);
    $this->updateUrl = route('games.expansions.update', [$this->game, $this->expansion]);
    $this->deleteUrl = route('games.expansions.destroy', [$this->game, $this->expansion]);
    $this->payload = [
        'name' => 'Nombre editado',
        'image_url' => 'https://example.com/new.jpg',
        'bgg_url' => 'https://boardgamegeek.com/boardgame/123/expansion',
        'youtube_url' => 'https://example.com/new-tutorial',
        'notes' => 'Notas editadas',
        'is_active' => false,
    ];
    $this->from($this->editUrl);
});

test('game editing receives all expansion fields sorted by name including inactive expansions', function () {
    $this->withoutVite();
    $alpha = $this->game->expansions()->create(['name' => 'Alfa', 'is_active' => false]);

    $this->actingAs($this->user)->get($this->editUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/edit')
            ->where('game.bgg_id', 371942)
            ->where('game.expansions', [
                [
                    'id' => $alpha->id,
                    'bgg_id' => null,
                    'name' => 'Alfa',
                    'image_url' => null,
                    'bgg_url' => null,
                    'youtube_url' => null,
                    'notes' => null,
                    'is_active' => false,
                ],
                $this->expansion->only(['id', 'bgg_id', 'name', 'image_url', 'bgg_url', 'youtube_url', 'notes', 'is_active']),
            ])
        );
});

test('an owner can patch expansion details without changing its parent or BGG ID', function () {
    $this->actingAs($this->user)->patch($this->updateUrl, [
        ...$this->payload,
        'bgg_id' => 999,
        'game_id' => 999,
    ])
        ->assertRedirect($this->editUrl)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Expansión actualizada.');

    $this->expansion->refresh();
    expect($this->expansion->only(array_keys($this->payload)))->toBe($this->payload)
        ->and($this->expansion->bgg_id)->toBe(123)
        ->and($this->expansion->game_id)->toBe($this->game->id);
});

test('invalid expansion updates leave the saved data unchanged', function ($field, $value) {
    $before = $this->expansion->fresh()->toArray();

    $this->actingAs($this->user)->patch($this->updateUrl, array_replace($this->payload, [$field => $value]))
        ->assertRedirect($this->editUrl)
        ->assertSessionHasErrors($field);

    expect($this->expansion->fresh()->toArray())->toBe($before);
})->with([
    'missing name' => ['name', ''],
    'invalid image URL' => ['image_url', 'invalid'],
    'invalid active state' => ['is_active', 'invalid'],
]);

test('deleting an expansion preserves plays players poll candidates and votes', function () {
    $poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Votación histórica',
        'token' => 'EXPANSIONHISTORY',
        'status' => 'closed',
    ]);
    $candidate = $poll->candidates()->create([
        'game_id' => $this->game->id,
        'expansion_id' => $this->expansion->id,
    ]);
    $participant = $poll->participants()->create(['display_name' => 'Ana']);
    $vote = $participant->votes()->create([
        'poll_id' => $poll->id,
        'poll_candidate_id' => $candidate->id,
        'position' => 1,
    ]);
    $play = Play::create([
        'created_by' => $this->user->id,
        'game_id' => $this->game->id,
        'expansion_id' => $this->expansion->id,
        'poll_id' => $poll->id,
        'played_at' => '2026-10-28',
        'notes' => 'Historial conservado',
    ]);
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $play->players()->attach($player);
    $otherExpansion = $this->game->expansions()->create(['name' => 'Otra expansión']);
    $otherPlay = Play::create([
        'created_by' => $this->user->id,
        'game_id' => $this->game->id,
        'expansion_id' => $otherExpansion->id,
        'played_at' => '2026-10-28',
    ]);
    $playBefore = $play->fresh()->toArray();
    $candidateBefore = $candidate->fresh()->toArray();

    $this->actingAs($this->user)->delete($this->deleteUrl)
        ->assertRedirect($this->editUrl)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Expansión eliminada.');

    $this->assertDatabaseMissing('expansions', ['id' => $this->expansion->id]);
    expect($play->fresh()->toArray())->toBe(array_replace($playBefore, ['expansion_id' => null]))
        ->and($candidate->fresh()->toArray())->toBe(array_replace($candidateBefore, ['expansion_id' => null]))
        ->and($play->fresh()->players->modelKeys())->toBe([$player->id])
        ->and($otherPlay->fresh()->expansion_id)->toBe($otherExpansion->id);
    $this->assertDatabaseHas('votes', ['id' => $vote->id, 'poll_candidate_id' => $candidate->id]);
    $this->assertDatabaseHas('polls', ['id' => $poll->id]);
    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
});

test('nested expansion actions reject an expansion belonging to a different game', function ($method) {
    $otherGame = Game::create([
        'name' => 'Heat',
        'min_players' => 1,
        'max_players' => 6,
        'game_mode' => 'competitive',
    ]);
    $otherExpansion = $otherGame->expansions()->create(['name' => 'Foreign expansion']);
    $before = Expansion::orderBy('id')->get()->toArray();
    $routeName = $method === 'patch' ? 'games.expansions.update' : 'games.expansions.destroy';

    $this->actingAs($this->user)->{$method}(route($routeName, [$this->game, $otherExpansion]), $this->payload)
        ->assertNotFound();

    expect(Expansion::orderBy('id')->get()->toArray())->toBe($before);
})->with(['patch', 'delete']);

test('expansion actions require ownership of the parent game', function ($method) {
    $this->game->collections()->detach();
    $before = $this->expansion->fresh()->toArray();
    $url = $method === 'patch' ? $this->updateUrl : $this->deleteUrl;

    $this->actingAs($this->user)->{$method}($url, $this->payload)->assertForbidden();

    expect($this->expansion->fresh()->toArray())->toBe($before);
})->with(['patch', 'delete']);

test('expansion actions require authentication', function ($method) {
    $url = $method === 'patch' ? $this->updateUrl : $this->deleteUrl;

    $this->{$method}($url, $this->payload)->assertRedirect(route('login'));

    $this->assertDatabaseHas('expansions', ['id' => $this->expansion->id]);
})->with(['patch', 'delete']);
