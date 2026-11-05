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
    $collection = GameCollection::create(['created_by' => $this->user->id, 'name' => 'Mi colección']);
    $collection->games()->attach($this->game);
    $this->expansion = $this->game->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $this->player = Player::create([
        'created_by' => $this->user->id,
        'name' => 'Ana',
        'is_active' => true,
    ]);
    $this->poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Partida del domingo',
        'token' => 'DELETEPLAY',
        'status' => 'closed',
    ]);
    $candidate = $this->poll->candidates()->create(['game_id' => $this->game->id]);
    $participant = $this->poll->participants()->create(['player_id' => $this->player->id, 'display_name' => 'Ana']);
    $participant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $candidate->id,
        'position' => 1,
    ]);
    $this->play = Play::create([
        'created_by' => $this->user->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->game->id,
        'expansion_id' => $this->expansion->id,
        'played_at' => '2026-10-25',
    ]);
    $this->play->players()->attach($this->player);
    $this->otherPlay = Play::create([
        'created_by' => $this->user->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-10-24',
    ]);
    $this->otherPlay->players()->attach($this->player);
});

test('deleting a play preserves related records and makes its closed poll available for registration again', function () {
    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('summary.pending_plays_count', 0));

    $this->delete(route('plays.destroy', $this->play))
        ->assertRedirect(route('plays.index'))
        ->assertSessionHas('success', 'Partida eliminada correctamente.');

    $this->assertDatabaseMissing('plays', ['id' => $this->play->id]);
    $this->assertDatabaseMissing('play_player', ['play_id' => $this->play->id]);
    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
    $this->assertDatabaseHas('expansions', ['id' => $this->expansion->id]);
    $this->assertDatabaseHas('players', ['id' => $this->player->id]);
    $this->assertDatabaseHas('polls', ['id' => $this->poll->id, 'status' => 'closed']);
    $this->assertDatabaseCount('poll_candidates', 1);
    $this->assertDatabaseCount('poll_participants', 1);
    $this->assertDatabaseCount('votes', 1);
    expect($this->otherPlay->fresh()->players->modelKeys())->toBe([$this->player->id]);
    expect($this->poll->fresh()->play)->toBeNull();

    $this->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.pending_plays_count', 1)
            ->where('pendingPlays.0.id', $this->poll->id));
    $this->get(route('polls.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('polls.0.has_registered_play', false));
    $this->get(route('polls.show', $this->poll))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('poll.registered_play', null));
    $this->get(route('plays.create-from-poll', $this->poll))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('plays/create'));

    $this->post(route('plays.store'), [
        'poll_id' => $this->poll->id,
        'game_ids' => [$this->game->id],
        'player_ids' => [$this->player->id],
        'played_at' => '2026-10-25',
    ])->assertSessionHasNoErrors()->assertRedirect(route('polls.show', $this->poll));
    expect($this->poll->fresh()->plays()->exists())->toBeTrue();
    $this->assertDatabaseCount('plays', 2);
});

test('manual plays can be deleted without affecting other plays or polls', function () {
    $this->actingAs($this->user)->delete(route('plays.destroy', $this->otherPlay))
        ->assertRedirect(route('plays.index'));

    $this->assertDatabaseMissing('plays', ['id' => $this->otherPlay->id]);
    $this->assertDatabaseMissing('play_player', ['play_id' => $this->otherPlay->id]);
    expect($this->play->fresh()->players->modelKeys())->toBe([$this->player->id]);
    expect($this->poll->fresh()->play->id)->toBe($this->play->id);
    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
});

test('another user cannot delete a play or detach its players', function () {
    $this->actingAs(User::factory()->create())->delete(route('plays.destroy', $this->play))
        ->assertForbidden();

    expect($this->play->fresh()->players->modelKeys())->toBe([$this->player->id]);
    expect($this->poll->fresh()->play->id)->toBe($this->play->id);
    $this->assertDatabaseCount('plays', 2);
    $this->assertDatabaseCount('play_player', 2);
});

test('deleting a play requires authentication', function () {
    $this->delete(route('plays.destroy', $this->play))->assertRedirect(route('login'));

    $this->assertDatabaseCount('plays', 2);
    $this->assertDatabaseCount('play_player', 2);
});
