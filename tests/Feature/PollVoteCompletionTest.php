<?php

use App\Models\Game;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('poll completion requires the expected vote positions', function ($candidateCount, $positions, $completed) {
    $this->withoutVite();
    $user = User::factory()->create();
    $poll = Poll::create([
        'created_by' => $user->id,
        'name' => 'Votación',
        'token' => 'COMPLETION',
        'status' => 'draft',
    ]);
    $participant = $poll->participants()->create([
        'display_name' => 'Ana',
        'vote_token' => str_repeat('a', 40),
    ]);
    $candidates = collect();

    for ($index = 0; $index < $candidateCount; $index++) {
        $game = Game::create([
            'name' => 'Juego ' . $index,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
        ]);
        $candidates->push($poll->candidates()->create(['game_id' => $game->id]));
    }

    foreach ($positions as $index => $position) {
        $participant->votes()->create([
            'poll_id' => $poll->id,
            'poll_candidate_id' => $candidates[$index]->id,
            'position' => $position,
        ]);
    }
    $pendingParticipant = $poll->participants()->create(['display_name' => 'Pendiente']);

    $this->actingAs($user)->get(route('polls.show', $poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('poll.candidate_count', $candidateCount)
            ->where('poll.required_vote_count', min(2, $candidateCount))
            ->where('poll.required_votes', min(2, $candidateCount))
            ->where('poll.voted_participants_count', $completed ? 1 : 0)
            ->where('poll.participants.0.has_voted', $completed)
            ->where('poll.participants.0.vote_token', $participant->vote_token)
            ->where('poll.participants.1.has_voted', false)
            ->where('poll.participants.1.vote_token', $pendingParticipant->vote_token)
        );
})->with([
    'no candidates' => [0, [], false],
    'one candidate without a vote' => [1, [], false],
    'one candidate with first choice' => [1, [1], true],
    'one candidate with only second choice' => [1, [2], false],
    'two candidates with only first choice' => [2, [1], false],
    'two candidates with both choices' => [2, [1, 2], true],
    'two votes but missing second choice' => [2, [1, 3], false],
    'three candidates with both choices' => [3, [1, 2], true],
    'two votes but missing first choice' => [3, [2, 3], false],
    'three candidates with extra position' => [3, [1, 2, 3], true],
    'four candidates with both choices' => [4, [1, 2], true],
]);
