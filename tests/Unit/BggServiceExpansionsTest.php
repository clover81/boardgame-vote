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

test('expansions are verified against their base game mapped sorted and cached for twelve hours', function () {
    Http::fake(function (Request $request) {
        expect($request->hasHeader('Authorization', 'Bearer test-token'))->toBeTrue();

        if ($request['type'] === 'boardgame') {
            expect($request['id'])->toBe(371942);

            return Http::response(<<<'XML'
                <items><item type="boardgame" id="371942">
                    <link type="boardgameexpansion" id="101"/>
                    <link type="boardgameexpansion" id="102"/>
                    <link type="boardgameexpansion" id="103"/>
                    <link type="boardgameexpansion" id="104"/>
                    <link type="boardgameexpansion" id="105"/>
                    <link type="boardgameexpansion" id="106"/>
                    <link type="boardgameexpansion" id="101"/>
                    <link type="boardgameexpansion" id="0"/>
                    <link type="boardgameexpansion" id="-1"/>
                    <link type="boardgamedesigner" id="999"/>
                </item></items>
                XML);
        }

        expect($request['type'])->toBe('boardgameexpansion')
            ->and($request['id'])->toBe('101,102,103,104,105,106');

        return Http::response(<<<'XML'
            <items>
                <item type="boardgameexpansion" id="101">
                    <name type="alternate" value="An alternate title"/>
                    <name type="primary" value="zeta"/>
                    <image>   </image>
                    <link type="boardgameexpansion" id="371942"/>
                </item>
                <item type="boardgameexpansion" id="102">
                    <name type="primary" value="Alpha"/>
                    <image> https://example.com/expansion.jpg </image>
                    <thumbnail>https://example.com/thumbnail.jpg</thumbnail>
                    <yearpublished value="2024"/>
                    <link type="boardgameexpansion" id="371942"/>
                </item>
                <item type="boardgameexpansion" id="103">
                    <name type="primary" value="Another games expansion"/>
                    <link type="boardgameexpansion" id="999"/>
                </item>
                <item type="boardgame" id="104">
                    <name type="primary" value="Another base game"/>
                    <link type="boardgameexpansion" id="371942"/>
                </item>
                <item type="boardgameexpansion" id="105">
                    <name type="primary" value="Wrong link type"/>
                    <link type="boardgamedesigner" id="371942"/>
                </item>
                <item type="boardgameexpansion" id="106">
                    <name type="primary" value="Missing backlink"/>
                </item>
            </items>
            XML);
    });

    $service = app(BggService::class);
    $expansions = $service->expansionsForGame(371942);

    expect($expansions)->toBe([
        [
            'bgg_id' => 102,
            'name' => 'Alpha',
            'year_published' => 2024,
            'image_url' => 'https://example.com/expansion.jpg',
            'thumbnail_url' => 'https://example.com/thumbnail.jpg',
            'bgg_url' => 'https://boardgamegeek.com/boardgame/102',
        ],
        [
            'bgg_id' => 101,
            'name' => 'zeta',
            'year_published' => null,
            'image_url' => null,
            'thumbnail_url' => null,
            'bgg_url' => 'https://boardgamegeek.com/boardgame/101',
        ],
    ]);
    expect($service->expansionsForGame(371942))->toBe($expansions);
    Http::assertSentCount(2);

    $this->travel(11)->hours();
    expect($service->expansionsForGame(371942))->toBe($expansions);
    Http::assertSentCount(2);

    $this->travel(2)->hours();
    expect($service->expansionsForGame(371942))->toBe($expansions);
    Http::assertSentCount(4);
});

test('a game without expansion links returns and caches an empty list after only the base request', function ($links) {
    Http::fake(['*' => Http::response('<items><item type="boardgame" id="371942">'.$links.'</item></items>')]);
    $service = app(BggService::class);

    expect($service->expansionsForGame(371942))->toBe([])
        ->and($service->expansionsForGame(371942))->toBe([]);
    Http::assertSentCount(1);
})->with([
    'no links' => [''],
    'unrelated links' => ['<link type="boardgamedesigner" id="1"/>'],
]);

test('a missing base game returns and caches an empty list', function () {
    Http::fake(['*' => Http::response('<items/>')]);
    $service = app(BggService::class);

    expect($service->expansionsForGame(371942))->toBe([])
        ->and($service->expansionsForGame(371942))->toBe([]);
    Http::assertSentCount(1);
});

test('expansions without a primary name use the first available name or the default', function () {
    Http::fakeSequence()
        ->push(<<<'XML'
            <items><item type="boardgame" id="371942">
                <link type="boardgameexpansion" id="101"/>
                <link type="boardgameexpansion" id="102"/>
            </item></items>
            XML)
        ->push(<<<'XML'
            <items>
                <item type="boardgameexpansion" id="101">
                    <name type="alternate" value="Matcha alternativo"/>
                    <yearpublished value="0"/>
                    <link type="boardgameexpansion" id="371942"/>
                </item>
                <item type="boardgameexpansion" id="102">
                    <link type="boardgameexpansion" id="371942"/>
                </item>
            </items>
            XML);

    $expansions = app(BggService::class)->expansionsForGame(371942);

    expect(array_column($expansions, 'name'))->toBe(['Expansión', 'Matcha alternativo']);
    expect(array_column($expansions, 'year_published'))->toBe([null, null]);
    Http::assertSentCount(2);
});

test('expansion detail requests are split into batches of no more than twenty IDs', function () {
    $chunks = [];
    Http::fake(function (Request $request) use (&$chunks) {
        if ($request['type'] === 'boardgame') {
            $links = '';

            foreach (range(1, 41) as $id) {
                $links .= '<link type="boardgameexpansion" id="'.$id.'"/>';
            }

            return Http::response('<items><item type="boardgame" id="371942">'.$links.'</item></items>');
        }

        $ids = array_map('intval', explode(',', $request['id']));
        $chunks[] = $ids;
        $items = '';

        foreach ($ids as $id) {
            $items .= '<item type="boardgameexpansion" id="'.$id.'">'
                .'<name type="primary" value="Expansion '.sprintf('%03d', $id).'"/>'
                .'<link type="boardgameexpansion" id="371942"/></item>';
        }

        return Http::response('<items>'.$items.'</items>');
    });

    $expansions = app(BggService::class)->expansionsForGame(371942);

    expect($chunks)->toBe([range(1, 20), range(21, 40), [41]])
        ->and(array_column($expansions, 'bgg_id'))->toBe(range(1, 41));
    Http::assertSentCount(4);
});

test('failed expansion requests are not cached as successful results', function () {
    $baseXml = '<items><item type="boardgame" id="371942"><link type="boardgameexpansion" id="101"/></item></items>';
    $expansionXml = '<items><item type="boardgameexpansion" id="101"><name type="primary" value="Matcha"/><link type="boardgameexpansion" id="371942"/></item></items>';
    Http::fakeSequence()->push($baseXml)->push('', 503)->push($baseXml)->push($expansionXml);
    $service = app(BggService::class);

    expect(fn () => $service->expansionsForGame(371942))
        ->toThrow(RuntimeException::class, 'BGG ha respondido con HTTP 503.');
    expect(array_column($service->expansionsForGame(371942), 'name'))->toBe(['Matcha']);
    Http::assertSentCount(4);
});
