<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('catalog_share_token')
                ->nullable()
                ->unique();
        });

        Schema::table('game_collections', function (Blueprint $table) {
            $table->boolean('is_public')
                ->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('game_collections', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_catalog_share_token_unique');
            $table->dropColumn('catalog_share_token');
        });
    }
};
