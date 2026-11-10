<?php

use App\Models\Expansion;
use App\Models\Game;
use App\Services\BggCollectionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.bgg.token' => 'test-token']);
    Http::preventStrayRequests();
    \Illuminate\Support\Sleep::fake();
});

afterEach(function () {
    \Illuminate\Support\Sleep::fake(false);
});

test('collection previews distinguish games and expansions without changing local records', function () {
    $game = Game::create([
        'bgg_id' => 100,
        'name' => 'Nombre local editado',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'short_description' => 'Descripción propia',
    ]);
    Game::create([
        'bgg_id' => 300,
        'name' => 'Fuera de la colección BGG',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);
    $expansion = $game->expansions()->create([
        'bgg_id' => 200,
        'name' => 'Expansión editada',
        'notes' => 'Notas propias',
    ]);
    $beforeGame = $game->fresh()->getAttributes();
    $beforeExpansion = $expansion->fresh()->getAttributes();
    Http::fake(function (Request $request) {
        expect($request->method())->toBe('GET')
            ->and($request['username'])->toBe('mi-usuario')
            ->and($request['own'])->toBe(1)
            ->and($request->hasHeader('Authorization', 'Bearer test-token'))->toBeTrue()
            ->and($request->hasHeader('Accept', 'application/xml'))->toBeTrue();

        if (($request['excludesubtype'] ?? null) === 'boardgameexpansion') {
            expect($request['subtype'] ?? null)->toBeNull();

            return Http::response(<<<'XML'
                <items>
                    <item objectid="100"><name> Juego existente </name></item>
                    <item objectid="101"><name>Nuevo &amp; especial</name></item>
                    <item objectid="100"><name> Juego existente </name></item>
                    <item objectid="0"><name>Inválido</name></item>
                    <item objectid="-1"><name>Inválido</name></item>
                </items>
                XML);
        }

        expect($request['subtype'])->toBe('boardgameexpansion')
            ->and($request['excludesubtype'] ?? null)->toBeNull();

        return Http::response(<<<'XML'
            <items>
                <item objectid="200"><name>Expansión existente</name></item>
                <item objectid="201"><name>Expansión nueva</name></item>
                <item objectid="201"><name>Expansión nueva</name></item>
            </items>
            XML);
    });

    DB::enableQueryLog();
    try {
        $preview = app(BggCollectionService::class)->preview('mi-usuario');
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($preview)->toBe([
        'summary' => [
            'games' => 2, 'games_existing' => 1, 'games_new' => 1,
            'expansions' => 2, 'expansions_existing' => 1, 'expansions_new' => 1,
        ],
        'games' => [
            ['bgg_id' => 100, 'name' => 'Juego existente', 'status' => 'existing'],
            ['bgg_id' => 101, 'name' => 'Nuevo & especial', 'status' => 'new'],
        ],
        'expansions' => [
            ['bgg_id' => 200, 'name' => 'Expansión existente', 'status' => 'existing'],
            ['bgg_id' => 201, 'name' => 'Expansión nueva', 'status' => 'new'],
        ],
    ]);
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
    expect($game->fresh()->getAttributes())->toBe($beforeGame)
        ->and($expansion->fresh()->getAttributes())->toBe($beforeExpansion);
    $this->assertDatabaseCount('games', 2);
    $this->assertDatabaseCount('expansions', 1);
    Http::assertSentCount(2);
});

test('an empty BGG collection produces an empty preview', function () {
    Http::fake(['*' => Http::response('<items/>')]);

    expect(app(BggCollectionService::class)->preview('sin-juegos'))->toBe([
        'summary' => [
            'games' => 0, 'games_existing' => 0, 'games_new' => 0,
            'expansions' => 0, 'expansions_existing' => 0, 'expansions_new' => 0,
        ],
        'games' => [],
        'expansions' => [],
    ]);
    Http::assertSentCount(2);
});

test('collection previews require a configured token before making requests', function () {
    config(['services.bgg.token' => '']);
    Http::fake();

    expect(fn () => app(BggCollectionService::class)->preview('mi-usuario'))
        ->toThrow(RuntimeException::class, 'No hay token de BGG configurado.');
    Http::assertNothingSent();
});

test('collection HTTP errors stop the preview with an explanatory message', function ($status, $message) {
    Http::fake(['*' => Http::response('', $status)]);

    expect(fn () => app(BggCollectionService::class)->preview('mi-usuario'))
        ->toThrow(RuntimeException::class, $message);
    Http::assertSentCount($status === 202 ? 8 : 1);
    \Illuminate\Support\Sleep::assertSleptTimes($status === 202 ? 7 : 0);
})->with([
    'collection pending' => [202, 'BGG sigue preparando la colección tras varios intentos.'],
    'unauthorized' => [401, 'BGG ha respondido con HTTP 401'],
    'rate limited' => [429, 'BGG está limitando temporalmente las consultas (HTTP 429)'],
    'server error' => [500, 'BGG ha respondido con HTTP 500'],
]);

test('invalid collection XML restores the previous libxml error handling', function ($body, $internalErrors) {
    Http::fake(['*' => Http::response($body)]);
    $previous = libxml_use_internal_errors($internalErrors);

    try {
        expect(fn () => app(BggCollectionService::class)->preview('mi-usuario'))
            ->toThrow(RuntimeException::class, 'BGG no ha devuelto una colección XML válida.');
        expect(libxml_use_internal_errors())->toBe($internalErrors)
            ->and(libxml_get_errors())->toBe([]);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    Http::assertSentCount(1);
})->with([
    'malformed with warnings enabled' => ['<items><item>', false],
    'malformed with internal errors enabled' => ['<items><item>', true],
    'wrong root with warnings enabled' => ['<errors><error>Usuario inválido</error></errors>', false],
    'wrong root with internal errors enabled' => ['<errors/>', true],
]);
