<?php

use App\Models\Poll;
use App\Models\User;

test('WhatsApp distingue los dos enlaces personales de una sesion', function () {
    $this->withoutVite();

    $owner = User::factory()->create();

    $first = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Mari partida',
        'token' => 'PRIMERJUEGO',
        'status' => 'open',
    ]);

    $second = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Mari partida · Juego 2',
        'token' => 'SEGUNDOJUEGO',
        'status' => 'open',
        'parent_poll_id' => $first->id,
    ]);

    $firstParticipant = $first->participants()->create([
        'display_name' => 'Ana',
        'vote_token' => str_repeat('a', 40),
    ]);

    $secondParticipant = $second->participants()->create([
        'display_name' => 'Ana',
        'vote_token' => str_repeat('b', 40),
    ]);

    $this->get(route('vote.participant.show', [
        $first->token, $firstParticipant->vote_token,
    ]))
        ->assertOk()
        ->assertSee(
            'property="og:title" content="🎲 Mari partida · Juego 1 de 2"',
            false
        )
        ->assertSee('Juego 1 de 2: vota y consulta', false)
        ->assertSee('name="robots" content="noindex, nofollow"', false);

    $this->get(route('vote.participant.show', [
        $second->token, $secondParticipant->vote_token,
    ]))
        ->assertOk()
        ->assertSee(
            'property="og:title" content="🎲 Mari partida · Juego 2 de 2"',
            false
        )
        ->assertSee('Juego 2 de 2: vota y consulta', false);

    $second->update(['status' => 'closed']);

    $this->get(route('vote.participant.show', [
        $second->token, $secondParticipant->vote_token,
    ]))
        ->assertOk()
        ->assertSee(
            'Juego 2 de 2: consulta los resultados y los tutoriales.',
            false
        );
});

test('una votacion individual conserva su titulo propio', function () {
    $this->withoutVite();

    $poll = Poll::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Noche de juegos',
        'token' => 'VOTACIONINDIVIDUAL',
        'status' => 'open',
    ]);

    $participant = $poll->participants()->create([
        'display_name' => 'Bea',
        'vote_token' => str_repeat('c', 40),
    ]);

    $this->get(route('vote.participant.show', [
        $poll->token, $participant->vote_token,
    ]))
        ->assertOk()
        ->assertSee(
            'property="og:title" content="🎲 Noche de juegos"',
            false
        );
});
