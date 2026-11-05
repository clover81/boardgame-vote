<?php

use App\Models\Game;
use App\Models\Poll;
use App\Models\User;

test('opening a poll requires at least two participants and one candidate game', function ($participants, $candidates, $canOpen) {
    $user = User::factory()->create();
    $poll = Poll::create([
        'created_by' => $user->id,
        'name' => 'Partida del domingo',
        'token' => 'MINIMUMTEST',
        'status' => 'draft',
    ]);
    for ($index = 0; $index < $participants; $index++) {
        $poll->participants()->create(['display_name' => 'Jugador '.$index]);
    }
    for ($index = 0; $index < $candidates; $index++) {
        $game = Game::create([
            'name' => 'Juego '.$index,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]);
        $poll->candidates()->create(['game_id' => $game->id]);
    }

    $response = $this->actingAs($user)
        ->from(route('polls.show', $poll))
        ->patch(route('polls.open', $poll));

    $response->assertRedirect(route('polls.show', $poll));
    if ($canOpen) {
        $response->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Votación abierta correctamente.');
        expect($poll->fresh()->status)->toBe('open');
    } else {
        $response->assertSessionHasErrors([
            'poll' => 'La votación necesita al menos 2 participantes y 1 juego.',
        ]);
        expect($poll->fresh()->status)->toBe('draft');
    }
})->with([
    'one participant' => [1, 2, false],
    'one candidate' => [2, 1, true],
    'one of each' => [1, 1, false],
    'no participants' => [0, 2, false],
    'no candidates' => [2, 0, false],
    'exactly two of each' => [2, 2, true],
    'more than two of each' => [3, 3, true],
]);
