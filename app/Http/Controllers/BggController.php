<?php

namespace App\Http\Controllers;

use App\Models\Expansion;
use App\Models\Game;
use App\Services\BggService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BggController extends Controller
{
  public function search(
    Request $request,
    BggService $bgg
  ): JsonResponse {
    $validated = $request->validate([
      'q' => [
        'required',
        'string',
        'min:2',
        'max:100',
      ],
    ]);

    try {
      $results = $bgg->search(
        $validated['q']
      );

      return response()->json([
        'results' => $results,
      ]);
    } catch (RuntimeException $exception) {
      report($exception);

      return response()->json([
        'message' =>
        'No se ha podido consultar BoardGameGeek.',
      ], 502);
    }
  }

  public function expansions(
    Request $request,
    Game $game,
    BggService $bgg
  ): JsonResponse {
    $userId = (int) $request->user()->id;

    $belongsToUser = DB::table('game_collection_game as gcg')
      ->join('game_collections as gc', 'gc.id', '=', 'gcg.game_collection_id')
      ->where('gcg.game_id', $game->id)
      ->where('gc.created_by', $userId)
      ->exists();

    abort_unless($belongsToUser, 403);

    if (!$game->bgg_id) {
      return response()->json([
        'message' => 'El juego no está vinculado a BGG.',
      ], 422);
    }

    try {
      $expansions = $bgg->expansionsForGame($game->bgg_id);

      /*
       * Indicamos cuáles ya tenemos guardadas para este juego.
       */
      $importedIds = $game
        ->expansions()
        ->whereNotNull('bgg_id')
        ->pluck('bgg_id')
        ->map(fn ($id) => (int) $id);

      $expansions = collect($expansions)
        ->map(function (array $expansion) use ($importedIds) {
          $expansion['already_imported'] = $importedIds->contains($expansion['bgg_id']);

          return $expansion;
        })
        ->values();

      return response()->json([
        'expansions' => $expansions,
      ]);
    } catch (RuntimeException $exception) {
      report($exception);

      return response()->json([
        'message' => 'No se han podido consultar las expansiones en BoardGameGeek.',
      ], 502);
    }
  }

  public function importExpansion(
    Request $request,
    Game $game,
    int $bggId,
    BggService $bgg
  ): RedirectResponse {
    $userId = (int) $request->user()->id;

    $belongsToUser = DB::table('game_collection_game as gcg')
      ->join('game_collections as gc', 'gc.id', '=', 'gcg.game_collection_id')
      ->where('gcg.game_id', $game->id)
      ->where('gc.created_by', $userId)
      ->exists();

    abort_unless($belongsToUser, 403);

    if (!$game->bgg_id) {
      return back()->withErrors([
        'bgg_expansion' => 'El juego base no está vinculado con BGG.',
      ]);
    }

    try {
      $available = collect($bgg->expansionsForGame($game->bgg_id));
      $data = $available->firstWhere('bgg_id', $bggId);

      if (!$data) {
        return back()->withErrors([
          'bgg_expansion' => 'BoardGameGeek no reconoce esta expansión para este juego.',
        ]);
      }

      /*
       * Primero por bgg_id.
       */
      $expansion = Expansion::query()
        ->where('bgg_id', $bggId)
        ->first();

      if ($expansion && $expansion->game_id !== $game->id) {
        return back()->withErrors([
          'bgg_expansion' => 'Esta expansión ya está asociada a otro juego.',
        ]);
      }

      /*
       * Compatibilidad con expansiones creadas manualmente anteriormente.
       */
      if (!$expansion) {
        $expansion = Expansion::query()
          ->where('game_id', $game->id)
          ->whereNull('bgg_id')
          ->where('name', $data['name'])
          ->first();
      }

      if ($expansion) {
        $expansion->bgg_id = $data['bgg_id'];

        // Conservamos los valores introducidos manualmente.
        if (! $expansion->image_url) {
          $expansion->image_url = $data['image_url'];
        }

        if (! $expansion->bgg_url) {
          $expansion->bgg_url = $data['bgg_url'];
        }

        $expansion->is_active = true;
        $expansion->save();

        return back()->with('success', 'La expansión existente se ha vinculado con BoardGameGeek.');
      }

      Expansion::create([
        'game_id' => $game->id,
        'bgg_id' => $data['bgg_id'],
        'name' => $data['name'],
        'image_url' => $data['image_url'],
        'bgg_url' => $data['bgg_url'],
        'youtube_url' => null,
        'notes' => null,
        'is_active' => true,
      ]);

      return back()->with('success', 'Expansión importada desde BoardGameGeek.');
    } catch (RuntimeException $exception) {
      report($exception);

      return back()->withErrors([
        'bgg_expansion' => 'No se ha podido importar la expansión desde BoardGameGeek.',
      ]);
    }
  }

  public function show(
    Request $request,
    int $bggId,
    BggService $bgg
  ): JsonResponse {
    if ($bggId < 1) {
      abort(404);
    }

    try {
      $game = $bgg->game($bggId);

      /*
             * Nos sirve para avisar en la interfaz
             * si este juego ya existe en nuestra BD.
             */
      $existingGame = Game::query()
        ->where('bgg_id', $bggId)
        ->first([
          'id',
          'name',
        ]);

      /*
       * Si todavía no tiene BGG ID, buscamos un juego
       * del usuario con exactamente el mismo nombre.
       *
       * Esto cubre los juegos creados manualmente antes
       * de implementar la integración con BGG.
       */
      $legacyGame = null;

      if (!$existingGame) {
        $legacyGame = Game::query()
          ->whereNull('bgg_id')
          ->where('name', $game['name'])
          ->whereHas(
            'collections',
            function ($query) use ($request) {
              $query->where(
                'game_collections.created_by',
                $request->user()->id
              );
            }
          )
          ->first([
            'id',
            'name',
          ]);
      }

      return response()->json([
        'game' => $game,

        'existing_game' =>
        $existingGame
          ? [
            'id' =>
            $existingGame->id,

            'name' =>
            $existingGame->name,
          ]
          : null,

        'legacy_game' => $legacyGame
          ? [
            'id' => $legacyGame->id,
            'name' => $legacyGame->name,
          ]
          : null,
      ]);
    } catch (RuntimeException $exception) {
      report($exception);

      return response()->json([
        'message' =>
        'No se han podido obtener los datos del juego desde BoardGameGeek.',
      ], 502);
    }
  }

  public function searchForExistingGame(
      Request $request,
      Game $game,
      BggService $bgg
  ): JsonResponse {
      $this->authorizeUserGame(
          $request,
          $game
      );

      $validated =
          $request->validate([
              'q' => [
                  'nullable',
                  'string',
                  'max:255',
              ],
          ]);

      $query =
          trim(
              $validated['q']
              ?? $game->name
          );

      if ($query === '') {
          return response()->json([
              'results' => [],
          ]);
      }

      try {
          return response()->json([
              'results' =>
                  $bgg->searchForLink(
                      $query
                  ),
          ]);
      } catch (\Throwable $exception) {
          report($exception);

          return response()->json(
              [
                  'message' =>
                      'No se ha podido buscar el juego en BoardGameGeek.',
              ],
              502
          );
      }
  }

  public function linkExistingGame(
      Request $request,
      Game $game,
      int $bggId,
      BggService $bgg
  ): RedirectResponse {
      $this->authorizeUserGame(
          $request,
          $game
      );

      /*
       * Ese BGG ID no puede pertenecer
       * ya a otro Game.
       */
      $duplicate =
          Game::query()
              ->where(
                  'bgg_id',
                  $bggId
              )
              ->where(
                  'id',
                  '<>',
                  $game->id
              )
              ->first();

      if ($duplicate) {
          return back()
              ->withErrors([
                  'bgg_link' =>
                      "Ese juego de BGG ya está vinculado a «{$duplicate->name}».",
              ]);
      }

      try {
          $data =
              $bgg->gameForLink(
                  $bggId
              );
      } catch (\Throwable $exception) {
          report($exception);

          return back()
              ->withErrors([
                  'bgg_link' =>
                      'No se han podido obtener los datos de BoardGameGeek.',
              ]);
      }

      DB::transaction(
          function () use (
              $game,
              $bggId,
              $data
          ) {
              /*
               * Estos dos sí identifican
               * el vínculo BGG.
               */
              $game->bgg_id =
                  $bggId;

              $game->bgg_url =
                  $data['bgg_url'];

              /*
               * El resto SOLO rellena
               * valores que estén vacíos.
               */

              if (
                  !$game->image_url
                  &&
                  $data['image_url']
              ) {
                  $game->image_url =
                      $data['image_url'];
              }

              if (
                  $game->min_players === null
                  &&
                  $data['min_players'] !== null
              ) {
                  $game->min_players =
                      $data['min_players'];
              }

              if (
                  $game->max_players === null
                  &&
                  $data['max_players'] !== null
              ) {
                  $game->max_players =
                      $data['max_players'];
              }

              if (
                  $game->min_playtime === null
                  &&
                  $data['min_playtime'] !== null
              ) {
                  $game->min_playtime =
                      $data['min_playtime'];
              }

              if (
                  $game->max_playtime === null
                  &&
                  $data['max_playtime'] !== null
              ) {
                  $game->max_playtime =
                      $data['max_playtime'];
              }

              if (
                  $game->complexity === null
                  &&
                  $data['complexity'] !== null
              ) {
                  $game->complexity =
                      $data['complexity'];
              }

              /*
               * NO tocamos:
               *
               * name
               * game_mode
               * descripción
               * información adicional
               * youtube
               * ideales
               * colecciones
               * partidas
               */
              $game->save();
          }
      );

      return back()->with(
          'success',
          'Juego vinculado con BoardGameGeek. Se han completado los datos que faltaban.'
      );
  }

  private function authorizeUserGame(
      Request $request,
      Game $game
  ): void {
      $belongsToUser =
          DB::table(
              'game_collection_game as gcg'
          )
              ->join(
                  'game_collections as gc',
                  'gc.id',
                  '=',
                  'gcg.game_collection_id'
              )
              ->where(
                  'gcg.game_id',
                  $game->id
              )
              ->where(
                  'gc.created_by',
                  $request->user()->id
              )
              ->exists();

      abort_unless(
          $belongsToUser,
          403
      );
  }

}
