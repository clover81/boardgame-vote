<?php

use App\Http\Controllers\PlayerController;
use App\Models\Expansion;
use App\Models\Game;
use App\Models\Play;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->player = Player::create([
        'created_by' => $this->user->id,
        'name' => 'Alejandro',
        'is_active' => true,
    ]);
    $this->request = Request::create('/players/'.$this->player->id);
    $this->request->headers->set('X-Inertia', 'true');
    $this->request->setUserResolver(fn () => $this->user);

    $this->createGame = fn (string $name) => Game::create([
        'name' => $name,
        'min_players' => 1,
        'max_players' => 8,
        'game_mode' => 'competitive',
    ]);
    $this->createPlay = function (Game $game, string $date, array $playerIds, ?User $creator = null, ?Expansion $expansion = null) {
        $play = Play::create([
            'created_by' => ($creator ?? $this->user)->id,
            'game_id' => $game->id,
            'expansion_id' => $expansion?->id,
            'played_at' => $date,
            'notes' => 'Notas de la partida',
        ]);
        $play->players()->attach($playerIds);

        return $play;
    };
    $this->showPlayer = fn () => app(PlayerController::class)
        ->show($this->request, $this->player)
        ->toResponse($this->request)
        ->getData(true);
});

test('a player with no recorded plays has empty statistics and lists', function () {
    $page = ($this->showPlayer)();

    expect($page['component'])->toBe('players/show');
    expect($page['props']['player'])->toBe([
        'id' => $this->player->id,
        'name' => 'Alejandro',
        'is_active' => true,
    ]);
    expect($page['props']['stats'])->toBe([
        'total_plays' => 0,
        'unique_games' => 0,
        'last_played_at' => null,
    ]);
    expect($page['props']['top_games'])->toBe([]);
    expect($page['props']['frequent_players'])->toBe([]);
    expect($page['props']['recent_plays'])->toBe([]);
});

test('player statistics include only that players plays recorded by the current user', function () {
    $ana = Player::create(['name' => 'Ana', 'is_active' => true]);
    $bea = Player::create(['name' => 'Bea', 'is_active' => true]);
    $alpha = ($this->createGame)('Alfa');
    $zeta = ($this->createGame)('Zeta');
    $expansion = $alpha->expansions()->create(['name' => 'Expansión Alfa']);

    ($this->createPlay)($zeta, '2026-10-20', [$this->player->id, $ana->id]);
    ($this->createPlay)($alpha, '2026-10-21', [$this->player->id, $ana->id, $bea->id]);
    ($this->createPlay)($zeta, '2026-10-23', [$this->player->id, $ana->id]);
    $latest = ($this->createPlay)($alpha, '2026-10-23', [$this->player->id, $bea->id], null, $expansion);
    // These must not affect any of the aggregates or recent plays.
    ($this->createPlay)($zeta, '2026-10-25', [$this->player->id, $bea->id], User::factory()->create());
    ($this->createPlay)($zeta, '2026-10-25', [$ana->id, $bea->id]);

    $props = ($this->showPlayer)()['props'];

    expect($props['stats'])->toBe([
        'total_plays' => 4,
        'unique_games' => 2,
        'last_played_at' => '2026-10-23',
    ]);
    expect(array_column($props['top_games'], 'name'))->toBe(['Alfa', 'Zeta']);
    expect(array_column($props['top_games'], 'plays_count'))->toBe([2, 2]);
    expect(array_column($props['top_games'], 'last_played_at'))->toBe(['2026-10-23', '2026-10-23']);
    expect($props['frequent_players'])->toBe([
        ['id' => $ana->id, 'name' => 'Ana', 'plays_together' => 3],
        ['id' => $bea->id, 'name' => 'Bea', 'plays_together' => 2],
    ]);
    expect($props['recent_plays'])->toHaveCount(4);
    expect($props['recent_plays'][0]['id'])->toBe($latest->id);
    expect($props['recent_plays'][0]['notes'])->toBe('Notas de la partida');
    expect($props['recent_plays'][0]['game']['id'])->toBe($alpha->id);
    expect($props['recent_plays'][0]['expansion'])->toBe(['id' => $expansion->id, 'name' => 'Expansión Alfa']);
    expect(array_column($props['recent_plays'][0]['players'], 'id'))
        ->toEqualCanonicalizing([$this->player->id, $bea->id]);
    expect($props['recent_plays'][1]['expansion'])->toBeNull();
});

test('the rankings stop at five and recent plays at ten without truncating total statistics', function () {
    foreach (range(1, 6) as $index) {
        $game = ($this->createGame)('Juego '.$index);
        $companion = Player::create(['name' => 'Compañero '.$index, 'is_active' => true]);
        foreach (range(1, $index) as $playIndex) {
            ($this->createPlay)($game, '2026-10-24', [$this->player->id, $companion->id]);
        }
    }

    $props = ($this->showPlayer)()['props'];

    expect($props['stats']['total_plays'])->toBe(21);
    expect($props['stats']['unique_games'])->toBe(6);
    expect(array_column($props['top_games'], 'plays_count'))->toBe([6, 5, 4, 3, 2]);
    expect(array_column($props['frequent_players'], 'plays_together'))->toBe([6, 5, 4, 3, 2]);
    expect($props['recent_plays'])->toHaveCount(10);
    expect(array_column($props['recent_plays'], 'id'))->toBe(range(21, 12));
});
