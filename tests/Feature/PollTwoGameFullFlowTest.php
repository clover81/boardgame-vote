<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;

test('dos juegos tienen votos resultados y partidas independientes', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $collection = GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Colección de la sesión',
        'is_default' => true,
    ]);

    $players = collect(['Ana', 'Bea', 'Carlos'])
        ->map(fn ($name) => Player::create([
            'created_by' => $owner->id,
            'name' => $name,
            'is_active' => true,
        ]));

    $games = collect(['White Castle', 'Heat', 'Cascadia', 'Sky Team', 'Azul'])
        ->map(fn ($name) => Game::create([
            'name' => $name,
            'min_players' => 1,
            'max_players' => 5,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]));

    $collection->games()->attach($games->pluck('id')->all());

    $this->actingAs($owner)->post(route('polls.store'), [
        'name' => 'Sábado jugón',
        'collection_ids' => [$collection->id],
        'player_ids' => $players->pluck('id')->all(),
        'candidate_ids' => $games->take(4)->pluck('id')->all(),
        'ranking_slots' => 3,
        'create_second_game' => true,
    ])->assertSessionHasNoErrors();

    $first = Poll::sole();

    $this->post(route('polls.store'), [
        'name' => 'Sábado jugón · Juego 2',
        'collection_ids' => [$collection->id],
        'player_ids' => [$players[0]->id, $players[2]->id],
        'candidate_ids' => [
            $games[4]->id,
            $games[3]->id,
            $games[2]->id,
        ],
        'ranking_slots' => 2,
        'parent_poll_id' => $first->id,
    ])->assertSessionHasNoErrors();

    $second = Poll::where('parent_poll_id', $first->id)->sole();

    expect($first->ranking_slots)->toBe(3)
        ->and($second->ranking_slots)->toBe(2)
        ->and($first->participants()->count())->toBe(3)
        ->and($second->participants()->count())->toBe(2)
        ->and($first->token)->not->toBe($second->token);

    foreach ([$first, $second] as $poll) {
        $this->patch(route('polls.open', $poll))
            ->assertSessionHasNoErrors();

        expect($poll->fresh()->status)->toBe('open');
    }

    $firstParticipant = $first->participants()
        ->where('player_id', $players[0]->id)
        ->firstOrFail();

    $secondParticipant = $second->participants()
        ->where('player_id', $players[0]->id)
        ->firstOrFail();

    expect($firstParticipant->vote_token)
        ->not->toBe($secondParticipant->vote_token);

    $firstCandidates = $first->candidates()
        ->orderBy('id')->pluck('id')->all();

    $secondCandidates = $second->candidates()
        ->orderBy('id')->pluck('id')->all();

    $firstVoteUrl = route('vote.participant.store', [
        'token' => $first->token,
        'voteToken' => $firstParticipant->vote_token,
    ]);

    $secondVoteUrl = route('vote.participant.store', [
        'token' => $second->token,
        'voteToken' => $secondParticipant->vote_token,
    ]);

    $this->post($firstVoteUrl, [
        'first_candidate_id' => $firstCandidates[0],
        'second_candidate_id' => $firstCandidates[1],
        'third_candidate_id' => $firstCandidates[2],
        'ranking_slots' => 3,
    ])->assertSessionHasNoErrors();

    $this->post($secondVoteUrl, [
        'first_candidate_id' => $secondCandidates[0],
        'second_candidate_id' => $secondCandidates[1],
        'ranking_slots' => 2,
    ])->assertSessionHasNoErrors();

    expect($first->votes()->count())->toBe(3)
        ->and($second->votes()->count())->toBe(2);

    foreach ([$first, $second] as $poll) {
        $this->patch(route('polls.close', $poll))
            ->assertSessionHasNoErrors();

        expect($poll->fresh()->status)->toBe('closed');
    }

    $firstResult = $this->get(route('polls.show', $first))
        ->assertOk()->inertiaProps('results');

    $secondResult = $this->get(route('polls.show', $second))
        ->assertOk()->inertiaProps('results');

    expect($firstResult[0]['game']['id'])->toBe($games[0]->id)
        ->and($firstResult[0]['points'])->toBe(3)
        ->and($secondResult[0]['game']['id'])->toBe($games[4]->id)
        ->and($secondResult[0]['points'])->toBe(2);

    $this->get(route('plays.create-from-poll', $first))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('defaults.game_ids', [$games[0]->id])
        );

    $this->get(route('plays.create-from-poll', $second))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('defaults.game_ids', [$games[4]->id])
            ->where('defaults.player_ids', [
                $players[0]->id,
                $players[2]->id,
            ])
        );

    $this->post(route('plays.store'), [
        'poll_id' => $first->id,
        'game_ids' => [$games[0]->id],
        'player_ids' => $players->pluck('id')->all(),
        'played_at' => '2026-11-17',
    ])->assertSessionHasNoErrors();

    $this->post(route('plays.store'), [
        'poll_id' => $second->id,
        'game_ids' => [$games[4]->id],
        'player_ids' => [$players[0]->id, $players[2]->id],
        'played_at' => '2026-11-17',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseCount('plays', 2);

    $firstPlay = Play::where('poll_id', $first->id)->sole();
    $secondPlay = Play::where('poll_id', $second->id)->sole();

    expect($firstPlay->game_id)->toBe($games[0]->id)
        ->and($secondPlay->game_id)->toBe($games[4]->id)
        ->and($firstPlay->players->modelKeys())
        ->toEqualCanonicalizing($players->pluck('id')->all())
        ->and($secondPlay->players->modelKeys())
        ->toEqualCanonicalizing([
            $players[0]->id,
            $players[2]->id,
        ]);
});
