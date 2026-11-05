<?php

namespace App\Console\Commands;

use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportGameDescriptions extends Command
{
    protected $signature = 'games:descriptions-import
                            {file : Ruta al archivo JSON de descripciones}
                            {--apply : Guardar los cambios (sin esta opción, solo vista previa)}';

    protected $description = 'Previsualiza o importa descripciones en español sin sobrescribir ediciones manuales';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (!str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        if (!is_file($path) || !is_readable($path)) {
            $this->error("No se puede leer el archivo: {$path}");
            return self::FAILURE;
        }

        try {
            $items = json_decode(
                file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($items) || !array_is_list($items) || $items === []) {
                throw new RuntimeException('El JSON debe contener una lista no vacía de juegos.');
            }

            $apply = (bool) $this->option('apply');
            $result = DB::transaction(function () use ($items, $apply): array {
                $processed = [];
                $ready = [];
                $alreadyImported = [];

                foreach ($items as $index => $item) {
                    if (
                        !is_array($item) ||
                        !is_int($item['id'] ?? null) ||
                        !is_int($item['bgg_id'] ?? null) ||
                        !is_string($item['name'] ?? null) ||
                        !is_string($item['expected_short_description_current'] ?? null) ||
                        !is_string($item['short_description'] ?? null) ||
                        !is_string($item['full_description'] ?? null)
                    ) {
                        throw new RuntimeException('Formato inválido en el elemento ' . ($index + 1));
                    }

                    $id = $item['id'];
                    if ($id <= 0 || $item['bgg_id'] <= 0 || isset($processed[$id])) {
                        throw new RuntimeException("ID inválido o duplicado: {$id}");
                    }
                    $processed[$id] = true;

                    if (
                        trim($item['short_description']) === '' ||
                        trim($item['full_description']) === ''
                    ) {
                        throw new RuntimeException("Descripción vacía en ID {$id}");
                    }

                    $query = Game::query()->whereKey($id);
                    $game = $apply ? $query->lockForUpdate()->first() : $query->first();

                    if (
                        !$game ||
                        (int) $game->bgg_id !== $item['bgg_id'] ||
                        $game->name !== $item['name']
                    ) {
                        throw new RuntimeException("No coinciden el juego o BGG: ID {$id}");
                    }

                    if (
                        $game->description_source === 'generated' &&
                        $game->short_description === $item['short_description'] &&
                        $game->full_description === $item['full_description']
                    ) {
                        $alreadyImported[] = $game->name;
                        continue;
                    }

                    if (
                        $game->description_source !== 'bgg' ||
                        $game->short_description !== $item['expected_short_description_current'] ||
                        trim((string) $game->full_description) !== ''
                    ) {
                        throw new RuntimeException(
                            "Protegido o modificado desde la exportación: {$game->name} (ID {$id})"
                        );
                    }

                    $ready[] = $game->name;

                    if ($apply) {
                        $game->update([
                            'short_description' => $item['short_description'],
                            'full_description' => $item['full_description'],
                            'description_source' => 'generated',
                        ]);
                    }
                }

                return ['ready' => $ready, 'already' => $alreadyImported];
            });

            $this->info('Juegos revisados: ' . count($items));
            $this->line('Aptos para actualizar: ' . count($result['ready']));
            $this->line('Ya importados sin cambios: ' . count($result['already']));
            foreach ($result['ready'] as $name) {
                $this->line('  - ' . $name);
            }
            $this->info($apply ? 'CAMBIOS GUARDADOS.' : 'SOLO VISTA PREVIA. Base de datos intacta.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());
            $this->warn('No se ha aplicado ninguna actualización de este lote.');
            return self::FAILURE;
        }
    }
}
