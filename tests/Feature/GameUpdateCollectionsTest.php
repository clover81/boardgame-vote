<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->ownedCollections = collect(['Mi colección', 'Juegos del grupo', 'Nueva colección'])->map(
        fn ($name) => GameCollection::create([
            'created_by' => $this->user->id,
            'name' => $name,
        ])
    );
    $this->foreignCollection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección de Ana',
    ]);
    $this->game = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $this->originalCollectionIds = [
        $this->ownedCollections[0]->id,
        $this->ownedCollections[1]->id,
        $this->foreignCollection->id,
    ];
    $this->game->collections()->attach($this->originalCollectionIds);
    $this->game->idealPlayerCounts()->create(['player_count' => 2]);

    $this->payload = [
        'name' => 'The White Castle actualizado',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'ideal_player_counts' => [3, 4],
    ];
});

test('updating a game replaces only the current users collection memberships', function () {
    $selectedIds = [
        $this->ownedCollections[0]->id,
        $this->ownedCollections[2]->id,
    ];

    $this->actingAs($this->user)->put(route('games.update', $this->game), [
        ...$this->payload,
        'collection_ids' => array_map('strval', $selectedIds),
    ])->assertSessionHasNoErrors()->assertRedirect(route('games.index'));

    $this->game->refresh();

    expect($this->game->name)->toBe($this->payload['name']);
    expect($this->game->collections->modelKeys())->toEqualCanonicalizing([
        ...$selectedIds,
        $this->foreignCollection->id,
    ]);
    expect($this->game->idealPlayerCounts->pluck('player_count')->all())
        ->toEqualCanonicalizing([3, 4]);
});

test('invalid collections leave the game and all its memberships unchanged', function ($selection) {
    $collectionIds = match ($selection) {
        'foreign' => [$this->ownedCollections[0]->id, $this->foreignCollection->id],
        'nonexistent' => [$this->foreignCollection->id + 1],
        'duplicate' => [$this->ownedCollections[0]->id, $this->ownedCollections[0]->id],
        'non-integer' => ['invalid'],
        'not-array' => 'invalid',
    };
    $payload = $this->payload;
    $payload['collection_ids'] = $collectionIds;

    $returnUrl = route('games.edit', $this->game);
    $response = $this->actingAs($this->user)->from($returnUrl)->put(route('games.update', $this->game), $payload);

    $errorKey = match ($selection) {
        'duplicate', 'non-integer', 'nonexistent' => 'collection_ids.0',
        default => 'collection_ids',
    };
    $response->assertRedirect($returnUrl)
        ->assertSessionHasErrors($selection === 'foreign' ? [
            'collection_ids' => 'Alguna colección seleccionada no te pertenece.',
        ] : $errorKey)
        ->assertSessionHasInput('collection_ids', $collectionIds);

    $this->game->refresh();

    expect($this->game->name)->toBe('The White Castle');
    expect($this->game->collections->modelKeys())->toEqualCanonicalizing($this->originalCollectionIds);
    expect($this->game->idealPlayerCounts->pluck('player_count')->all())->toBe([2]);
})->with(['foreign', 'nonexistent', 'duplicate', 'non-integer', 'not-array']);

test('removing all owned memberships preserves shared collections and related records', function ($input, $shared) {
    $this->withoutVite();
    if (! $shared) {
        $this->game->collections()->detach($this->foreignCollection);
    }
    $expansion = $this->game->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Partida del domingo',
        'token' => 'REMOVEMEMBERSHIP',
        'status' => 'closed',
    ]);
    $candidate = $poll->candidates()->create(['game_id' => $this->game->id]);
    $play = Play::create([
        'created_by' => $this->user->id,
        'game_id' => $this->game->id,
        'expansion_id' => $expansion->id,
        'poll_id' => $poll->id,
        'played_at' => '2026-10-25',
    ]);
    $play->players()->attach($player);
    $playState = $play->fresh()->load('players')->toArray();
    $payload = $this->payload;
    if ($input !== 'omitted') {
        $payload['collection_ids'] = $input === 'null' ? null : [];
    }

    $this->actingAs($this->user)->put(route('games.update', $this->game), $payload)
        ->assertSessionHasNoErrors()->assertRedirect(route('games.index'));

    expect($this->game->fresh()->collections->modelKeys())
        ->toBe($shared ? [$this->foreignCollection->id] : []);
    expect($play->fresh()->load('players')->toArray())->toBe($playState);
    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
    $this->assertDatabaseHas('expansions', ['id' => $expansion->id, 'game_id' => $this->game->id]);
    $this->assertDatabaseHas('polls', ['id' => $poll->id, 'status' => 'closed']);
    $this->assertDatabaseHas('poll_candidates', ['id' => $candidate->id, 'game_id' => $this->game->id]);

    $this->get(route('games.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('games', 0));
    $this->get(route('games.show', $this->game))->assertForbidden();
    $this->get(route('games.edit', $this->game))->assertForbidden();
    $this->put(route('games.update', $this->game), $payload)->assertForbidden();

    if ($shared) {
        $this->actingAs(User::findOrFail($this->foreignCollection->created_by))
            ->get(route('games.show', $this->game))->assertOk();
    }
})->with(['empty', 'null', 'omitted'])->with(['shared' => true, 'unshared' => false]);

test('editing a shared game receives only owned collections and memberships', function () {
    $this->withoutVite();
    $this->ownedCollections[2]->update(['is_default' => true]);

    $this->actingAs($this->user)->get(route('games.edit', $this->game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/edit')
            ->has('collections', 3)
            ->where('collections.0.id', $this->ownedCollections[2]->id)
            ->where('collections.1.id', $this->ownedCollections[1]->id)
            ->where('collections.2.id', $this->ownedCollections[0]->id)
            ->where('game_collection_ids', fn ($ids) => collect($ids)->sort()->values()->all()
                === $this->ownedCollections->take(2)->pluck('id')->sort()->values()->all())
        );
});

test('games outside owned collections cannot be edited or updated', function ($hasForeignCollection) {
    $this->game->collections()->detach($this->ownedCollections->pluck('id'));
    if (! $hasForeignCollection) {
        $this->game->collections()->detach();
    }
    $before = $this->game->fresh()->load('collections', 'idealPlayerCounts')->toArray();

    $this->actingAs($this->user)->get(route('games.edit', $this->game))->assertForbidden();
    $this->put(route('games.update', $this->game), [
        ...$this->payload,
        'collection_ids' => [$this->ownedCollections[0]->id],
    ])->assertForbidden();
    $this->put(route('games.update', $this->game), [])->assertForbidden();

    expect($this->game->fresh()->load('collections', 'idealPlayerCounts')->toArray())->toBe($before);
})->with(['foreign collection' => true, 'uncollected' => false]);

test('editing and updating games requires authentication', function () {
    $this->get(route('games.edit', $this->game))->assertRedirect(route('login'));
    $this->put(route('games.update', $this->game), $this->payload)->assertRedirect(route('login'));
});
