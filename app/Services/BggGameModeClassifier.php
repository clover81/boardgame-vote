<?php

namespace App\Services;

use App\Models\GameCollection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class BggGameModeClassifier
{
    public function preview(GameCollection $collection): array
    {
        if (!$collection->bgg_username) {
            throw new RuntimeException(
                'La colección no está vinculada a BGG.'
            );
        }

        // Solamente juegos de esta colección
        // que todavía no tienen modalidad asignada.
        $games = $collection->games()
            ->whereNull('games.game_mode')
            ->whereNotNull('games.bgg_id')
            ->get([
                'games.id',
                'games.name',
                'games.bgg_id',
            ]);

        $result = [
            'cooperative' => [],
            'semi_cooperative' => [],
            'review' => [],
            'unavailable' => [],
        ];

        $token = (string) config('services.bgg.token');

        if ($token === '') {
            throw new RuntimeException(
                'No hay token de BGG configurado.'
            );
        }

        foreach ($games->chunk(20) as $index => $batch) {
            if ($index > 0) {
                sleep(5);
            }

            $ids = $batch->pluck('bgg_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $response = null;

            // BGG puede devolver 202 o limitar las peticiones.
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $response = Http::withToken($token)
                    ->accept('application/xml')
                    ->timeout(60)
                    ->get(
                        'https://boardgamegeek.com/xmlapi2/thing',
                        ['id' => implode(',', $ids)]
                    );

                if (!in_array(
                    $response->status(),
                    [202, 429, 503],
                    true
                )) {
                    break;
                }

                if ($attempt < 5) {
                    sleep(10);
                }
            }

            if (!$response || !$response->successful()) {
                throw new RuntimeException(
                    'Error consultando BGG: HTTP '
                    . ($response?->status() ?? 'desconocido')
                );
            }

            $previous = libxml_use_internal_errors(true);

            try {
                $xml = simplexml_load_string(
                    $response->body(),
                    SimpleXMLElement::class,
                    LIBXML_NONET
                );

                if ($xml === false || $xml->getName() !== 'items') {
                    throw new RuntimeException(
                        'BGG no devolvió un XML válido.'
                    );
                }

                $localGames = $batch->keyBy('bgg_id');
                $receivedIds = [];

                foreach ($xml->item as $item) {
                    $bggId = (int) $item['id'];

                    $game = $localGames->get($bggId);

                    if (!$game) {
                        continue;
                    }

                    $receivedIds[$bggId] = true;

                    $mechanics = [];

                    foreach ($item->link as $link) {
                        if (
                            (string) $link['type']
                            === 'boardgamemechanic'
                        ) {
                            $mechanics[] = (string) $link['value'];
                        }
                    }

                    $hasSemi = in_array(
                        'Semi-Cooperative Game',
                        $mechanics,
                        true
                    );

                    $hasCooperative = in_array(
                        'Cooperative Game',
                        $mechanics,
                        true
                    );

                    // Semicooperativo tiene prioridad.
                    if ($hasSemi) {
                        $category = 'semi_cooperative';
                    } elseif ($hasCooperative) {
                        $category = 'cooperative';
                    } else {
                        $category = 'review';
                    }

                    $result[$category][] = [
                        'id' => $game->id,
                        'bgg_id' => $bggId,
                        'name' => $game->name,
                    ];
                }

                // No confundir juegos omitidos por BGG
                // con juegos sin mecánicas cooperativas.
                foreach ($batch as $game) {
                    if (!isset($receivedIds[(int) $game->bgg_id])) {
                        $result['unavailable'][] = [
                            'id' => $game->id,
                            'bgg_id' => (int) $game->bgg_id,
                            'name' => $game->name,
                        ];
                    }
                }

            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        $result['summary'] = [
            'total' => $games->count(),
            'cooperative' => count($result['cooperative']),
            'semi_cooperative' =>
                count($result['semi_cooperative']),
            'review' => count($result['review']),
            'unavailable' => count($result['unavailable']),
        ];

        return $result;
    }
}
