<?php

namespace App\Services;

use App\Jobs\GenerateGameDescription;
use App\Models\Game;
use App\Models\GameCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BggCollectionImporter
{
  public function __construct(
    private BggCollectionService $collections,
    private BggService $bgg
  ) {}

  public function importGames(
    GameCollection $collection,
    int $limit = 5
  ): array {
    if ($limit < 1 || $limit > 20) {
      throw new InvalidArgumentException(
        'El lote debe contener entre 1 y 20 juegos.'
      );
    }

    $username = trim((string) $collection->bgg_username);

    if ($username === '') {
      throw new RuntimeException(
        'La colección no está vinculada a BGG.'
      );
    }

    // Consultar únicamente los juegos que el usuario posee.
    $preview = $this->collections->preview($username);
    $ownedGames = $preview['games'];

    // Asociar los juegos ya existentes sin modificar sus fichas.
    $ownedBggIds = array_column($ownedGames, 'bgg_id');

    $existingIds = Game::query()
      ->whereIn('bgg_id', $ownedBggIds)
      ->pluck('id')
      ->all();

    $collection->games()->syncWithoutDetaching($existingIds);

    // Seleccionar únicamente juegos que aún no están en Laravel.
    $newGames = array_values(array_filter(
      $ownedGames,
      fn(array $item) => $item['status'] === 'new'
    ));

    $batch = array_slice($newGames, 0, $limit);

    $result = [
      'existing' => count($existingIds),
      'imported' => [],
      'errors' => [],
      'remaining_before_import' => count($newGames),
    ];

    foreach ($batch as $index => $item) {
      $bggId = (int) $item['bgg_id'];

      // Evitar demasiadas peticiones seguidas a BGG.
      if ($index > 0) {
        sleep(3);
      }

      try {
        $details = $this->bgg->game($bggId);

        if (
          !is_array($details) ||
          (int) ($details['bgg_id'] ?? 0) !== $bggId
        ) {
          throw new RuntimeException(
            'BGG no ha devuelto los datos esperados.'
          );
        }

        $description = strip_tags(
          html_entity_decode(
            (string) ($details['description'] ?? ''),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
          )
        );

        $description = trim(
          preg_replace('/\s+/u', ' ', $description) ?? ''
        );

        $name = trim(
          (string) ($details['name'] ?? $item['name'])
        );

        if ($name === '') {
          throw new RuntimeException(
            'El juego no tiene nombre.'
          );
        }

        $game = DB::transaction(function () use (
          $collection,
          $bggId,
          $details,
          $name,
          $description
        ) {
          // Nunca sobrescribir fichas ya existentes.
          $game = Game::query()->firstOrCreate(
            ['bgg_id' => $bggId],
            [
              'name' => $name,
              'image_url' => $details['image_url'] ?? null,
              'min_players' => $details['min_players'] ?? null,
              'max_players' => $details['max_players'] ?? null,
              'min_playtime' => $details['min_playtime'] ?? null,
              'max_playtime' => $details['max_playtime'] ?? null,
              'complexity' => $details['complexity'] ?? null,
              'game_modes' => [],
              'short_description' => $description !== ''
                ? Str::limit($description, 240)
                : null,
              'description_source' => 'bgg',
              'bgg_url' => $details['bgg_url']
                ?? "https://boardgamegeek.com/boardgame/{$bggId}",
              'is_active' => true,
            ]
          );

          if (
            $game->wasRecentlyCreated
            && trim($description) !== ''
          ) {
            GenerateGameDescription::dispatch(
              $game->id,
              (int) $game->bgg_id,
              $description
            )
              ->onQueue('descriptions')
              ->afterCommit();
          }

          $collection->games()->syncWithoutDetaching([
            $game->id,
          ]);

          // Guardar los jugadores ideales indicados por BGG.
          // Solo para juegos recién creados: nunca sobrescribimos
          // las preferencias configuradas manualmente.
          if ($game->wasRecentlyCreated) {
            $idealCounts = $details['suggested_ideal_player_counts'] ?? [];

            $minPlayers = max(1, (int) ($details['min_players'] ?? 1));
            $maxPlayers = (int) ($details['max_players'] ?? 99);

            foreach (array_unique(array_map('intval', $idealCounts)) as $count) {
              if ($count >= $minPlayers && $count <= $maxPlayers) {
                $game->idealPlayerCounts()->create([
                  'player_count' => $count,
                ]);
              }
            }
          }

          return $game;
        });

        $result['imported'][] = [
          'id' => $game->id,
          'bgg_id' => $bggId,
          'name' => $game->name,
        ];
      } catch (Throwable $exception) {
        $result['errors'][] = [
          'bgg_id' => $bggId,
          'name' => $item['name'],
          'error' => $exception->getMessage(),
        ];
      }
    }

    return $result;
  }
}
