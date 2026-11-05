<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poll_participants', function (Blueprint $table) {
            $table->string('vote_token', 64)
                ->nullable(false)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('poll_participants', function (Blueprint $table) {
            $table->string('vote_token', 64)
                ->nullable()
                ->change();
        });
    }
};
