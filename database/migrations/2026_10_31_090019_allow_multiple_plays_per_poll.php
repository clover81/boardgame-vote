<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            // Dejamos poll_id indexado para la relación,
            // pero ya no será UNIQUE.
            $table->index(
                'poll_id',
                'plays_poll_id_non_unique_index'
            );

            $table->dropUnique(
                'plays_poll_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->unique(
                'poll_id',
                'plays_poll_id_unique'
            );

            $table->dropIndex(
                'plays_poll_id_non_unique_index'
            );
        });
    }
};
