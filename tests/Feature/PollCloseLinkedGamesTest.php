<?php

use App\Models\Game;
use App\Models\Poll;
use App\Models\User;

test('tres preferencias requieren un voto completo antes del cierre', function () {
    $owner = User::factory()->create();

    $poll = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Tres preferencias',
        'token' => 'CLOSETHREE',
        'status' => 'open',
        'ranking_slots' => 3,
        'single_choice' => false,
    ]);

    $candidates = collect(range(1, 4))->map(function ($number) use ($poll) {
        $game = Game::create([
            'name' => 'Juego '.$number,
            'min_players' => 1,
            'max_players' => 5,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]);

        return $poll->candidates()->create(['game_id' => $game->id]);
    });

    $participant = $poll->participants()->create([
        'display_name' => 'Ana',
    ]);

    foreach ([1, 2] as $position) {
        $participant->votes()->create([
            'poll_id' => $poll->id,
            'poll_candidate_id' => $candidates[$position - 1]->id,
            'position' => $position,
        ]);
    }

    $url = route('polls.close', $poll);

    $this->actingAs($owner)
        ->from(route('polls.show', $poll))
        ->patch($url)
        ->assertSessionHasErrors('poll');

    expect($poll->fresh()->status)->toBe('open');

    $participant->votes()->create([
        'poll_id' => $poll->id,
        'poll_candidate_id' => $candidates[2]->id,
        'position' => 3,
    ]);

    $this->from(route('polls.show', $poll))
        ->patch($url)
        ->assertSessionHasNoErrors();

    expect($poll->fresh()->status)->toBe('closed');
    $this->assertDatabaseCount('votes', 3);
});

test('cerrar el primer juego no cierra ni modifica el segundo', function () {
    $owner = User::factory()->create();

    $first = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Juego 1',
        'token' => 'CLOSEFIRST',
        'status' => 'open',
        'ranking_slots' => 1,
        'single_choice' => true,
    ]);

    $second = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Juego 2',
        'token' => 'CLOSESECOND',
        'status' => 'open',
        'parent_poll_id' => $first->id,
        'ranking_slots' => 1,
        'single_choice' => true,
    ]);

    foreach ([$first, $second] as $index => $poll) {
        $game = Game::create([
            'name' => 'Juego candidato '.($index + 1),
            'min_players' => 1,
            'max_players' => 5,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]);

        $candidate = $poll->candidates()->create([
            'game_id' => $game->id,
        ]);

        $participant = $poll->participants()->create([
            'display_name' => 'Ana',
        ]);

        if ($poll->id === $first->id) {
            $participant->votes()->create([
                'poll_id' => $poll->id,
                'poll_candidate_id' => $candidate->id,
                'position' => 1,
            ]);
        }
    }

    $this->actingAs($owner)
        ->patch(route('polls.close', $first))
        ->assertSessionHasNoErrors();

    expect($first->fresh()->status)->toBe('closed')
        ->and($second->fresh()->status)->toBe('open')
        ->and($first->votes()->count())->toBe(1)
        ->and($second->votes()->count())->toBe(0);

    $this->from(route('polls.show', $second))
        ->patch(route('polls.close', $second))
        ->assertSessionHasErrors('poll');

    expect($second->fresh()->status)->toBe('open');
});
