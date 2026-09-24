<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Structure findings now count toward slop_score (capped); the other column records the score without them.
        Schema::table('scans', function (Blueprint $table) {
            $table->renameColumn('slop_score_with_structure', 'slop_score_without_structure');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->renameColumn('slop_score_without_structure', 'slop_score_with_structure');
        });
    }
};
