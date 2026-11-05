<?php

namespace App\Services;

use App\Models\Expansion;
use App\Models\GameCollection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class BggExpansionImporter
{
  public function __construct(
    private BggCollectionService $collections
  ) {}

  public function run(
    GameCollection $collection,
    bool $dryRun = true
  ): array {
    $username = trim((string) $collection->bgg_username);

    if ($username === '') {
      throw new RuntimeException(
        'La colección no está vinculada a BGG.'
      );
    }

    // Consultar la colección real del usuario.
    $preview = $this->collections->preview($username);

    $ownedExpansions = $preview['expansions'];

    $ownedBaseIds = array_fill_keys(
      array_map(
        'intval',
        array_column($preview['games'], 'bgg_id')
      ),
      true
    );

    // Juegos que pertenecen a esta colección
    // y siguen marcados como propios en BGG.
    $localBases = [];

    foreach (
      $collection->games()
        ->get(['games.id', 'games.bgg_id', 'games.name'])
      as $game
    ) {
      $bggId = (int) $game->bgg_id;

      if ($bggId > 0 && isset($ownedBaseIds[$bggId])) {
        $localBases[$bggId] = [
          'id' => $game->id,
          'name' => $game->name,
        ];
      }
    }

    // Obtener detalles de todas las expansiones
    // en grupos de 20 identificadores.
    $ids = array_column($ownedExpansions, 'bgg_id');
    $details = $this->fetchDetails($ids);

    $result = [
      'total_bgg' => count($ownedExpansions),
      'ready' => [],
      'imported' => [],
      'existing' => [],
      'pending' => [],
      'errors' => [],
    ];

    foreach ($ownedExpansions as $item) {
      $bggId = (int) $item['bgg_id'];

      // Respetar expansiones registradas previamente.
      $existingExpansion = Expansion::where('bgg_id', $bggId)->first();

      if ($existingExpansion) {
        // Asociar la expansión a esta colección,
        // sin duplicarla ni modificar su ficha.
        // En modo simulación no se guarda nada.
        if (!$dryRun) {
          $collection->expansions()->syncWithoutDetaching([
            $existingExpansion->id,
          ]);
        }

        $result['existing'][] = [
          'bgg_id' => $bggId,
          'name' => $item['name'],
        ];

        continue;
      }

      if (!isset($details[$bggId])) {
        $result['pending'][] = [
          'bgg_id' => $bggId,
          'name' => $item['name'],
          'reason' => 'BGG no devolvió detalles.',
        ];

        continue;
      }

      $detail = $details[$bggId];

      // Elegir únicamente juegos base que son propios
      // y que están registrados en esta colección.
      $matchingBases = [];

      foreach ($detail['parent_bgg_ids'] as $parentId) {
        if (isset($localBases[$parentId])) {
          $matchingBases[$parentId] = $localBases[$parentId];
        }
      }

      if (count($matchingBases) !== 1) {
        $result['pending'][] = [
          'bgg_id' => $bggId,
          'name' => $item['name'],
          'reason' => count($matchingBases) === 0
            ? 'No se encontró un juego base propio.'
            : 'Hay varios juegos base posibles.',
          'possible_bases' => array_keys($matchingBases),
        ];

        continue;
      }

      $parentId = array_key_first($matchingBases);
      $parent = $matchingBases[$parentId];

      $name = trim(
        $detail['name'] ?: $item['name']
      );

      $entry = [
        'bgg_id' => $bggId,
        'name' => $name,
        'game_id' => $parent['id'],
        'base_game' => $parent['name'],
      ];

      if ($dryRun) {
        $result['ready'][] = $entry;
        continue;
      }

      try {
        // No sobrescribir expansiones existentes.
        $expansion = Expansion::firstOrCreate(
          ['bgg_id' => $bggId],
          [
            'game_id' => $parent['id'],
            'name' => $name,
            'image_url' => $detail['image_url'],
            'bgg_url' =>
            "https://boardgamegeek.com/boardgameexpansion/{$bggId}",
            'is_active' => true,
          ]
        );

        // Asociar también las expansiones recién importadas.
        // syncWithoutDetaching evita relaciones duplicadas.
        $collection->expansions()->syncWithoutDetaching([
          $expansion->id,
        ]);

        $result['imported'][] = [
          ...$entry,
          'id' => $expansion->id,
        ];
      } catch (Throwable $e) {
        $result['errors'][] = [
          ...$entry,
          'error' => $e->getMessage(),
        ];
      }
    }

    return $result;
  }

  private function fetchDetails(array $ids): array
  {
    $token = (string) config('services.bgg.token');

    if ($token === '') {
      throw new RuntimeException(
        'No hay token de BGG configurado.'
      );
    }

    $details = [];

    foreach (array_chunk($ids, 20) as $index => $chunk) {
      if ($index > 0) {
        sleep(5);
      }

      $response = Http::withToken($token)
        ->accept('application/xml')
        ->timeout(60)
        ->get(
          'https://boardgamegeek.com/xmlapi2/thing',
          ['id' => implode(',', $chunk)]
        );

      if (!$response->successful()) {
        throw new RuntimeException(
          'Error al consultar expansiones BGG. HTTP '
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
            'BGG no devolvió un XML válido.'
          );
        }

        foreach ($xml->item as $item) {
          $id = (int) $item['id'];

          if ($id <= 0) {
            continue;
          }

          $name = '';

          foreach ($item->name as $nameElement) {
            if (
              (string) $nameElement['type'] === 'primary'
            ) {
              $name = (string) $nameElement['value'];
              break;
            }
          }

          $parents = [];

          foreach ($item->link as $link) {
            if (
              (string) $link['type']
              !== 'boardgameexpansion'
            ) {
              continue;
            }

            if (
              (string) $link['inbound'] !== 'true'
            ) {
              continue;
            }

            $parentId = (int) $link['id'];

            if ($parentId > 0) {
              $parents[$parentId] = $parentId;
            }
          }

          $details[$id] = [
            'name' => $name,
            'image_url' =>
            trim((string) $item->image) ?: null,
            'parent_bgg_ids' =>
            array_values($parents),
          ];
        }
      } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
      }
    }

    return $details;
  }
}
