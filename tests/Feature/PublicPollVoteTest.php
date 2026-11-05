<?php

use App\Models\Game;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $owner = User::factory()->create();
    $this->poll = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Votación abierta',
        'token' => 'PUBLICVOTE',
        'status' => 'open',
    ]);
    $this->otherPoll = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Otra votación',
        'token' => 'OTHERVOTE',
        'status' => 'open',
    ]);
    $this->candidates = collect(['White Castle', 'Heat', 'Dune'])
        ->map(function ($name) {
            $game = Game::create([
                'name' => $name,
                'min_players' => 1,
                'max_players' => 4,
                'game_mode' => 'competitive',
            ]);

            return $this->poll->candidates()->create(['game_id' => $game->id]);
        });
    $this->participant = $this->poll->participants()->create(['display_name' => 'Ana']);
    $this->payload = [
        'first_candidate_id' => $this->candidates[0]->id,
        'second_candidate_id' => $this->candidates[1]->id,
    ];
    $this->voteUrl = route('vote.participant.store', [
        'token' => $this->poll->token,
        'voteToken' => $this->participant->vote_token,
    ]);
    $this->publicUrl = route('vote.participant.show', [
        'token' => $this->poll->token,
        'voteToken' => $this->participant->vote_token,
    ]);
    $this->participant->votes()->createMany([
        ['poll_id' => $this->poll->id, 'poll_candidate_id' => $this->candidates[0]->id, 'position' => 1],
        ['poll_id' => $this->poll->id, 'poll_candidate_id' => $this->candidates[1]->id, 'position' => 2],
    ]);
    $this->originalVotes = $this->participant->votes()->orderBy('position')->get()->toArray();
});

test('a public vote stores both choices with the poll and participant IDs', function () {
    $this->participant->votes()->delete();

    $this->from($this->publicUrl)->post($this->voteUrl, $this->payload)
        ->assertRedirect($this->publicUrl)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Voto guardado correctamente.');

    $this->assertDatabaseCount('votes', 2);

    foreach ([1, 2] as $position) {
        $this->assertDatabaseHas('votes', [
            'poll_id' => $this->poll->id,
            'poll_participant_id' => $this->participant->id,
            'poll_candidate_id' => $this->candidates[$position - 1]->id,
            'position' => $position,
        ]);
    }

    $this->withoutVite();
    $this->actingAs($this->poll->creator)->get(route('polls.show', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('poll.required_votes', 2)
            ->where('poll.voted_participants_count', 1)
            ->where('poll.participants.0.has_voted', true)
        );
});

test('changing a vote replaces both choices without changing another participants votes', function () {
    $otherParticipant = $this->poll->participants()->create(['display_name' => 'Bea']);
    $otherVote = $otherParticipant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $this->candidates[0]->id,
        'position' => 1,
    ]);
    $payload = array_replace($this->payload, [
        'first_candidate_id' => (string) $this->candidates[1]->id,
        'second_candidate_id' => (string) $this->candidates[2]->id,
    ]);

    // Repeating the same submission must not create duplicate votes.
    foreach ([1, 2] as $attempt) {
        $this->from($this->publicUrl)->post($this->voteUrl, $payload)
            ->assertRedirect($this->publicUrl)
            ->assertSessionHasNoErrors();
    }

    expect($this->participant->votes()->orderBy('position')->pluck('poll_candidate_id')->all())
        ->toBe([$this->candidates[1]->id, $this->candidates[2]->id]);
    $this->assertDatabaseCount('votes', 3);
    $this->assertDatabaseHas('votes', $otherVote->getAttributes());
});

test('closed and draft polls reject votes without removing earlier choices', function ($status) {
    $this->poll->update(['status' => $status]);

    $this->from($this->publicUrl)->post($this->voteUrl, $this->payload)
        ->assertRedirect($this->publicUrl)
        ->assertSessionHasErrors(['vote' => 'La votación ya no está abierta.']);

    expect($this->participant->votes()->orderBy('position')->get()->toArray())->toBe($this->originalVotes);
})->with(['closed', 'draft']);

test('foreign or nonexistent participants return not found without changing votes', function ($foreign) {
    $voteToken = $foreign
        ? $this->otherPoll->participants()->create(['display_name' => 'Otro participante'])->vote_token
        : str_repeat('x', 40);

    $voteUrl = route('vote.participant.store', [
        'token' => $this->poll->token,
        'voteToken' => $voteToken,
    ]);

    $this->from($this->publicUrl)->post($voteUrl, $this->payload)
        ->assertNotFound();

    expect($this->participant->votes()->orderBy('position')->get()->toArray())->toBe($this->originalVotes);
})->with(['foreign participant' => [true], 'nonexistent participant' => [false]]);

test('foreign or nonexistent candidates return not found without changing votes', function ($field, $foreign) {
    $candidateId = $foreign
        ? $this->otherPoll->candidates()->create(['game_id' => $this->candidates[0]->game_id])->id
        : $this->candidates->last()->id + 1000;

    $this->from($this->publicUrl)->post($this->voteUrl, array_replace($this->payload, [
        $field => $candidateId,
    ]))
        ->assertUnprocessable();

    expect($this->participant->votes()->orderBy('position')->get()->toArray())->toBe($this->originalVotes);
})->with([
    'foreign first choice' => ['first_candidate_id', true],
    'foreign second choice' => ['second_candidate_id', true],
    'nonexistent first choice' => ['first_candidate_id', false],
    'nonexistent second choice' => ['second_candidate_id', false],
]);

test('both choices must be different and invalid submissions preserve earlier votes', function ($firstAsString, $secondAsString) {
    $candidateId = $this->payload['first_candidate_id'];
    $payload = array_replace($this->payload, [
        'first_candidate_id' => $firstAsString ? (string) $candidateId : $candidateId,
        'second_candidate_id' => $secondAsString ? (string) $candidateId : $candidateId,
    ]);

    $this->from($this->publicUrl)->post($this->voteUrl, $payload)
        ->assertRedirect($this->publicUrl)
        ->assertSessionHasErrors([
            'second_candidate_id' => 'La primera y segunda opción deben ser distintas.',
        ]);

    expect($this->participant->votes()->orderBy('position')->get()->toArray())->toBe($this->originalVotes);
})->with([
    'integer IDs' => [false, false],
    'string IDs' => [true, true],
    'integer first and string second' => [false, true],
    'string first and integer second' => [true, false],
]);

test('a vote for an unknown token returns not found', function () {
    $voteUrl = route('vote.participant.store', [
        'token' => 'TOKENQUE_NO_EXISTE',
        'voteToken' => $this->participant->vote_token,
    ]);

    $this->post($voteUrl, $this->payload)->assertNotFound();

    expect($this->participant->votes()->orderBy('position')->get()->toArray())->toBe($this->originalVotes);
});

test('a poll without candidates rejects voting before validating choices and preserves other votes', function () {
    $otherCandidate = $this->otherPoll->candidates()->create([
        'game_id' => $this->candidates[0]->game_id,
    ]);
    $otherParticipant = $this->otherPoll->participants()->create(['display_name' => 'Bea']);
    $otherVote = $otherParticipant->votes()->create([
        'poll_id' => $this->otherPoll->id,
        'poll_candidate_id' => $otherCandidate->id,
        'position' => 1,
    ]);
    $this->poll->candidates()->delete();

    $this->from($this->publicUrl)->post($this->voteUrl, [])
        ->assertRedirect($this->publicUrl)
        ->assertSessionHasErrors(['vote' => 'La votación no tiene juegos candidatos.']);

    $this->assertDatabaseCount('votes', 1);
    $this->assertDatabaseHas('votes', $otherVote->getAttributes());
});

test('a poll with one candidate stores only the first choice even on resubmission', function ($secondChoice) {
    $this->poll->candidates()->whereKeyNot($this->candidates[0]->id)->delete();
    $payload = $this->payload;

    if ($secondChoice === 'omitted') {
        unset($payload['second_candidate_id']);
    } else {
        $payload['second_candidate_id'] = match ($secondChoice) {
            'null' => null,
            'same' => $this->candidates[0]->id,
            'unused' => $this->candidates[2]->id,
        };
    }

    foreach ([1, 2] as $attempt) {
        $this->from($this->publicUrl)->post($this->voteUrl, $payload)
            ->assertRedirect($this->publicUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Voto guardado correctamente.');
    }

    $this->assertDatabaseCount('votes', 1);
    $this->assertDatabaseHas('votes', [
        'poll_id' => $this->poll->id,
        'poll_participant_id' => $this->participant->id,
        'poll_candidate_id' => $this->candidates[0]->id,
        'position' => 1,
    ]);

    $this->withoutVite();
    $this->actingAs($this->poll->creator)->get(route('polls.show', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('poll.required_votes', 1)
            ->where('poll.voted_participants_count', 1)
            ->where('poll.participants.0.has_voted', true)
        );
})->with(['omitted', 'null', 'same', 'unused']);

test('a poll with multiple candidates still requires a second choice', function ($omitSecondChoice) {
    $payload = $this->payload;

    if ($omitSecondChoice) {
        unset($payload['second_candidate_id']);
    } else {
        $payload['second_candidate_id'] = null;
    }

    $this->from($this->publicUrl)->post($this->voteUrl, $payload)
        ->assertRedirect($this->publicUrl)
        ->assertSessionHasErrors('second_candidate_id');

    expect($this->participant->votes()->orderBy('position')->get()->toArray())->toBe($this->originalVotes);
})->with(['omitted' => [true], 'null' => [false]]);

test('a single candidate poll still rejects a first choice from another poll', function () {
    $this->poll->candidates()->whereKeyNot($this->candidates[0]->id)->delete();
    $foreignCandidate = $this->otherPoll->candidates()->create(['game_id' => $this->candidates[0]->game_id]);
    $originalVotes = $this->participant->votes()->get()->toArray();

    $this->from($this->publicUrl)->post($this->voteUrl, [
        'first_candidate_id' => $foreignCandidate->id,
    ])
        ->assertUnprocessable();

    expect($this->participant->votes()->get()->toArray())->toBe($originalVotes);
});
