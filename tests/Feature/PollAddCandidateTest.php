<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Colección propia',
    ]);
    $this->poll = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Sábado',
        'token' => 'ADDCANDIDATE',
        'status' => 'draft',
    ]);
    $this->poll->gameCollections()->attach($this->collection);
    $this->existingGame = Game::create([
        'name' => 'Juego existente',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);
    $this->newGame = Game::create([
        'name' => 'Nuevo candidato',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);
    $this->collection->games()->attach([$this->existingGame->id, $this->newGame->id]);
    $this->existingCandidate = $this->poll->candidates()->create([
        'game_id' => $this->existingGame->id,
    ]);
});

test('draft and open polls accept an owned candidate without disturbing existing ones', function ($status) {
    $this->poll->update(['status' => $status]);

    $this->actingAs($this->owner)
        ->post(route('polls.candidates.store', $this->poll), ['game_id' => $this->newGame->id])
        ->assertRedirect(route('polls.show', $this->poll))
        ->assertSessionHasNoErrors();

    expect($this->poll->candidates()->count())->toBe(2);
    expect($this->poll->candidates()->where('game_id', $this->existingGame->id)->exists())->toBeTrue();
    expect($this->poll->candidates()->where('game_id', $this->newGame->id)->exists())->toBeTrue();
})->with(['draft', 'open']);

test('the owner sees only available games from the selected collections', function () {
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get(route('polls.show', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('canAddCandidates', true)
            ->has('availableGames', 1)
            ->where('availableGames.0.id', $this->newGame->id));
});

test('a candidate may not be added twice', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.candidates.store', $this->poll), ['game_id' => $this->existingGame->id])
        ->assertSessionHasErrors('game_id');

    expect($this->poll->candidates()->count())->toBe(1);
});

test('a game outside the polls selected collections or inactive is rejected', function ($case) {
    $game = $this->newGame;
    if ($case === 'other owned collection') {
        $collection = GameCollection::create([
            'created_by' => $this->owner->id,
            'name' => 'Otra colección',
        ]);
        $this->collection->games()->detach($game);
        $collection->games()->attach($game);
    } elseif ($case === 'foreign collection') {
        $collection = GameCollection::create([
            'created_by' => User::factory()->create()->id,
            'name' => 'Colección ajena',
        ]);
        $this->collection->games()->detach($game);
        $collection->games()->attach($game);
    } else {
        $game->update(['is_active' => false]);
    }

    $this->actingAs($this->owner)
        ->post(route('polls.candidates.store', $this->poll), ['game_id' => $game->id])
        ->assertSessionHasErrors('game_id');

    expect($this->poll->candidates()->count())->toBe(1);
})->with(['other owned collection', 'foreign collection', 'inactive']);

test('closed polls and polls with received votes cannot change their candidates', function ($case) {
    if ($case === 'closed') {
        $this->poll->update(['status' => 'closed']);
    } else {
        $this->poll->update(['status' => 'open']);
        $participant = $this->poll->participants()->create(['display_name' => 'Ana']);
        $participant->votes()->create([
            'poll_id' => $this->poll->id,
            'poll_candidate_id' => $this->existingCandidate->id,
            'position' => 1,
        ]);
    }

    $this->actingAs($this->owner)
        ->post(route('polls.candidates.store', $this->poll), ['game_id' => $this->newGame->id])
        ->assertSessionHasErrors('game_id');

    expect($this->poll->candidates()->count())->toBe(1);
})->with(['closed', 'already voted']);

test('a different user cannot alter the poll', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('polls.candidates.store', $this->poll), ['game_id' => $this->newGame->id])
        ->assertForbidden();

    expect($this->poll->candidates()->count())->toBe(1);
});
