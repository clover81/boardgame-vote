<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->uuid('submission_key')->nullable();
            $table->unique(
                ['created_by', 'submission_key'],
                'polls_submission_owner_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->dropUnique('polls_submission_owner_unique');
            $table->dropColumn('submission_key');
        });
    }
};
