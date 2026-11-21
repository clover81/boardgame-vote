<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('game_collections', function (Blueprint $table) {
            $table->unsignedInteger('bgg_sync_expansions_pending')
                ->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('game_collections', function (Blueprint $table) {
            $table->dropColumn('bgg_sync_expansions_pending');
        });
    }
};
