<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_collections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('name');

            $table->boolean('is_default')
                ->default(false);

            $table->timestamps();

            $table->unique([
                'created_by',
                'name',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_collections');
    }
};
