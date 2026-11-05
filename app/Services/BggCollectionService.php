<?php

namespace App\Services;

use App\Models\Expansion;
use App\Models\Game;
use App\Models\GameCollection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class BggCollectionService
{
    public function preview(string $username): array
    {
        $games = $this->fetchOwned($username, [
            'excludesubtype' => 'boardgameexpansion',
        ]);

        // Espaciamos las peticiones a BGG.
        sleep(5);

        $expansions = $this->fetchOwned($username, [
            'subtype' => 'boardgameexpansion',
        ]);

        // Identificadores ya registrados en Laravel.
        $localGames = Game::query()
            ->whereIn('bgg_id', array_column($games, 'bgg_id'))
            ->pluck('bgg_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        $localExpansions = Expansion::query()
            ->whereIn('bgg_id', array_column($expansions, 'bgg_id'))
            ->pluck('bgg_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        // Identificar juegos nuevos y existentes.
        $games = array_map(
            fn (array $game) => [
                ...$game,
                'status' => isset($localGames[$game['bgg_id']])
                    ? 'existing'
                    : 'new',
            ],
            $games
        );

        $expansions = array_map(
            fn (array $expansion) => [
                ...$expansion,
                'status' => isset($localExpansions[$expansion['bgg_id']])
                    ? 'existing'
                    : 'new',
            ],
            $expansions
        );

        return [
            'summary' => [
                'games' => count($games),
                'games_existing' => count($localGames),
                'games_new' => count($games) - count($localGames),

                'expansions' => count($expansions),
                'expansions_existing' => count($localExpansions),
                'expansions_new' =>
                    count($expansions) - count($localExpansions),
            ],
            'games' => $games,
            'expansions' => $expansions,
        ];
    }

    public function removalCandidates(
        GameCollection $collection,
        ?array $preview = null
    ): array {
        $username = trim((string) $collection->bgg_username);

        if ($username === '') {
            throw new RuntimeException(
                'La colección no está vinculada a BGG.'
            );
        }

        // Reutilizar la consulta cuando ya esté disponible.
        $preview ??= $this->preview($username);

        // Identificadores de juegos que actualmente
        // aparecen como propios en BGG.
        $ownedIds = array_fill_keys(
            array_map(
                'intval',
                array_column($preview['games'], 'bgg_id')
            ),
            true
        );

        // Revisar exclusivamente los juegos de esta colección.
        // Nunca afecta a otras colecciones ni al historial.
        return $collection->games()
            ->whereNotNull('games.bgg_id')
            ->get(['games.id', 'games.bgg_id', 'games.name'])
            ->filter(
                fn ($game) =>
                    !isset($ownedIds[(int) $game->bgg_id])
            )
            ->map(fn ($game) => [
                'id' => $game->id,
                'bgg_id' => (int) $game->bgg_id,
                'name' => $game->name,
            ])
            ->values()
            ->all();
    }

    public function expansionRemovalCandidates(
        GameCollection $collection,
        ?array $preview = null
    ): array {
        $username = trim(
            (string) $collection->bgg_username
        );

        if ($username === '') {
            throw new RuntimeException(
                'La colección no está vinculada a BGG.'
            );
        }

        // Reutilizar los datos si ya se han consultado.
        $preview ??= $this->preview($username);

        // Expansiones que actualmente tenemos en BGG.
        $ownedIds = array_fill_keys(
            array_map(
                'intval',
                array_column($preview['expansions'], 'bgg_id')
            ),
            true
        );

        // Buscar únicamente las expansiones asociadas
        // a esta colección de Laravel.
        return $collection->expansions()
            ->whereNotNull('expansions.bgg_id')
            ->get([
                'expansions.id',
                'expansions.bgg_id',
                'expansions.name',
                'expansions.game_id',
            ])
            ->filter(
                fn ($expansion) =>
                    !isset($ownedIds[(int) $expansion->bgg_id])
            )
            ->map(fn ($expansion) => [
                'id' => $expansion->id,
                'bgg_id' => (int) $expansion->bgg_id,
                'name' => $expansion->name,
                'game_id' => $expansion->game_id,
            ])
            ->values()
            ->all();
    }

    private function fetchOwned(
        string $username,
        array $filters
    ): array {
        $token = (string) config('services.bgg.token');

        if ($token === '') {
            throw new RuntimeException(
                'No hay token de BGG configurado.'
            );
        }

        // BGG puede devolver HTTP 202 mientras prepara
        // la colección. Esperamos y reintentamos automáticamente.
        $maxAttempts = 8;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = Http::withToken($token)
                ->accept('application/xml')
                ->timeout(60)
                ->get('https://boardgamegeek.com/xmlapi2/collection', [
                    'username' => $username,
                    'own' => 1,
                    ...$filters,
                ]);

            // La colección ya está disponible o se ha producido
            // una respuesta distinta de 202.
            if ($response->status() !== 202) {
                break;
            }

            // Dar tiempo a BGG para preparar los datos.
            if ($attempt < $maxAttempts) {
                sleep(10);
            }
        }

        if ($response->status() === 202) {
            throw new RuntimeException(
                'BGG sigue preparando la colección tras varios intentos. '
                . 'Se podrá volver a sincronizar más adelante.'
            );
        }

        if (!$response->successful()) {
            throw new RuntimeException(
                'BGG ha respondido con HTTP '
                . $response->status()
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
                    'BGG no ha devuelto una colección XML válida.'
                );
            }

            $items = [];

            foreach ($xml->item as $item) {
                $id = (int) $item['objectid'];

                if ($id > 0) {
                    $items[$id] = [
                        'bgg_id' => $id,
                        'name' => trim((string) $item->name),
                    ];
                }
            }

            return array_values($items);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
