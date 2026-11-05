<?php

namespace App\Http\Controllers;

use App\Services\BggService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use App\Models\Game;

class BggImportController extends Controller
{
    public function search(
        Request $request,
        BggService $bggService
    ): JsonResponse {
        $validated = $request->validate([
            'query' => [
                'required',
                'string',
                'min:2',
                'max:255',
            ],
        ]);

        try {
            $results = $bggService->searchGames($validated['query']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'query' => $exception->getMessage(),
            ]);
        }

        $bggIds = collect($results)
            ->pluck('bgg_id')
            ->values();

        $existingGames = Game::query()
            ->whereIn('bgg_id', $bggIds)
            ->get(['id', 'bgg_id'])
            ->keyBy('bgg_id');

        $results = collect($results)
            ->map(function (array $result) use ($existingGames) {
                $existing = $existingGames->get($result['bgg_id']);

                return [
                    ...$result,
                    'existing_game_id' => $existing?->id,
                ];
            })
            ->values();

        return response()->json([
            'results' => $results,
        ]);
    }

    public function preview(
        Request $request,
        BggService $bggService
    ): JsonResponse {
        $validated =
            $request->validate([
                'url' => [
                    'required',
                    'string',
                    'max:2048',
                ],
            ]);

        $input = trim($validated['url']);
        $bggId = null;

        // Permitir introducir directamente el ID.
        if (ctype_digit($input)) {
            $bggId = (int) $input;
        } else {
            // O una URL válida de BoardGameGeek.
            $parts = parse_url($input);

            if (is_array($parts)) {
                $host = strtolower($parts['host'] ?? '');
                $scheme = strtolower($parts['scheme'] ?? '');
                $path = $parts['path'] ?? '';

                if (
                    in_array($scheme, ['http', 'https'], true) &&
                    in_array($host, [
                        'boardgamegeek.com',
                        'www.boardgamegeek.com',
                    ], true) &&
                    preg_match(
                        '~^/boardgame/([1-9]\d*)(?:/|$)~',
                        $path,
                        $matches
                    )
                ) {
                    $bggId = (int) $matches[1];
                }
            }
        }

        if (!$bggId) {
            throw ValidationException::withMessages([
                'url' => 'Introduce una URL válida de BoardGameGeek o un ID numérico.',
            ]);
        }

        try {
            // Métodos actuales del servicio BGG.
            $game = $bggService->game($bggId);

            $game['expansions'] =
                $bggService->expansionsForGame($bggId);
        } catch (RuntimeException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'url' => 'No se han podido obtener los datos desde BoardGameGeek.',
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | ¿YA EXISTE?
    |--------------------------------------------------------------------------
    */

        $existingGame =
            Game::query()
            ->where(
                'bgg_id',
                $game['bgg_id']
            )
            ->orWhere(
                'bgg_url',
                $game['bgg_url']
            )
            ->orWhere(
                'bgg_url',
                'like',
                '%/boardgame/'
                    . $game['bgg_id']
                    . '/%'
            )
            ->first();

        /*
     * Si encontramos un juego antiguo por URL
     * pero aún no tenía bgg_id, lo completamos.
     */
        if (
            $existingGame &&
            $existingGame->bgg_id === null
        ) {
            $idAlreadyUsed =
                Game::query()
                ->where(
                    'bgg_id',
                    $game['bgg_id']
                )
                ->whereKeyNot(
                    $existingGame->id
                )
                ->exists();

            if (!$idAlreadyUsed) {
                $existingGame->update([
                    'bgg_id' =>
                    $game['bgg_id'],
                ]);
            }
        }

        $existingGameData =
            null;

        if ($existingGame) {
            $existingGame->load([
                'collections' =>
                function ($query) use (
                    $request
                ) {
                    $query
                        ->where(
                            'created_by',
                            $request
                                ->user()
                                ->id
                        )
                        ->orderByDesc(
                            'is_default'
                        )
                        ->orderBy('name');
                },
            ]);

            $existingGameData = [
                'id' =>
                $existingGame->id,

                'name' =>
                $existingGame->name,

                'image_url' =>
                $existingGame->image_url,

                'bgg_id' =>
                $existingGame->bgg_id,

                /*
             * Colecciones de ESTE usuario.
             */
                'collection_ids' =>
                $existingGame
                    ->collections
                    ->pluck('id')
                    ->values(),

                'collections' =>
                $existingGame
                    ->collections
                    ->map(
                        fn($collection) => [
                            'id' =>
                            $collection->id,

                            'name' =>
                            $collection->name,
                        ]
                    )
                    ->values(),
            ];
        }

        return response()->json([
            'game' =>
            $game,

            'existing_game' =>
            $existingGameData,
        ]);
    }
}
