<?php

namespace Tests\Feature;

use App\Services\BggService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BggSearchImagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bgg.token', 'token-de-prueba');
        config()->set('services.bgg.base_url', 'https://boardgamegeek.com/xmlapi2');

        Cache::forget('bgg-search-with-images-v1-' . md5('terraforming mars imagen test'));
        Cache::forget('bgg-search-with-images-v1-' . md5('terraforming mars fallback test'));
    }

    public function test_search_adds_covers_with_one_batched_request(): void
    {
        Http::fake([
            '*/search*' => Http::response(<<<'XML'
<items><item type="boardgame" id="167791"><name type="primary" value="Terraforming Mars" /><yearpublished value="2016" /></item><item type="boardgame" id="328871"><name type="primary" value="Terraforming Mars: Ares Expedition" /><yearpublished value="2021" /></item></items>
XML, 200),
            '*/thing*' => Http::response(<<<'XML'
<items><item type="boardgame" id="167791"><thumbnail>https://example.org/1-small.jpg</thumbnail><image>https://example.org/1.jpg</image></item><item type="boardgame" id="328871"><thumbnail>https://example.org/2-small.jpg</thumbnail><image>https://example.org/2.jpg</image></item></items>
XML, 200),
        ]);

        $results = app(BggService::class)->search('Terraforming Mars imagen TEST');

        $this->assertCount(2, $results);
        $this->assertSame('https://example.org/1-small.jpg', $results[0]['thumbnail_url']);
        $this->assertSame('https://example.org/2.jpg', $results[1]['image_url']);
        $this->assertSame('https://boardgamegeek.com/boardgame/167791', $results[0]['bgg_url']);

        // La segunda llamada es un único lote, no una llamada por juego.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/thing')
            && str_contains(rawurldecode($request->url()), 'id=167791,328871'));

        // Las búsquedas iguales reutilizan la respuesta de caché.
        $this->assertSame($results, app(BggService::class)->search('Terraforming Mars imagen TEST'));
        Http::assertSentCount(2);
    }

    public function test_search_still_returns_results_if_cover_request_fails(): void
    {
        Http::fake([
            '*/search*' => Http::response(<<<'XML'
<items><item type="boardgame" id="167791"><name type="primary" value="Terraforming Mars" /><yearpublished value="2016" /></item></items>
XML, 200),
            '*/thing*' => Http::response('Unavailable', 503),
        ]);

        $results = app(BggService::class)->search('Terraforming Mars fallback TEST');

        $this->assertCount(1, $results);
        $this->assertSame('Terraforming Mars', $results[0]['name']);
        $this->assertNull($results[0]['thumbnail_url']);
        $this->assertNull($results[0]['image_url']);
        Http::assertSentCount(2);
    }
}
