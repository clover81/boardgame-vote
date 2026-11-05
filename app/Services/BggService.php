<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class BggService
{
  private string $baseUrl;

  private string $token;

  public function __construct()
  {
    $this->baseUrl = rtrim(
      (string) config('services.bgg.base_url'),
      '/'
    );

    $this->token = (string) config(
      'services.bgg.token'
    );

    if ($this->token === '') {
      throw new RuntimeException(
        'BGG_API_TOKEN no está configurado.'
      );
    }
  }

  /**
   * Buscar juegos por nombre.
   */
  public function search(string $query): array
  {
    $query = trim($query);

    if ($query === '') {
      return [];
    }

    return Cache::remember(
      'bgg-search-' . md5(mb_strtolower($query)),
      now()->addMinutes(30),
      function () use ($query) {
        $xml = $this->request(
          '/search',
          [
            'query' => $query,
            'type' => 'boardgame',
          ]
        );

        $results = [];

        foreach ($xml->item as $item) {
          $primaryName = null;

          foreach ($item->name as $name) {
            if (
              (string) $name['type']
              === 'primary'
            ) {
              $primaryName =
                (string) $name['value'];

              break;
            }
          }

          if (!$primaryName) {
            continue;
          }

          $results[] = [
            'bgg_id' =>
            (int) $item['id'],

            'name' =>
            $primaryName,

            'year' =>
            isset(
              $item->yearpublished['value']
            )
              ? (int) $item
                ->yearpublished['value']
              : null,
          ];
        }

        return array_slice(
          $results,
          0,
          20
        );
      }
    );
  }

  /**
   * Obtener los datos principales de un juego.
   */
  public function game(int $bggId): array
  {
    return Cache::remember(
      "bgg-game-v2-{$bggId}",
      now()->addDay(),
      function () use ($bggId) {
        $xml = $this->request(
          '/thing',
          [
            'id' => $bggId,
            'type' => 'boardgame',
            'stats' => 1,
          ]
        );

        $item =
          $xml->item[0] ?? null;

        if (!$item) {
          throw new RuntimeException(
            'BGG no ha devuelto el juego solicitado.'
          );
        }

        $name = '';

        foreach ($item->name as $nameNode) {
          if (
            (string) $nameNode['type']
            === 'primary'
          ) {
            $name =
              (string) $nameNode['value'];

            break;
          }
        }

        $complexity = null;

        if (
          isset(
            $item
              ->statistics
              ->ratings
              ->averageweight['value']
          )
        ) {
          $complexity =
            round(
              (float) $item
                ->statistics
                ->ratings
                ->averageweight['value'],
              2
            );
        }

        $suggestedIdealPlayerCounts =
          $this->suggestedIdealPlayerCounts($item);

        return [
          'bgg_id' =>
          (int) $item['id'],

          'name' =>
          $name,

          'image_url' =>
          $this->nullableString(
            $item->image ?? null
          ),

          'thumbnail_url' =>
          $this->nullableString(
            $item->thumbnail ?? null
          ),

          'year_published' =>
          $this->attributeInt(
            $item->yearpublished ?? null
          ),

          'min_players' =>
          $this->attributeInt(
            $item->minplayers ?? null
          ),

          'max_players' =>
          $this->attributeInt(
            $item->maxplayers ?? null
          ),

          'min_playtime' =>
          $this->attributeInt(
            $item->minplaytime ?? null
          ),

          'max_playtime' =>
          $this->attributeInt(
            $item->maxplaytime ?? null
          ),

          'playing_time' =>
          $this->attributeInt(
            $item->playingtime ?? null
          ),

          'complexity' =>
          $complexity,

          'suggested_ideal_player_counts' =>
          $suggestedIdealPlayerCounts,

          /*
                     * Guardamos la descripción como
                     * referencia, pero no sustituye
                     * nuestra descripción corta en español.
                     */
          'description' =>
          html_entity_decode(
            (string) (
              $item->description
              ?? ''
            ),
            ENT_QUOTES |
              ENT_HTML5,
            'UTF-8'
          ),

          'bgg_url' =>
          'https://boardgamegeek.com/boardgame/'
            . $bggId,
        ];
      }
    );
  }

  public function expansionsForGame(int $baseBggId): array
  {
    return Cache::remember(
      "bgg-expansions-{$baseBggId}",
      now()->addHours(12),
      function () use ($baseBggId) {
        /*
         * Primero obtenemos el juego base.
         */
        $xml = $this->request('/thing', [
          'id' => $baseBggId,
          'type' => 'boardgame',
        ]);

        $baseItem = $xml->item[0] ?? null;

        if (!$baseItem) {
          return [];
        }

        /*
         * IDs relacionados con el juego mediante boardgameexpansion.
         */
        $candidateIds = [];

        foreach ($baseItem->link as $link) {
          if ((string) $link['type'] !== 'boardgameexpansion') {
            continue;
          }

          $id = (int) $link['id'];

          if ($id > 0) {
            $candidateIds[] = $id;
          }
        }

        $candidateIds = array_values(array_unique($candidateIds));

        if (!$candidateIds) {
          return [];
        }

        $expansions = [];

        /*
         * Consultamos los detalles en lotes de hasta 20 elementos.
         */
        foreach (array_chunk($candidateIds, 20) as $chunk) {
          $expansionXml = $this->request('/thing', [
            'id' => implode(',', $chunk),
            'type' => 'boardgameexpansion',
          ]);

          foreach ($expansionXml->item as $item) {
            if (
              (string) $item['type'] !== 'boardgameexpansion' ||
              !$this->itemLinksToGame($item, $baseBggId)
            ) {
              continue;
            }

            $expansions[] = $this->mapExpansion($item);
          }
        }

        usort(
          $expansions,
          fn (array $a, array $b) => strcasecmp($a['name'], $b['name'])
        );

        return $expansions;
      }
    );
  }

  private function request(
    string $path,
    array $query = []
  ): SimpleXMLElement {
    $response = Http::withToken(
      $this->token
    )
      ->accept('application/xml')
      ->timeout(20)
      ->get(
        $this->baseUrl . $path,
        $query
      );

    if ($response->failed()) {
      throw new RuntimeException(
        'BGG ha respondido con HTTP '
          . $response->status()
          . '.'
      );
    }

    $previousXmlErrorHandling =
      libxml_use_internal_errors(true);

    try {
      $xml = simplexml_load_string(
        $response->body()
      );

      if ($xml === false) {
        throw new RuntimeException(
          'No se ha podido interpretar la respuesta de BGG.'
        );
      }

      return $xml;
    } finally {
      libxml_clear_errors();

      libxml_use_internal_errors(
        $previousXmlErrorHandling
      );
    }
  }

  private function attributeInt(
    mixed $node
  ): ?int {
    if (
      !$node ||
      !isset($node['value'])
    ) {
      return null;
    }

    $value = (string) $node['value'];

    return $value !== ''
      ? (int) $value
      : null;
  }

  private function nullableString(
    mixed $node
  ): ?string {
    if (!$node) {
      return null;
    }

    $value = trim(
      (string) $node
    );

    return $value !== ''
      ? $value
      : null;
  }

  private function suggestedIdealPlayerCounts(
    SimpleXMLElement $item
  ): array {
    $idealCounts = [];

    foreach ($item->poll as $poll) {
      if ((string) $poll['name'] !== 'suggested_numplayers') {
        continue;
      }

      foreach ($poll->results as $results) {
        $numPlayers = (string) $results['numplayers'];

        /*
         * BGG también devuelve valores como 4+, 5+ o 6+.
         * Nuestra aplicación trabaja con números exactos.
         */
        if (!ctype_digit($numPlayers)) {
          continue;
        }

        $best = 0;
        $recommended = 0;
        $notRecommended = 0;

        foreach ($results->result as $result) {
          $value = (string) $result['value'];
          $votes = (int) $result['numvotes'];

          if ($value === 'Best') {
            $best = $votes;
          }

          if ($value === 'Recommended') {
            $recommended = $votes;
          }

          if ($value === 'Not Recommended') {
            $notRecommended = $votes;
          }
        }

        /*
         * Solo es ideal cuando Best gana claramente
         * para ese número de jugadores.
         */
        if (
          $best > 0 &&
          $best > $recommended &&
          $best > $notRecommended
        ) {
          $idealCounts[] = (int) $numPlayers;
        }
      }

      break;
    }

    sort($idealCounts);

    return array_values(array_unique($idealCounts));
  }

  private function itemLinksToGame(
    SimpleXMLElement $item,
    int $baseBggId
  ): bool {
    foreach ($item->link as $link) {
      if ((string) $link['type'] !== 'boardgameexpansion') {
        continue;
      }

      if ((int) $link['id'] === $baseBggId) {
        return true;
      }
    }

    return false;
  }

  private function mapExpansion(
    SimpleXMLElement $item
  ): array {
    $name = null;

    foreach ($item->name as $nameNode) {
      if ((string) $nameNode['type'] === 'primary') {
        $name = (string) $nameNode['value'];
        break;
      }
    }

    $name ??= (string) ($item->name[0]['value'] ?? 'Expansión');

    $bggId = (int) $item['id'];

    return [
      'bgg_id' => $bggId,
      'name' => $name,
      'year_published' => $this->attributeInt($item->yearpublished ?? null) ?: null,
      'image_url' => $this->nullableString($item->image ?? null),
      'thumbnail_url' => $this->nullableString($item->thumbnail ?? null),
      'bgg_url' => 'https://boardgamegeek.com/boardgame/' . $bggId,
    ];
  }

  public function searchForLink(string $value): array
  {
      $value = trim($value);

      /*
       * Si introduce directamente un ID
       * o una URL de BGG, vamos al juego.
       */
      $directId = $this->extractBggIdForLink($value);

      if ($directId) {
          $game = $this->gameForLink($directId);

          return [[
              'id' => $game['id'],
              'name' => $game['name'],
              'year_published' => $game['year_published'],
          ]];
      }

      $xml = $this->requestXmlForLink(
          'search',
          [
              'query' => $value,
              'type' => 'boardgame',
          ]
      );

      $results = [];

      foreach ($xml->item as $item) {
          $name = null;

          foreach ($item->name as $candidateName) {
              if (
                  (string) $candidateName['type']
                  === 'primary'
              ) {
                  $name =
                      (string) $candidateName['value'];

                  break;
              }
          }

          $name ??=
              (string) ($item->name[0]['value'] ?? '');

          if ($name === '') {
              continue;
          }

          $year = null;

          if (
              isset(
                  $item->yearpublished['value']
              )
          ) {
              $year =
                  (int) $item
                      ->yearpublished['value'];
          }

          $results[] = [
              'id' =>
                  (int) $item['id'],

              'name' =>
                  $name,

              'year_published' =>
                  $year,
          ];
      }

      return array_slice(
          $results,
          0,
          12
      );
  }


  public function gameForLink(
      int $bggId
  ): array {
      $xml = $this->requestXmlForLink(
          'thing',
          [
              'id' => $bggId,
              'type' => 'boardgame',
              'stats' => 1,
          ]
      );

      $item =
          $xml->item[0] ?? null;

      if (!$item) {
          throw new RuntimeException(
              'BGG no ha devuelto el juego solicitado.'
          );
      }

      $name = null;

      foreach ($item->name as $candidateName) {
          if (
              (string) $candidateName['type']
              === 'primary'
          ) {
              $name =
                  (string) $candidateName['value'];

              break;
          }
      }

      $name ??=
          (string) ($item->name[0]['value'] ?? '');

      $image =
          trim(
              (string) $item->image
          );

      $year = null;

      if (
          isset(
              $item->yearpublished['value']
          )
      ) {
          $year =
              (int) $item
                  ->yearpublished['value'];
      }

      $complexity = null;

      if (
          isset(
              $item
                  ->statistics
                  ->ratings
                  ->averageweight['value']
          )
      ) {
          $rawComplexity =
              (float) $item
                  ->statistics
                  ->ratings
                  ->averageweight['value'];

          if ($rawComplexity > 0) {
              $complexity =
                  round(
                      $rawComplexity,
                      2
                  );
          }
      }

      return [
          'id' =>
              $bggId,

          'name' =>
              $name,

          'year_published' =>
              $year,

          'image_url' =>
              $image !== ''
                  ? $image
                  : null,

          'min_players' =>
              isset(
                  $item->minplayers['value']
              )
                  ? (int)
                      $item
                          ->minplayers['value']
                  : null,

          'max_players' =>
              isset(
                  $item->maxplayers['value']
              )
                  ? (int)
                      $item
                          ->maxplayers['value']
                  : null,

          'min_playtime' =>
              isset(
                  $item->minplaytime['value']
              )
                  ? (int)
                      $item
                          ->minplaytime['value']
                  : null,

          'max_playtime' =>
              isset(
                  $item->maxplaytime['value']
              )
                  ? (int)
                      $item
                          ->maxplaytime['value']
                  : null,

          'complexity' =>
              $complexity,

          'bgg_url' =>
              "https://boardgamegeek.com/boardgame/{$bggId}",
      ];
  }


  private function extractBggIdForLink(
      string $value
  ): ?int {
      if (ctype_digit($value)) {
          return (int) $value;
      }

      // El ID normal tiene prioridad sobre los números que pueda contener el slug.
      if (preg_match('~boardgame/(\d+)~i', $value, $matches)) {
          return (int) $matches[1];
      }

      if (preg_match('~boardgame/[^/]+/(\d+)~i', $value, $matches)) {
          return (int) $matches[1];
      }

      return null;
  }


  private function requestXmlForLink(
      string $endpoint,
      array $query = []
  ): \SimpleXMLElement {
      $baseUrl =
          rtrim(
              (string) config(
                  'services.bgg.base_url',
                  'https://boardgamegeek.com/xmlapi2'
              ),
              '/'
          );

      $token =
          trim(
              (string) config(
                  'services.bgg.token'
              )
          );

      $request =
          Http::accept(
              'application/xml'
          )
              ->timeout(25);

      if ($token !== '') {
          $request =
              $request->withToken(
                  $token
              );
      }

      $response =
          $request->get(
              "{$baseUrl}/{$endpoint}",
              $query
          );

      if (!$response->successful()) {
          throw new RuntimeException(
              "Error BGG HTTP {$response->status()}."
          );
      }

      $previousXmlErrorHandling = libxml_use_internal_errors(true);

      try {
          $xml = simplexml_load_string($response->body());

          if ($xml === false) {
              throw new RuntimeException('La respuesta de BGG no contiene XML válido.');
          }

          return $xml;
      } finally {
          libxml_clear_errors();
          libxml_use_internal_errors($previousXmlErrorHandling);
      }
  }

}
