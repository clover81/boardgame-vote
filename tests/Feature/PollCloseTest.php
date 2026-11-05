<?php

use App\Models\Game;
use App\Models\Poll;
use App\Models\User;

test('closing requires at least one complete ballot for the number of candidates', function (
    $candidateCount,
    $ballots,
    $canClose
) {
    $owner = User::factory()->create();
    $poll = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Cerrar votación',
        'token' => 'CLOSETEST',
        'status' => 'open',
    ]);
    $candidates = collect();

    for ($index = 0; $index < $candidateCount; $index++) {
        $game = Game::create([
            'name' => 'Juego '.$index,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
        ]);
        $candidates->push($poll->candidates()->create(['game_id' => $game->id]));
    }

    foreach ($ballots as $index => $positions) {
        $participant = $poll->participants()->create(['display_name' => 'Jugador '.$index]);

        foreach ($positions as $position) {
            $participant->votes()->create([
                'poll_id' => $poll->id,
                'poll_candidate_id' => $candidates[min($position - 1, $candidateCount - 1)]->id,
                'position' => $position,
            ]);
        }
    }

    $response = $this->actingAs($owner)
        ->from(route('polls.show', $poll))
        ->patch(route('polls.close', $poll));

    $response->assertRedirect(route('polls.show', $poll));

    if ($canClose) {
        $response->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Votación cerrada. Ya puedes ver los resultados.');
        expect($poll->fresh()->status)->toBe('closed');
    } else {
        $response->assertSessionHasErrors([
            'poll' => $candidateCount === 0
                ? 'La votación no tiene juegos candidatos.'
                : 'Todavía no ha completado su voto ningún participante.',
        ]);
        expect($poll->fresh()->status)->toBe('open');
    }
})->with([
    'one candidate and a first choice with a pending participant' => [1, [[1], []], true],
    'one candidate without votes' => [1, [[], []], false],
    'one candidate with only a second choice' => [1, [[2], []], false],
    'two candidates with a complete ballot and a pending participant' => [2, [[1, 2], []], true],
    'two votes without the required second position' => [2, [[1, 3], []], false],
    'multiple candidates with a complete ballot and pending participants' => [3, [[1, 2], [1], []], true],
    'multiple candidates without votes' => [3, [[], []], false],
    'multiple candidates with only a first choice' => [3, [[1], []], false],
    'first and second choices from different participants are not a complete ballot' => [3, [[1], [2]], false],
    'two votes without the required first position' => [3, [[2, 3], []], false],
    'four candidates with a complete ballot and a pending participant' => [4, [[1, 2], []], true],
    'no candidates or votes' => [0, [[], []], false],
]);
