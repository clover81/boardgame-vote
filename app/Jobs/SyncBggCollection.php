<?php

namespace App\Jobs;

use App\Models\GameCollection;
use App\Services\BggCollectionImporter;
use App\Services\BggCollectionService;
use App\Services\BggExpansionImporter;
use App\Services\BggRetryCooldown;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SyncBggCollection implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    // No lanzar dos sincronizaciones simultáneas
    // para la misma colección.
    public int $uniqueFor = 3600;

    // Una sincronización completa puede tardar.
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $collectionId
    ) {}

    public function uniqueId(): string
    {
        return 'bgg-collection-' . $this->collectionId;
    }

    public function displayName(): string
    {
        return 'SyncBggCollection '.$this->uniqueId();
    }

    public function handle(
        BggCollectionImporter $gamesImporter,
        BggExpansionImporter $expansionsImporter,
        BggCollectionService $collections
    ): void {
        $collection = GameCollection::findOrFail(
            $this->collectionId
        );

        if (!$collection->bgg_username) {
            throw new RuntimeException(
                'La colección no tiene una cuenta BGG vinculada.'
            );
        }

        Cache::forget($collection->bggPreviewCacheKey());

        $report = [
            'games_added' => [],
            'expansions_added' => [],
            'expansions_pending' => [],
            'games_errors' => [],
            'expansions_errors' => [],
        ];

        $collection->forceFill([
            'bgg_sync_status' => 'running',
            'bgg_sync_started_at' => now(),
            'bgg_sync_error' => null,
            'bgg_sync_games_added' => 0,
            'bgg_sync_expansions_added' => 0,
            'bgg_sync_expansions_pending' => 0,
            'bgg_sync_report' => $report,
        ])->save();

        $preview = $collections->preview(trim((string) $collection->bgg_username));

        $totalGames = 0;
        $completed = false;

        // Importar juegos base por lotes.
        for ($batch = 1; $batch <= 20; $batch++) {
            $result = $gamesImporter->importGames(
                $collection,
                20,
                $preview
            );

            $imported = count($result['imported']);
            $totalGames += $imported;

            if ($imported > 0 || !empty($result['errors'])) {
                $report['games_added'] = array_merge(
                    $report['games_added'],
                    $result['imported']
                );

                $report['games_errors'] = array_merge(
                    $report['games_errors'],
                    $result['errors']
                );

                $collection->forceFill([
                    'bgg_sync_games_added' => $totalGames,
                    'bgg_sync_report' => $report,
                ])->save();
            }

            if (!empty($result['errors'])) {
                throw new RuntimeException(
                    'Error importando juegos BGG: '
                        . json_encode($result['errors'])
                );
            }

            if ($result['remaining_before_import'] <= $imported) {
                $completed = true;
                break;
            }

            if ($imported === 0) {
                throw new RuntimeException(
                    'La importación no ha avanzado.'
                );
            }
        }

        if (!$completed) {
            throw new RuntimeException(
                'Se alcanzó el límite de lotes.'
            );
        }

        // Importar únicamente las expansiones
        // que el usuario posee en BGG.
        $expansions = $expansionsImporter->run(
            $collection,
            false,
            $preview
        );

        $totalExpansions = count($expansions['imported']);

        $report['expansions_added'] = $expansions['imported'];
        $report['expansions_pending'] = $expansions['pending'];
        $report['expansions_errors'] = $expansions['errors'];

        $collection->forceFill([
            'bgg_sync_expansions_added' => $totalExpansions,
            'bgg_sync_expansions_pending' =>
                count($expansions['pending']),
            'bgg_sync_report' => $report,
        ])->save();

        if (!empty($expansions['errors'])) {
            throw new RuntimeException(
                'Error importando expansiones BGG: '
                    . json_encode($expansions['errors'])
            );
        }

        $collection->forceFill([
            'bgg_sync_status' => 'completed',
            'bgg_last_synced_at' => now(),
            'bgg_sync_error' => null,
            'bgg_sync_games_added' => $totalGames,
            'bgg_sync_expansions_added' =>
                count($expansions['imported']),
        ])->save();

        Cache::forget($collection->bggPreviewCacheKey());

        Log::info('Sincronización BGG completada', [
            'collection_id' => $collection->id,
            'username' => $collection->bgg_username,
            'games_imported' => $totalGames,
            'expansions_imported' =>
            count($expansions['imported']),
            'expansions_pending' =>
            count($expansions['pending']),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage()
            ?? 'La sincronización ha fallado.';

        if (str_contains($message, '429')) {
            $collection = GameCollection::find($this->collectionId);

            if ($collection) {
                app(BggRetryCooldown::class)->register($collection);
            }
        }

        GameCollection::query()
            ->whereKey($this->collectionId)
            ->update([
                'bgg_sync_status' => 'failed',
                'bgg_sync_error' => mb_substr($message, 0, 1000),
            ]);

        Log::error('Sincronización BGG fallida', [
            'collection_id' => $this->collectionId,
            'error' => $message,
        ]);
    }
}
