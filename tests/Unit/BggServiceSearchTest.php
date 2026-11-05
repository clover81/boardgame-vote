<?php

use App\Services\BggService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    config([
        'services.bgg.base_url' =>
            'https://boardgamegeek.com/xmlapi2',
        'services.bgg.token' => 'test-token',
        'cache.default' => 'array',
    ]);

    Cache::flush();
    Http::preventStrayRequests();
});

test(
    'empty searches return no results without making requests',
    function ($query) {
        expect(
            app(BggService::class)->search($query)
        )->toBe([]);

        Http::assertNothingSent();
    }
)->with([
    'empty' => '',
    'spaces' => '   ',
]);

test(
    'a configured token is required for BGG requests',
    function () {
        config([
            'services.bgg.token' => null,
        ]);

        expect(
            fn () => app(BggService::class)
        )->toThrow(
            RuntimeException::class,
            'BGG_API_TOKEN no está configurado.'
        );

        Http::assertNothingSent();
    }
);

test(
    'BGG searches map primary names and years',
    function () {
        Http::fake([
            '*' => Http::response(<<<'XML'
                <items>
                    <item
                        type="boardgame"
                        id="1"
                    >
                        <name
                            type="primary"
                            value="Heat"
                        />
                        <yearpublished
                            value="2022"
                        />
                    </item>

                    <item
                        type="boardgame"
                        id="2"
                    >
                        <name
                            type="alternate"
                            value="Otro nombre"
                        />
                        <name
                            type="primary"
                            value="Heat: Heavy Rain"
                        />
                    </item>

                    <item
                        type="boardgame"
                        id="3"
                    >
                        <name
                            type="alternate"
                            value="Sin nombre principal"
                        />
                    </item>
                </items>
                XML),
        ]);

        $results =
            app(BggService::class)
                ->search(' Heat ');

        expect($results)->toBe([
            [
                'bgg_id' => 1,
                'name' => 'Heat',
                'year' => 2022,
            ],
            [
                'bgg_id' => 2,
                'name' => 'Heat: Heavy Rain',
                'year' => null,
            ],
        ]);

        Http::assertSent(
            fn (Request $request) =>
                $request['query'] === 'Heat'
                && $request['type'] === 'boardgame'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer test-token'
                )
                && $request->hasHeader(
                    'Accept',
                    'application/xml'
                )
        );
    }
);

test(
    'BGG search results are capped at twenty',
    function () {
        $items = '';

        for ($id = 1; $id <= 25; $id++) {
            $items .=
                '<item type="boardgame" id="'
                . $id
                . '"><name type="primary" value="Game '
                . $id
                . '"/></item>';
        }

        Http::fake([
            '*' => Http::response(
                '<items>'
                . $items
                . '</items>'
            ),
        ]);

        $results =
            app(BggService::class)
                ->search('Game');

        expect($results)
            ->toHaveCount(20);

        expect(
            array_column(
                $results,
                'bgg_id'
            )
        )->toBe(range(1, 20));
    }
);

test(
    'search caching ignores surrounding spaces and case and expires after thirty minutes',
    function () {
        Http::fake([
            '*' => Http::response(
                '<items/>'
            ),
        ]);

        $service =
            app(BggService::class);

        expect(
            $service->search(' Heat ')
        )->toBe([]);

        expect(
            $service->search('HEAT')
        )->toBe([]);

        Http::assertSentCount(1);

        $this->travel(29)->minutes();

        $service->search('heat');

        Http::assertSentCount(1);

        $this->travel(2)->minutes();

        $service->search('Heat');

        Http::assertSentCount(2);
    }
);

test(
    'invalid XML produces the current BGG parsing error',
    function () {
        Http::fake([
            '*' => Http::response(
                '<items>'
            ),
        ]);

        expect(
            fn () =>
                app(BggService::class)
                    ->search('Heat')
        )->toThrow(
            RuntimeException::class,
            'No se ha podido interpretar la respuesta de BGG.'
        );

        Http::assertSentCount(1);
    }
);

test(
    'unsuccessful HTTP responses produce the current BGG HTTP error',
    function () {
        Http::fake([
            '*' => Http::response(
                '',
                503
            ),
        ]);

        expect(
            fn () =>
                app(BggService::class)
                    ->search('Heat')
        )->toThrow(
            RuntimeException::class,
            'BGG ha respondido con HTTP 503.'
        );

        Http::assertSentCount(1);
    }
);
