<?php

use App\Services\BggService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    config([
        'services.bgg.token' => 'test-token',
        'services.bgg.base_url' => 'https://boardgamegeek.com/xmlapi2',
        'cache.default' => 'array',
    ]);
    Cache::flush();
    Http::preventStrayRequests();
});

test('BGG game suggestions use only exact player counts with a clear Best winner and return unique sorted counts', function () {
    Http::fake(['*' => Http::response(<<<'XML'
        <items>
            <item type="boardgame" id="371942">
                <name type="primary" value="The White Castle"/>
                <image>https://example.com/image.jpg</image>
                <thumbnail>https://example.com/thumbnail.jpg</thumbnail>
                <yearpublished value="2023"/>
                <minplayers value="1"/><maxplayers value="4"/>
                <minplaytime value="60"/><maxplaytime value="90"/><playingtime value="80"/>
                <description>BGG &amp;amp; reference</description>
                <statistics><ratings><averageweight value="2.735"/></ratings></statistics>
                <poll name="suggested_playerage">
                    <results numplayers="99"><result value="Best" numvotes="100"/></results>
                </poll>
                <poll name="suggested_numplayers">
                    <results numplayers="10">
                        <result value="Best" numvotes="12"/><result value="Recommended" numvotes="5"/><result value="Not Recommended" numvotes="2"/>
                    </results>
                    <results numplayers="4">
                        <result value="Best" numvotes="100"/><result value="Recommended" numvotes="30"/><result value="Not Recommended" numvotes="1"/>
                    </results>
                    <results numplayers="2">
                        <result value="Best" numvotes="5"/><result value="Recommended" numvotes="5"/>
                    </results>
                    <results numplayers="1">
                        <result value="Best" numvotes="5"/><result value="Not Recommended" numvotes="5"/>
                    </results>
                    <results numplayers="5"><result value="Best" numvotes="0"/></results>
                    <results numplayers="6"><result value="Recommended" numvotes="10"/></results>
                    <results numplayers="7">
                        <result value="Best" numvotes="1"/><result value="Not Recommended" numvotes="10"/>
                    </results>
                    <results numplayers="8">
                        <result value="Best" numvotes="1"/><result value="Recommended" numvotes="10"/>
                    </results>
                    <results numplayers="4+"><result value="Best" numvotes="1000"/></results>
                    <results numplayers="3-4"><result value="Best" numvotes="1000"/></results>
                    <results numplayers="3.5"><result value="Best" numvotes="1000"/></results>
                    <results><result value="Best" numvotes="1000"/></results>
                    <results numplayers="3"><result value="Best" numvotes="8"/></results>
                    <results numplayers="4"><result value="Best" numvotes="5"/></results>
                </poll>
            </item>
        </items>
        XML)]);

    $game = app(BggService::class)->game(371942);

    expect($game)->toBe([
        'bgg_id' => 371942,
        'name' => 'The White Castle',
        'image_url' => 'https://example.com/image.jpg',
        'thumbnail_url' => 'https://example.com/thumbnail.jpg',
        'year_published' => 2023,
        'min_players' => 1,
        'max_players' => 4,
        'min_playtime' => 60,
        'max_playtime' => 90,
        'playing_time' => 80,
        'complexity' => 2.74,
        'suggested_ideal_player_counts' => [3, 4, 10],
        'description' => 'BGG & reference',
        'bgg_url' => 'https://boardgamegeek.com/boardgame/371942',
    ]);
    Http::assertSent(fn (Request $request) => $request['id'] === 371942
        && $request['type'] === 'boardgame'
        && $request['stats'] === 1
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('games without player recommendations return no suggested counts', function ($polls) {
    Http::fake(['*' => Http::response(
        '<items><item id="371942"><name type="primary" value="The White Castle"/>'
        .$polls.'</item></items>'
    )]);

    expect(app(BggService::class)->game(371942)['suggested_ideal_player_counts'])->toBe([]);
})->with([
    'no polls' => [''],
    'an unrelated poll' => ['<poll name="suggested_playerage"><results numplayers="4"><result value="Best" numvotes="100"/></results></poll>'],
    'an empty player count poll' => ['<poll name="suggested_numplayers"/>'],
]);

test('old game cache entries do not hide suggestions and the new response is cached', function () {
    Cache::put('bgg-game-371942', ['name' => 'Respuesta antigua'], now()->addDay());
    Http::fake(['*' => Http::response(<<<'XML'
        <items><item id="371942">
            <name type="primary" value="The White Castle"/>
            <poll name="suggested_numplayers">
                <results numplayers="4"><result value="Best" numvotes="10"/></results>
            </poll>
        </item></items>
        XML)]);

    $service = app(BggService::class);
    $game = $service->game(371942);

    expect($game['suggested_ideal_player_counts'])->toBe([4]);
    expect($service->game(371942))->toBe($game);
    Http::assertSentCount(1);
});
