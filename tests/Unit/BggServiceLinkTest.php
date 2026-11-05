<?php

use App\Services\BggService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    config([
        'services.bgg.token' => 'test-token',
        'services.bgg.base_url' => 'https://boardgamegeek.com/xmlapi2/',
    ]);
    Http::preventStrayRequests();
});

test('link searches map primary or fallback names and return at most twelve named results', function () {
    $items = '<item id="99"/><item id="1"><name type="alternate" value="Alternativo"/>'
        .'<name type="primary" value="Principal"/><yearpublished value="2023"/></item>'
        .'<item id="2"><name type="alternate" value="Sin principal"/></item>';
    foreach (range(3, 15) as $id) {
        $items .= '<item id="'.$id.'"><name type="primary" value="Juego '.$id.'"/></item>';
    }
    Http::fake(['*' => Http::response('<items>'.$items.'</items>')]);

    $results = app(BggService::class)->searchForLink('  White Castle  ');

    expect($results)->toHaveCount(12)
        ->and($results[0])->toBe(['id' => 1, 'name' => 'Principal', 'year_published' => 2023])
        ->and($results[1])->toBe(['id' => 2, 'name' => 'Sin principal', 'year_published' => null])
        ->and(array_column($results, 'id'))->toBe(range(1, 12));
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://boardgamegeek.com/xmlapi2/search?')
        && $request['query'] === 'White Castle'
        && $request['type'] === 'boardgame'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->hasHeader('Accept', 'application/xml'));
    Http::assertSentCount(1);
});

test('link searches resolve numeric IDs and URLs directly without a search request', function ($query) {
    Http::fake(['*' => Http::response(<<<'XML'
        <items><item type="boardgame" id="371942">
            <name type="primary" value="The White Castle"/>
            <yearpublished value="2023"/>
        </item></items>
        XML)]);

    expect(app(BggService::class)->searchForLink($query))->toBe([
        ['id' => 371942, 'name' => 'The White Castle', 'year_published' => 2023],
    ]);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://boardgamegeek.com/xmlapi2/thing?')
        && $request['id'] === 371942 && $request['stats'] === 1 && $request['type'] === 'boardgame');
    Http::assertSentCount(1);
})->with([
    '371942',
    ' 371942 ',
    'https://boardgamegeek.com/boardgame/371942/the-white-castle',
    'https://boardgamegeek.com/boardgame/371942/2023-edition',
    'https://boardgamegeek.com/boardgame/the-white-castle/371942',
]);

test('link details map only the requested fields and round positive complexity', function () {
    Http::fake(['*' => Http::response(<<<'XML'
        <items><item type="boardgame" id="371942">
            <name type="alternate" value="Alternativo"/>
            <name type="primary" value="The White Castle"/>
            <image> https://example.com/game.jpg </image>
            <yearpublished value="2023"/>
            <minplayers value="1"/><maxplayers value="4"/>
            <minplaytime value="60"/><maxplaytime value="90"/>
            <statistics><ratings><averageweight value="3.085"/></ratings></statistics>
        </item></items>
        XML)]);

    expect(app(BggService::class)->gameForLink(371942))->toBe([
        'id' => 371942,
        'name' => 'The White Castle',
        'year_published' => 2023,
        'image_url' => 'https://example.com/game.jpg',
        'min_players' => 1,
        'max_players' => 4,
        'min_playtime' => 60,
        'max_playtime' => 90,
        'complexity' => 3.09,
        'bgg_url' => 'https://boardgamegeek.com/boardgame/371942',
    ]);
});

test('link details handle alternate names missing fields and unrated complexity', function ($weight) {
    Http::fake(['*' => Http::response('<items><item id="371942">'
        .'<name type="alternate" value="Alternativo"/><image>   </image>'
        .$weight.'</item></items>')]);

    $game = app(BggService::class)->gameForLink(371942);
    expect($game['name'])->toBe('Alternativo');
    foreach (['year_published', 'image_url', 'min_players', 'max_players', 'min_playtime', 'max_playtime', 'complexity'] as $field) {
        expect($game[$field])->toBeNull();
    }
})->with(['', '<statistics><ratings><averageweight value="0"/></ratings></statistics>']);

test('link details report a missing game', function () {
    Http::fake(['*' => Http::response('<items/>')]);

    expect(fn () => app(BggService::class)->gameForLink(371942))
        ->toThrow(RuntimeException::class, 'BGG no ha devuelto el juego solicitado.');
});

test('link requests reject HTTP failures and malformed XML', function ($body, $status, $message) {
    Http::fake(['*' => Http::response($body, $status)]);

    expect(fn () => app(BggService::class)->searchForLink('White Castle'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'HTTP failure' => ['', 503, 'Error BGG HTTP 503.'],
    'malformed XML' => ['<items><item></items>', 200, 'La respuesta de BGG no contiene XML válido.'],
]);
