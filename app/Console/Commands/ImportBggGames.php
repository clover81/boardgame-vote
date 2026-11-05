<?php

namespace App\Console\Commands;

use App\Models\GameCollection;
use App\Services\BggCollectionImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportBggGames extends Command
{
    protected $signature = 'bgg:import-games
        {collection=3 : ID de la colección}
        {--size=20 : Juegos nuevos por lote}
        {--batches=20 : Número máximo de lotes}';

    protected $description =
    'Importa los juegos propios de BGG por lotes, sin duplicados';

    public function handle(BggCollectionImporter $importer): int
    {
        $collectionId = (int) $this->argument('collection');
        $size = (int) $this->option('size');
        $maxBatches = (int) $this->option('batches');

        if ($size < 1 || $size > 20 || $maxBatches < 1) {
            $this->error(
                'El tamaño debe estar entre 1 y 20 y los lotes deben ser positivos.'
            );

            return self::FAILURE;
        }

        $collection = GameCollection::find($collectionId);

        if (!$collection || !$collection->bgg_username) {
            $this->error(
                'La colección no existe o no está vinculada a BGG.'
            );

            return self::FAILURE;
        }

        $this->info(
            "Colección: {$collection->name}"
        );

        $this->line(
            "Cuenta BGG: {$collection->bgg_username}"
        );

        $totalImported = 0;

        for ($batch = 1; $batch <= $maxBatches; $batch++) {
            $this->newLine();
            $this->info("=== Lote {$batch} ===");

            try {
                $result = $importer->importGames(
                    $collection,
                    $size
                );
            } catch (Throwable $e) {
                $this->error(
                    'Sincronización interrumpida: ' . $e->getMessage()
                );

                $this->warn(
                    'Puedes volver a ejecutar el comando. Los juegos ya importados se conservarán.'
                );

                return self::FAILURE;
            }

            $imported = $result['imported'];
            $errors = $result['errors'];
            $pending = $result['remaining_before_import'];

            foreach ($imported as $game) {
                $this->line(
                    "✓ {$game['name']} (BGG {$game['bgg_id']})"
                );
            }

            $count = count($imported);
            $totalImported += $count;

            $this->info(
                "Importados en este lote: {$count}"
            );

            $this->line(
                'Pendientes estimados: ' .
                    max(0, $pending - $count)
            );

            if (count($errors) > 0) {
                foreach ($errors as $error) {
                    $this->error(
                        "BGG {$error['bgg_id']}: {$error['error']}"
                    );
                }

                $this->warn(
                    'Hay errores. Se detiene para evitar repetir consultas innecesarias.'
                );

                return self::FAILURE;
            }

            if ($pending <= $count) {
                $this->newLine();
                $this->info(
                    "Importación finalizada. Nuevos juegos: {$totalImported}"
                );

                return self::SUCCESS;
            }

            if ($count === 0) {
                $this->warn(
                    'No se han importado juegos nuevos. Proceso detenido.'
                );

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info(
            "Juegos importados en esta ejecución: {$totalImported}"
        );

        $this->warn(
            'Se alcanzó el máximo de lotes. Puedes ejecutar el comando otra vez para continuar.'
        );

        return self::SUCCESS;
    }
}
