<?php

use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('offers extra active players when registering from a closed poll, without changing the selected voters', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $someoneElse = User::factory()->create();
    $poll = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Votación de prueba',
        'token' => 'EXTRA-PLAYERS-TEST',
        'status' => 'closed',
    ]);

    $ana = Player::create(['created_by' => $owner->id, 'name' => 'Ana', 'is_active' => true]);
    $alejo = Player::create(['created_by' => $owner->id, 'name' => 'Alejo', 'is_active' => false]);
    $miki = Player::create(['created_by' => $owner->id, 'name' => 'Miki', 'is_active' => true]);
    Player::create(['created_by' => $owner->id, 'name' => 'Nuria', 'is_active' => false]);
    Player::create(['created_by' => $someoneElse->id, 'name' => 'Bea', 'is_active' => true]);

    $poll->participants()->create(['player_id' => $ana->id, 'display_name' => 'Ana']);
    $poll->participants()->create(['player_id' => $alejo->id, 'display_name' => 'Alejo']);

    $this->actingAs($owner)
        ->get(route('plays.create-from-poll', $poll))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('plays/create')
            ->where('players', [
                ['id' => $ana->id, 'name' => 'Ana'],
                ['id' => $alejo->id, 'name' => 'Alejo'],
                ['id' => $miki->id, 'name' => 'Miki'],
            ])
            ->where('defaults.player_ids', [$ana->id, $alejo->id])
            ->etc());
});
