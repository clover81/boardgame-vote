<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->longText('full_description')
                ->nullable()
                ->after('short_description');

            $table->string('description_source', 20)
                ->nullable()
                ->after('full_description');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn([
                'full_description',
                'description_source',
            ]);
        });
    }
};
