<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->json('game_modes')
                ->nullable()
                ->after('game_mode');
        });

        // Copiar las clasificaciones existentes
        // al nuevo campo, sin modificar game_mode.
        DB::table('games')
            ->whereNotNull('game_mode')
            ->select('id', 'game_mode')
            ->orderBy('id')
            ->chunkById(100, function ($games) {
                foreach ($games as $game) {
                    DB::table('games')
                        ->where('id', $game->id)
                        ->update([
                            'game_modes' => json_encode([
                                $game->game_mode,
                            ]),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('game_modes');
        });
    }
};
