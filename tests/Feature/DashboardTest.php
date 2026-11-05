<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard totals include every owned record while previews show only the latest five', function () {
    $this->withoutVite();
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $makeGame = fn ($name, $active = true) => Game::create([
        'name' => $name,
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => $active,
    ]);
    $game = $makeGame('White Castle');
    $inactiveGame = $makeGame('Juego archivado', false);
    $foreignGame = $makeGame('Juego ajeno');
    $makeGame('Juego sin colección');
    $collection = GameCollection::create(['created_by' => $owner->id, 'name' => 'Mi colección']);
    $secondCollection = GameCollection::create(['created_by' => $owner->id, 'name' => 'Segunda colección']);
    $foreignCollection = GameCollection::create(['created_by' => $otherUser->id, 'name' => 'Colección ajena']);
    $collection->games()->attach([$game->id, $inactiveGame->id]);
    $secondCollection->games()->attach($game->id);
    $foreignCollection->games()->attach([$game->id, $foreignGame->id]);

    $sequence = 0;
    $makePoll = function ($user, $status, $date) use (&$sequence) {
        $sequence++;
        $poll = Poll::create([
            'created_by' => $user->id,
            'name' => 'Sesión ' . $sequence,
            'token' => 'DASHBOARD' . $sequence,
            'status' => $status,
        ]);
        $poll->forceFill(['created_at' => $date])->save();

        return $poll;
    };
    $openPolls = collect(range(1, 7))->map(fn ($day) => $makePoll($owner, 'open', sprintf('2026-10-%02d 12:00:00', $day)));
    $pendingPolls = collect(range(10, 17))->map(fn ($day) => $makePoll($owner, 'closed', sprintf('2026-10-%02d 12:00:00', $day)));
    $registeredPoll = $makePoll($owner, 'closed', '2026-10-28 12:00:00');
    $makePoll($owner, 'draft', '2026-10-29 12:00:00');
    $makePoll($otherUser, 'open', '2026-10-30 12:00:00');
    $makePoll($otherUser, 'closed', '2026-10-30 12:00:00');
    $openPolls->last()->participants()->createMany([['display_name' => 'Ana'], ['display_name' => 'Bea']]);
    $openPolls->last()->candidates()->create(['game_id' => $game->id]);

    $expansion = $game->expansions()->create(['name' => 'Matcha', 'is_active' => true]);
    $player = Player::create(['name' => 'Ana', 'is_active' => true]);
    $plays = collect(range(1, 7))->map(function ($day) use ($owner, $game, $registeredPoll, $expansion, $player) {
        $play = Play::create([
            'created_by' => $owner->id,
            'game_id' => $game->id,
            'poll_id' => $day === 1 ? $registeredPoll->id : null,
            'expansion_id' => $day === 7 ? $expansion->id : null,
            'played_at' => sprintf('2026-10-%02d', $day),
        ]);
        if ($day === 7) {
            $play->players()->attach($player);
        }

        return $play;
    });
    Play::create(['created_by' => $otherUser->id, 'game_id' => $game->id, 'played_at' => '2026-10-31']);

    $response = $this->actingAs($owner)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('summary', [
                'games_count' => 2,
                'open_polls_count' => 7,
                'total_plays' => 7,
                'pending_plays_count' => 8,
            ])
            ->has('openPolls', 5)
            ->where('openPolls.0.id', $openPolls->last()->id)
            ->where('openPolls.0.token', $openPolls->last()->token)
            ->where('openPolls.0.participants_count', 2)
            ->where('openPolls.0.candidates_count', 1)
            ->has('pendingPlays', 5)
            ->where('pendingPlays.0.id', $pendingPolls->last()->id)
            ->has('recentPlays', 5)
            ->where('recentPlays.0.id', $plays->last()->id)
            ->where('recentPlays.0.played_at', '2026-10-07')
            ->where('recentPlays.0.game', ['id' => $game->id, 'name' => 'White Castle', 'image_url' => null])
            ->where('recentPlays.0.expansion', ['id' => $expansion->id, 'name' => 'Matcha'])
            ->where('recentPlays.0.players', [['id' => $player->id, 'name' => 'Ana']])
            ->where('recentPlays.1.expansion', null)
            ->where('recentPlays.1.players', [])
        );

    expect(array_column($response->inertiaProps('openPolls'), 'id'))
        ->toBe($openPolls->reverse()->take(5)->pluck('id')->all());
    expect(array_column($response->inertiaProps('pendingPlays'), 'id'))
        ->toBe($pendingPolls->reverse()->take(5)->pluck('id')->all());
    expect(array_column($response->inertiaProps('recentPlays'), 'id'))
        ->toBe($plays->reverse()->take(5)->pluck('id')->all());
});

test('an account without records receives an empty dashboard despite another users data', function () {
    $this->withoutVite();
    $otherUser = User::factory()->create();
    $game = Game::create(['name' => 'Heat', 'min_players' => 1, 'max_players' => 6, 'game_mode' => 'competitive']);
    $collection = GameCollection::create(['created_by' => $otherUser->id, 'name' => 'Colección ajena']);
    $collection->games()->attach($game);
    foreach (['open', 'closed'] as $status) {
        Poll::create(['created_by' => $otherUser->id, 'name' => 'Ajena', 'token' => 'OTHER' . $status, 'status' => $status]);
    }
    Play::create(['created_by' => $otherUser->id, 'game_id' => $game->id, 'played_at' => '2026-10-20']);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary', ['games_count' => 0, 'open_polls_count' => 0, 'total_plays' => 0, 'pending_plays_count' => 0])
            ->where('openPolls', [])
            ->where('pendingPlays', [])
            ->where('recentPlays', [])
        );
});
