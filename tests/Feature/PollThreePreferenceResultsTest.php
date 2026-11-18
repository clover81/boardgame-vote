<?php

use App\Models\Game;
use App\Models\Poll;
use App\Models\User;

test('los resultados 3 2 1 respetan empates y ganadores en ambas vistas', function () {
    $this->withoutVite();

    $owner = User::factory()->create();

    $poll = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Sesión con tres preferencias',
        'token' => 'THREERESULTS',
        'status' => 'closed',
        'ranking_slots' => 3,
        'single_choice' => false,
    ]);

    $candidates = collect(['Juego A', 'Juego B', 'Juego C'])
        ->map(function ($name) use ($poll) {
            $game = Game::create([
                'name' => $name,
                'min_players' => 1,
                'max_players' => 5,
                'game_mode' => 'competitive',
                'is_active' => true,
            ]);

            return $poll->candidates()->create([
                'game_id' => $game->id,
            ]);
        });

    $addBallot = function (string $name, array $order) use ($poll, $candidates) {
        $participant = $poll->participants()->create([
            'display_name' => $name,
        ]);

        foreach ($order as $position => $index) {
            $participant->votes()->create([
                'poll_id' => $poll->id,
                'poll_candidate_id' => $candidates[$index]->id,
                'position' => $position + 1,
            ]);
        }

        return $participant;
    };

    $first = $addBallot('Ana', [0, 1, 2]);
    $addBallot('Bea', [1, 0, 2]);

    $publicUrl = route('vote.participant.show', [
        'token' => $poll->token,
        'voteToken' => $first->vote_token,
    ]);

    // A y B tienen 5 puntos y una primera opción cada uno.
    // Es un empate real: clasificación 1, 1, 3.
    $public = $this->get($publicUrl)->assertOk();
    $results = $public->inertiaProps('results');

    expect(array_column($results, 'points'))->toBe([5, 5, 2])
        ->and(array_column($results, 'rank'))->toBe([1, 1, 3])
        ->and(array_column($results, 'is_top_tie'))
            ->toBe([true, true, false])
        ->and($public->inertiaProps('poll')['has_clear_winner'])
            ->toBeFalse();

    $private = $this->actingAs($owner)
        ->get(route('polls.show', $poll))
        ->assertOk();

    expect($private->inertiaProps('results'))->toBe($results);

    // Añadir un tercer votante resuelve el empate:
    // A: 8 puntos, B: 6, C: 4.
    $addBallot('Carlos', [0, 2, 1]);

    $public = $this->get($publicUrl)->assertOk();
    $results = $public->inertiaProps('results');

    expect(array_column($results, 'points'))->toBe([8, 6, 4])
        ->and(array_column($results, 'rank'))->toBe([1, 2, 3])
        ->and(array_column($results, 'is_winner'))
            ->toBe([true, false, false])
        ->and($public->inertiaProps('poll')['has_clear_winner'])
            ->toBeTrue();

    $private = $this->get(route('polls.show', $poll))->assertOk();

    expect($private->inertiaProps('results'))->toBe($results);
});
