<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->collection = GameCollection::create(['created_by' => $this->user->id, 'name' => 'Mi colección']);
    $this->foreignCollection = GameCollection::create(['created_by' => $this->otherUser->id, 'name' => 'Colección ajena']);
    $this->game = Game::create([
        'name' => 'White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $makePoll = function ($user, $name, $token, $status, $date) {
        $poll = Poll::create([
            'created_by' => $user->id,
            'name' => $name,
            'token' => $token,
            'status' => $status,
        ]);
        $poll->forceFill(['created_at' => $date])->save();

        return $poll;
    };
    $this->recordedPoll = $makePoll($this->user, 'Recorded poll', 'INDEXRECORDED', 'closed', '2026-10-20 12:00:00');
    $this->otherPoll = $makePoll($this->user, 'Another poll', 'INDEXOTHER', 'closed', '2026-10-19 12:00:00');
    $this->draftPoll = $makePoll($this->user, 'Draft poll', 'INDEXDRAFT', 'draft', '2026-10-18 12:00:00');
    $this->foreignPoll = $makePoll($this->otherUser, 'Recorded secret', 'INDEXFOREIGN', 'closed', '2026-10-21 12:00:00');
    $this->recordedPoll->gameCollections()->attach([$this->collection->id, $this->foreignCollection->id]);
    $this->foreignPoll->gameCollections()->attach($this->collection->id);
    $this->recordedPoll->participants()->createMany([
        ['display_name' => 'Ana'],
        ['display_name' => 'Bea'],
    ]);
    $this->recordedPoll->candidates()->create(['game_id' => $this->game->id]);

    foreach ([
        [$this->user, $this->recordedPoll],
        [$this->otherUser, $this->otherPoll],
        [$this->user, $this->foreignPoll],
        [$this->user, null],
    ] as [$user, $poll]) {
        Play::create([
            'created_by' => $user->id,
            'game_id' => $this->game->id,
            'poll_id' => $poll?->id,
            'played_at' => '2026-10-22',
        ]);
    }
});

test('the poll index contains only owned polls and checks registered plays in one query', function () {
    $playQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$playQueries) {
        $sql = strtolower(str_replace(['"', '`'], '', $query->sql));
        if (str_contains($sql, 'from plays')) {
            $playQueries[] = $query;
        }
    });

    $this->actingAs($this->user)->get(route('polls.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/index')
            ->has('polls', 3)
            ->where('polls.0.id', $this->recordedPoll->id)
            ->where('polls.0.has_registered_play', true)
            ->where('polls.0.participants_count', 2)
            ->where('polls.0.candidates_count', 1)
            ->where('polls.0.created_at', $this->recordedPoll->created_at->toIso8601String())
            ->where('polls.0.collections', [['id' => $this->collection->id, 'name' => 'Mi colección']])
            ->where('polls.1.id', $this->otherPoll->id)
            ->where('polls.1.has_registered_play', false)
            ->where('polls.2.id', $this->draftPoll->id)
            ->where('polls.2.has_registered_play', false)
            ->has('collections', 1)
        );

    expect($playQueries)->toHaveCount(1);
    expect($playQueries[0]->bindings)->toBe([$this->user->id]);
});

test('existing poll filters remain scoped to the current owner', function () {
    $this->actingAs($this->user)->get(route('polls.index', [
        'collection_id' => $this->collection->id,
        'status' => 'closed',
        'search' => '  Recorded  ',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('polls', 1)
            ->where('polls.0.id', $this->recordedPoll->id)
            ->where('polls.0.has_registered_play', true)
            ->where('filters', [
                'collection_id' => $this->collection->id,
                'status' => 'closed',
                'search' => 'Recorded',
            ])
        );
});

test('a foreign collection and a matching name cannot reveal another users poll', function () {
    $this->actingAs($this->user)->get(route('polls.index', [
        'collection_id' => $this->foreignCollection->id,
        'search' => 'secret',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('polls', 0)
            ->where('filters.collection_id', null)
        );
});

test('the local status filter receives every owned status for switching and counting', function () {
    $this->actingAs($this->user)->get(route('polls.index', ['status' => 'closed']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('polls', 3)
            ->where('polls.0.status', 'closed')
            ->where('polls.1.status', 'closed')
            ->where('polls.2.status', 'draft')
            ->where('filters.status', 'closed')
        );
});

test('an account without polls sees an empty list', function () {
    $this->actingAs(User::factory()->create())->get(route('polls.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('polls', 0));
});

test('the poll index requires authentication', function () {
    $this->get(route('polls.index'))->assertRedirect(route('login'));
});
