<?php

use App\Models\Game;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Partida del domingo',
        'token' => 'POLLTEST',
        'status' => 'closed',
    ]);
    $this->games = collect(['winner', 'candidate', 'outside'])->mapWithKeys(
        fn ($name) => [$name => Game::create([
            'name' => $name,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
        ])]
    );
    // Insert the losing candidate first, so the winner must come from the ranking.
    $candidate = $this->poll->candidates()->create(['game_id' => $this->games['candidate']->id]);
    $winner = $this->poll->candidates()->create(['game_id' => $this->games['winner']->id]);
    $participant = $this->poll->participants()->create(['display_name' => 'Participante original']);
    $participant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $winner->id,
        'position' => 1,
    ]);
    $participant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $candidate->id,
        'position' => 2,
    ]);
});

test('registered play data reflects the actual game expansion players and vote outcome', function ($chosenGame, $wasCandidate, $wasWinner) {
    $game = $this->games[$chosenGame];
    $player = Player::create(['name' => 'Jugador real', 'is_active' => true]);
    $expansion = $game->expansions()->create(['name' => 'Expansión realmente jugada']);
    $play = Play::create([
        'created_by' => $this->user->id,
        'poll_id' => $this->poll->id,
        'game_id' => $game->id,
        'expansion_id' => $expansion->id,
        'played_at' => '2026-10-25',
    ]);
    $play->players()->attach($player->id);

    $this->actingAs($this->user)->get(route('polls.show', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('results.0.game.id', $this->games['winner']->id)
            ->where('poll.has_clear_winner', true)
            ->where('poll.plays', [[
                'id' => $play->id,
                'played_at' => '2026-10-25',
                'game' => ['id' => $game->id, 'name' => $game->name],
                'expansion' => ['id' => $expansion->id, 'name' => $expansion->name],
                'players' => [['id' => $player->id, 'name' => 'Jugador real']],
            ]])
            ->where('poll.registered_play', [
                'id' => $play->id,
                'played_at' => '2026-10-25',
                'game' => ['id' => $game->id, 'name' => $game->name, 'image_url' => null],
                'expansion' => ['id' => $expansion->id, 'name' => $expansion->name],
                'players' => [['id' => $player->id, 'name' => 'Jugador real']],
                'was_candidate' => $wasCandidate,
                'was_winner' => $wasWinner,
            ])
        );
})->with([
    'winner' => ['winner', true, true],
    'another candidate' => ['candidate', true, false],
    'outside the candidates' => ['outside', false, false],
]);

test('a poll without a linked play returns null even when manual plays exist', function () {
    Play::create([
        'created_by' => $this->user->id,
        'game_id' => $this->games['winner']->id,
        'played_at' => '2026-10-25',
    ]);

    $this->actingAs($this->user)->get(route('polls.show', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('poll.registered_play', null)
            ->where('poll.plays', [])
        );
});

test('registered plays without an expansion or ranked candidates have safe defaults', function () {
    $this->poll->candidates()->delete();
    Play::create([
        'created_by' => $this->user->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->games['outside']->id,
        'played_at' => '2026-10-25',
    ]);

    $this->actingAs($this->user)->get(route('polls.show', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('results', [])
            ->where('poll.registered_play.expansion', null)
            ->where('poll.registered_play.players', [])
            ->where('poll.plays.0.players', [])
            ->where('poll.registered_play.was_candidate', false)
            ->where('poll.registered_play.was_winner', false)
        );
});

test('another user cannot view the poll or its registered play', function () {
    Play::create([
        'created_by' => $this->user->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->games['winner']->id,
        'played_at' => '2026-10-25',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('polls.show', $this->poll))
        ->assertForbidden();
});
