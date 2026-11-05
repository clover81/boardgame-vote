<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('game_mode', 255)
                ->collation('utf8mb4_unicode_ci')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('game_mode', 255)
                ->collation('utf8mb4_unicode_ci')
                ->nullable(false)
                ->change();
        });
    }
};
