<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_collections', function (Blueprint $table) {
            $table->string('bgg_username', 100)
                ->nullable();

            $table->unique(
                ['created_by', 'bgg_username'],
                'game_collections_owner_bgg_username_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('game_collections', function (Blueprint $table) {
            $table->dropUnique(
                'game_collections_owner_bgg_username_unique'
            );

            $table->dropColumn('bgg_username');
        });
    }
};
