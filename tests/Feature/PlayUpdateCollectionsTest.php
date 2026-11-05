<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'Mi colección',
        'is_default' => true,
    ]);
    $this->game = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);
    $this->collection->games()->attach($this->game);
    $this->expansion = $this->game->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $this->originalPlayer = Player::create([
        'created_by' => $this->user->id,
        'name' => 'Ana',
        'is_active' => true,
    ]);

    $this->newPlayer = Player::create([
        'created_by' => $this->user->id,
        'name' => 'Bea',
        'is_active' => true,
    ]);
    $this->poll = Poll::create([
        'created_by' => $this->user->id,
        'name' => 'Partida del domingo',
        'token' => 'UPDATETEST',
        'status' => 'closed',
    ]);
    $this->play = Play::create([
        'created_by' => $this->user->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->game->id,
        'expansion_id' => $this->expansion->id,
        'played_at' => '2026-10-25',
        'notes' => 'Notas originales',
    ]);
    $this->play->players()->attach($this->originalPlayer);
    $this->payload = [
        'game_id' => $this->game->id,
        'expansion_id' => $this->expansion->id,
        'played_at' => '2026-10-24',
        'notes' => 'Notas corregidas',
        'player_ids' => [$this->newPlayer->id],
    ];
    $this->assertPlayUnchanged = function () {
        $play = $this->play->fresh();
        expect($play->game_id)->toBe($this->game->id);
        expect($play->expansion_id)->toBe($this->expansion->id);
        expect($play->played_at->toDateString())->toBe('2026-10-25');
        expect($play->notes)->toBe('Notas originales');
        expect($play->players->modelKeys())->toBe([$this->originalPlayer->id]);
    };
});

test('update accepts a game from another owned collection and its active expansion', function () {
    $collection = GameCollection::create(['created_by' => $this->user->id, 'name' => 'Del grupo']);
    $foreignCollection = GameCollection::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección ajena',
    ]);
    $game = Game::create([
        'name' => 'Heat',
        'min_players' => 1,
        'max_players' => 6,
        'game_mode' => 'competitive',
    ]);
    $game->collections()->attach([$collection->id, $foreignCollection->id]);
    $expansion = $game->expansions()->create(['name' => 'Expansión elegida', 'is_active' => true]);
    $this->payload['game_id'] = $game->id;
    $this->payload['expansion_id'] = $expansion->id;

    $this->actingAs($this->user)->put(route('plays.update', $this->play), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('plays.index'))
        ->assertSessionHas('success', 'Partida actualizada correctamente.');

    $play = $this->play->fresh();
    expect($play->game_id)->toBe($game->id);
    expect($play->expansion_id)->toBe($expansion->id);
    expect($play->poll_id)->toBe($this->poll->id);
    expect($play->created_by)->toBe($this->user->id);
    expect($play->played_at->toDateString())->toBe('2026-10-24');
    expect($play->notes)->toBe('Notas corregidas');
    expect($play->players->modelKeys())->toBe([$this->newPlayer->id]);
});

test('update can clear an expansion', function ($input) {
    if ($input === 'omitted') {
        unset($this->payload['expansion_id']);
    } else {
        $this->payload['expansion_id'] = null;
    }

    $this->actingAs($this->user)->put(route('plays.update', $this->play), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('plays.index'));

    expect($this->play->fresh()->expansion_id)->toBeNull();
})->with(['omitted', 'null']);

test('update rejects games outside owned collections without changing the play or players', function ($foreign) {
    $game = Game::create([
        'name' => 'Juego no disponible',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    if ($foreign) {
        $collection = GameCollection::create([
            'created_by' => User::factory()->create()->id,
            'name' => 'Colección ajena',
        ]);
        $collection->games()->attach($game);
    }
    $this->payload['game_id'] = $game->id;
    $this->payload['expansion_id'] = null;

    $this->actingAs($this->user)->put(route('plays.update', $this->play), $this->payload)
        ->assertNotFound();

    ($this->assertPlayUnchanged)();
})->with(['foreign' => true, 'uncollected' => false]);

test('update rejects expansions of another game and nonexistent expansions', function ($kind) {
    if ($kind === 'missing') {
        $this->payload['expansion_id'] = 999999;
    } else {
        $game = Game::create([
            'name' => 'Otro juego',
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
        ]);
        $expansion = $game->expansions()->create([
            'name' => 'Expansión no válida',
            'is_active' => true,
        ]);
        $this->payload['expansion_id'] = $expansion->id;
    }

    $this->actingAs($this->user)->put(route('plays.update', $this->play), $this->payload)
        ->assertSessionHasErrors($kind === 'missing' ? 'expansion_id' : [
            'expansion_id' => 'La expansión seleccionada no pertenece a este juego.',
        ]);

    ($this->assertPlayUnchanged)();
})->with(['foreign', 'missing']);

test('update can retain an inactive owned game and its inactive expansion', function () {
    $this->game->update(['is_active' => false]);
    $this->expansion->update(['is_active' => false]);

    $this->actingAs($this->user)->put(route('plays.update', $this->play), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('plays.index'));

    $play = $this->play->fresh();
    expect($play->game_id)->toBe($this->game->id);
    expect($play->expansion_id)->toBe($this->expansion->id);
    expect($play->notes)->toBe('Notas corregidas');
    expect($play->players->modelKeys())->toBe([$this->newPlayer->id]);
});

test('editing cannot change the original poll association through forged input', function ($hasPoll, $input) {
    if (! $hasPoll) {
        $this->play->update(['poll_id' => null]);
    }
    $expectedPollId = $this->play->poll_id;
    $this->payload['poll_id'] = match ($input) {
        'another poll' => Poll::create([
            'created_by' => $this->user->id,
            'name' => 'Otra votación',
            'token' => 'OTHERPOLL',
            'status' => 'closed',
        ])->id,
        'null' => null,
        'invalid' => 999999,
    };

    $this->actingAs($this->user)->put(route('plays.update', $this->play), $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('plays.index'));

    expect($this->play->fresh()->poll_id)->toBe($expectedPollId);
    expect($this->play->fresh()->notes)->toBe('Notas corregidas');
})->with([true, false])->with(['another poll', 'null', 'invalid']);

test('another user cannot update a play', function () {
    $this->actingAs(User::factory()->create())->put(route('plays.update', $this->play), $this->payload)
        ->assertForbidden();

    ($this->assertPlayUnchanged)();
});

test('updating a play requires authentication', function () {
    $this->put(route('plays.update', $this->play), $this->payload)
        ->assertRedirect(route('login'));

    ($this->assertPlayUnchanged)();
});
