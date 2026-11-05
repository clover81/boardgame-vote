<?php

use App\Models\Game;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->poll = Poll::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Enlace personal',
        'token' => 'PERSONAL',
        'status' => 'open',
    ]);
    $this->participant = $this->poll->participants()->create([
        'display_name' => 'Alejandro',
        'vote_token' => str_repeat('a', 40),
    ]);
    $game = Game::create([
        'name' => 'Sky Team',
        'min_players' => 2,
        'max_players' => 2,
        'game_mode' => 'cooperative',
        'short_description' => 'Aterriza en equipo.',
    ]);
    $this->secondGame = Game::create([
        'name' => 'Heat',
        'min_players' => 1,
        'max_players' => 6,
        'game_mode' => 'competitive',
    ]);
    $this->candidate = $this->poll->candidates()->create([
        'game_id' => $game->id,
        'note' => 'Primera partida',
    ]);
    $this->participant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $this->candidate->id,
        'position' => 1,
    ]);
    $this->personalUrl = route('vote.participant.show', [$this->poll->token, $this->participant->vote_token]);
    $this->voteUrl = route('vote.participant.store', [$this->poll->token, $this->participant->vote_token]);
    $this->originalVotes = $this->participant->votes()->get()->toArray();
});

test('a personal link stores one vote for its participant regardless of the submitted participant ID', function ($secondChoice) {
    $otherParticipant = $this->poll->participants()->create(['display_name' => 'Ana']);
    $otherVote = $otherParticipant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $this->candidate->id,
        'position' => 1,
    ]);
    $payload = [
        'participant_id' => $otherParticipant->id,
        'first_candidate_id' => (string) $this->candidate->id,
    ];
    if ($secondChoice !== 'omitted') {
        $payload['second_candidate_id'] = $secondChoice === 'same' ? $this->candidate->id : null;
    }

    foreach ([1, 2] as $attempt) {
        $this->from($this->personalUrl)->post($this->voteUrl, $payload)
            ->assertRedirect($this->personalUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Voto guardado correctamente.');
    }

    $this->assertDatabaseCount('votes', 2);
    $this->assertDatabaseHas('votes', [
        'poll_id' => $this->poll->id,
        'poll_participant_id' => $this->participant->id,
        'poll_candidate_id' => $this->candidate->id,
        'position' => 1,
    ]);
    $this->assertDatabaseHas('votes', $otherVote->getAttributes());
})->with(['omitted', 'null', 'same']);

test('a personal link replaces both choices without requiring a participant ID in the payload', function () {
    $secondCandidate = $this->poll->candidates()->create(['game_id' => $this->secondGame->id]);

    foreach ([1, 2] as $attempt) {
        $this->from($this->personalUrl)->post($this->voteUrl, [
            'first_candidate_id' => (string) $secondCandidate->id,
            'second_candidate_id' => (string) $this->candidate->id,
        ])->assertRedirect($this->personalUrl)->assertSessionHasNoErrors();
    }

    $this->assertDatabaseCount('votes', 2);
    foreach ([$secondCandidate, $this->candidate] as $index => $candidate) {
        $this->assertDatabaseHas('votes', [
            'poll_id' => $this->poll->id,
            'poll_participant_id' => $this->participant->id,
            'poll_candidate_id' => $candidate->id,
            'position' => $index + 1,
        ]);
    }
});

test('invalid choices on a personal link preserve the previous ballot', function ($payload, $errorField) {
    $this->poll->candidates()->create(['game_id' => $this->secondGame->id]);
    $payload = array_replace(['first_candidate_id' => $this->candidate->id], $payload);
    if (($payload['second_candidate_id'] ?? null) === 'same') {
        $payload['second_candidate_id'] = (string) $this->candidate->id;
    }

    $this->from($this->personalUrl)->post($this->voteUrl, $payload)
        ->assertRedirect($this->personalUrl)->assertSessionHasErrors($errorField);

    expect($this->participant->votes()->get()->toArray())->toBe($this->originalVotes);
})->with([
    'missing first' => [['first_candidate_id' => null], 'first_candidate_id'],
    'invalid first' => [['first_candidate_id' => 'bad'], 'first_candidate_id'],
    'missing second' => [[], 'second_candidate_id'],
    'null second' => [['second_candidate_id' => null], 'second_candidate_id'],
    'invalid second' => [['second_candidate_id' => 'bad'], 'second_candidate_id'],
    'same choice' => [['second_candidate_id' => 'same'], 'second_candidate_id'],
]);

test('personal voting rejects candidates outside the poll without changing votes', function ($field, $foreign) {
    $secondCandidate = $this->poll->candidates()->create(['game_id' => $this->secondGame->id]);
    $otherPoll = Poll::create([
        'created_by' => $this->poll->created_by,
        'name' => 'Otra votación',
        'token' => 'FOREIGN',
        'status' => 'open',
    ]);
    $foreignCandidate = $otherPoll->candidates()->create(['game_id' => $this->candidate->game_id]);
    $payload = [
        'first_candidate_id' => $this->candidate->id,
        'second_candidate_id' => $secondCandidate->id,
    ];
    $payload[$field] = $foreign ? $foreignCandidate->id : $foreignCandidate->id + 1000;

    $this->post($this->voteUrl, $payload)->assertStatus(422);

    expect($this->participant->votes()->get()->toArray())->toBe($this->originalVotes);
})->with([
    'foreign first' => ['first_candidate_id', true],
    'unknown first' => ['first_candidate_id', false],
    'foreign second' => ['second_candidate_id', true],
    'unknown second' => ['second_candidate_id', false],
]);

test('personal voting rejects closed and draft polls without changing votes', function ($status) {
    $this->poll->update(['status' => $status]);

    $this->from($this->personalUrl)->post($this->voteUrl, ['first_candidate_id' => $this->candidate->id])
        ->assertRedirect($this->personalUrl)
        ->assertSessionHasErrors(['vote' => 'La votación ya no está abierta.']);

    expect($this->participant->votes()->get()->toArray())->toBe($this->originalVotes);
})->with(['closed', 'draft']);

test('personal voting rejects invalid poll and participant tokens without changing votes', function ($token, $voteToken, $status = 'open') {
    $this->poll->update(['status' => $status]);

    $this->post(route('vote.participant.store', [$token, $voteToken]), [
        'first_candidate_id' => $this->candidate->id,
    ])->assertNotFound();

    expect($this->participant->votes()->get()->toArray())->toBe($this->originalVotes);
})->with([
    'unknown poll' => ['UNKNOWN', str_repeat('a', 40)],
    'unknown participant' => ['PERSONAL', str_repeat('b', 40)],
    'closed poll with unknown participant' => ['PERSONAL', str_repeat('b', 40), 'closed'],
    'draft poll with unknown participant' => ['PERSONAL', str_repeat('b', 40), 'draft'],
]);

test('a personal link identifies its participant and preserves the public page data', function ($status) {
    $this->poll->update(['status' => $status]);

    $this->get(route('vote.participant.show', [$this->poll->token, $this->participant->vote_token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vote/show')
            ->where('currentParticipantId', $this->participant->id)
            ->where('voteToken', $this->participant->vote_token)
            ->where('poll.participants.0.votes.0.candidate_id', $this->candidate->id)
            ->where('poll.candidates.0.note', 'Primera partida')
            ->where('poll.candidates.0.game.short_description', 'Aterriza en equipo.')
            ->where('poll.candidates.0.history.total_plays', 0)
            ->where('poll.has_clear_winner', $status === 'closed')
            ->has('results', $status === 'closed' ? 1 : 0)
        );
})->with(['open', 'closed']);

test('personal links show group names without revealing other participants votes', function ($name, $status) {
    $this->poll->update(['status' => $status]);
    $ana = $this->poll->participants()->create([
        'display_name' => 'Ana',
        'vote_token' => str_repeat('b', 40),
    ]);
    $secondCandidate = $this->poll->candidates()->create(['game_id' => $this->secondGame->id]);
    $this->participant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $secondCandidate->id,
        'position' => 2,
    ]);
    $ana->votes()->createMany([
        ['poll_id' => $this->poll->id, 'poll_candidate_id' => $secondCandidate->id, 'position' => 1],
        ['poll_id' => $this->poll->id, 'poll_candidate_id' => $this->candidate->id, 'position' => 2],
    ]);

    $participant = $name === 'Ana' ? $ana : $this->participant;
    $otherParticipant = $name === 'Ana' ? $this->participant : $ana;
    $votesBefore = $this->poll->votes()->orderBy('id')->get()->toArray();
    $expectedVotes = $participant->votes()->get()->map(fn ($vote) => [
        'candidate_id' => $vote->poll_candidate_id,
        'position' => $vote->position,
    ])->all();

    $response = $this->get(route('vote.participant.show', [$this->poll->token, $participant->vote_token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vote/show')
            ->where('currentParticipantId', $participant->id)
            ->where('voteToken', $participant->vote_token)
            ->where('poll.participants', function ($participants) use ($participant, $expectedVotes) {
                $visible = collect($participants);
                $own = $visible->firstWhere('id', $participant->id);

                return $visible->count() === 2
                    && $own !== null
                    && $own['display_name'] === $participant->display_name
                    && $own['votes'] === $expectedVotes
                    && $visible->every(function ($entry) use ($participant) {
                        return array_diff(
                            array_keys($entry),
                            ['id', 'display_name', 'votes']
                        ) === []
                        && (
                            $entry['id'] === $participant->id
                            || $entry['votes'] === []
                        );
                    });
            })
            ->has('poll.candidates', 2)
            ->where('poll.candidates.0.history.participants', function ($history) use ($participant, $otherParticipant) {
                $players = collect($history);

                return $players->count() === 2
                    && $players->pluck('display_name', 'participant_id')->all() == [
                        $participant->id => $participant->display_name,
                        $otherParticipant->id => $otherParticipant->display_name,
                    ];
            })
            ->where('poll.candidates.1.history.participants', function ($history) use ($participant, $otherParticipant) {
                $players = collect($history);

                return $players->count() === 2
                    && $players->pluck('display_name', 'participant_id')->all() == [
                        $participant->id => $participant->display_name,
                        $otherParticipant->id => $otherParticipant->display_name,
                    ];
            })
            ->has('results', $status === 'closed' ? 2 : 0)
        );

    $response->assertDontSee($otherParticipant->vote_token, false);
    expect($this->poll->votes()->orderBy('id')->get()->toArray())->toBe($votesBefore);

    if ($status === 'closed') {
        foreach ($response->inertiaProps('results') as $result) {
            expect($result['first_votes'])->toBe(1)
                ->and($result['second_votes'])->toBe(1);
        }
    }
})->with([
    'Alejandro open' => ['Alejandro', 'open'],
    'Ana open' => ['Ana', 'open'],
    'Alejandro closed' => ['Alejandro', 'closed'],
    'Ana closed' => ['Ana', 'closed'],
]);

test('a personal link rejects unknown poll and participant tokens', function ($token, $voteToken) {
    $this->get(route('vote.participant.show', [$token, $voteToken]))->assertNotFound();
})->with([
    'unknown poll' => ['UNKNOWN', str_repeat('a', 40)],
    'unknown participant' => ['PERSONAL', str_repeat('b', 40)],
]);

test('a personal link cannot use the token of a participant from another poll', function () {
    $otherPoll = Poll::create([
        'created_by' => $this->poll->created_by,
        'name' => 'Otra votación',
        'token' => 'OTHERPERSONAL',
        'status' => 'open',
    ]);

    $this->get(route('vote.participant.show', [$otherPoll->token, $this->participant->vote_token]))
        ->assertNotFound();

    $this->get('/vote/'.$this->poll->token)->assertNotFound();
});
