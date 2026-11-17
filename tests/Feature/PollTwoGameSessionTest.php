<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();

    $this->owner = User::factory()->create();
    $this->collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Colección principal',
        'is_default' => true,
    ]);

    $this->players = collect(['Ana', 'Bea', 'Carlos'])
        ->map(fn ($name) => Player::create([
            'created_by' => $this->owner->id,
            'name' => $name,
            'is_active' => true,
        ]));

    $this->games = collect(['White Castle', 'Heat', 'Cascadia'])
        ->map(fn ($name) => Game::create([
            'name' => $name,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]));

    $this->collection->games()->attach($this->games->pluck('id'));

    $this->payload = [
        'name' => 'Primera partida',
        'collection_ids' => [$this->collection->id],
        'player_ids' => $this->players->pluck('id')->all(),
        'candidate_ids' => $this->games->take(2)->pluck('id')->all(),
    ];
});

test('elegir dos juegos abre la configuración del segundo', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.store'), [
            ...$this->payload,
            'create_second_game' => true,
        ])->assertSessionHasNoErrors();

    $first = Poll::sole();

    $this->get(route('polls.create', [
        'parent_poll_id' => $first->id,
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('polls/create')
        ->where('parentPoll.id', $first->id)
        ->where('parentPoll.name', $first->name)
        ->where(
            'parentPoll.player_ids',
            $this->players->pluck('id')->all()
        )
        ->where('parentPoll.collection_ids', [$this->collection->id])
    );
});

test('las dos votaciones mantienen jugadores y juegos distintos', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.store'), $this->payload)
        ->assertSessionHasNoErrors();

    $first = Poll::sole();

    $this->post(route('polls.store'), [
        ...$this->payload,
        'name' => 'Segunda partida',
        'parent_poll_id' => $first->id,
        'player_ids' => [
            $this->players[0]->id,
            $this->players[2]->id,
        ],
        'candidate_ids' => [$this->games[2]->id],
    ])->assertSessionHasNoErrors();

    $second = Poll::where('parent_poll_id', $first->id)->sole();

    expect($first->secondGamePoll->id)->toBe($second->id);
    expect($second->firstGamePoll->id)->toBe($first->id);

    expect($first->token)->not->toBe($second->token);
    expect($first->participants->pluck('player_id')->all())
        ->toHaveCount(3);
    expect($second->participants->pluck('player_id')->all())
        ->toEqualCanonicalizing([
            $this->players[0]->id,
            $this->players[2]->id,
        ]);

    expect($first->candidates->pluck('game_id')->all())
        ->toEqualCanonicalizing($this->games->take(2)->pluck('id')->all());

    expect($second->candidates->pluck('game_id')->all())
        ->toBe([$this->games[2]->id]);

    $this->assertDatabaseCount('polls', 2);
});

test('no se puede crear una tercera votación ni usar sesiones ajenas', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.store'), $this->payload)
        ->assertSessionHasNoErrors();

    $first = Poll::sole();

    $secondPayload = [
        ...$this->payload,
        'name' => 'Segundo juego',
        'parent_poll_id' => $first->id,
    ];

    $this->post(route('polls.store'), $secondPayload)
        ->assertSessionHasNoErrors();

    $this->post(route('polls.store'), $secondPayload)
        ->assertSessionHasErrors('parent_poll_id');

    $other = User::factory()->create();

    $this->actingAs($other)
        ->post(route('polls.store'), [
            ...$secondPayload,
            'collection_ids' => [],
        ])->assertSessionHasErrors('collection_ids');

    $this->assertDatabaseCount('polls', 2);
});

test('la navegación enlaza las dos votaciones de la sesión', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.store'), $this->payload)
        ->assertSessionHasNoErrors();

    $first = Poll::sole();

    $this->get(route('polls.show', $first))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('poll.can_add_second_game', true)
            ->where('poll.linked_poll', null)
        );

    $this->post(route('polls.store'), [
        ...$this->payload,
        'name' => 'Segundo juego',
        'parent_poll_id' => $first->id,
    ])->assertSessionHasNoErrors();

    $second = Poll::where('parent_poll_id', $first->id)->sole();

    $this->get(route('polls.show', $first))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('poll.linked_poll.id', $second->id)
            ->where('poll.is_second_game', false)
            ->where('poll.can_add_second_game', false)
        );

    $this->get(route('polls.show', $second))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('poll.linked_poll.id', $first->id)
            ->where('poll.is_second_game', true)
        );
});
