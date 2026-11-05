<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_collections', function (Blueprint $table) {
            $table->string('bgg_sync_status', 20)
                ->default('idle');

            $table->timestamp('bgg_sync_started_at')
                ->nullable();

            $table->timestamp('bgg_last_synced_at')
                ->nullable();

            $table->text('bgg_sync_error')
                ->nullable();

            $table->unsignedInteger('bgg_sync_games_added')
                ->default(0);

            $table->unsignedInteger('bgg_sync_expansions_added')
                ->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('game_collections', function (Blueprint $table) {
            $table->dropColumn([
                'bgg_sync_status',
                'bgg_sync_started_at',
                'bgg_last_synced_at',
                'bgg_sync_error',
                'bgg_sync_games_added',
                'bgg_sync_expansions_added',
            ]);
        });
    }
};
