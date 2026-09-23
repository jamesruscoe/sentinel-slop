<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            // Structural profile (paths, counts, names the parser found; no code) from ProfileRepository.
            $table->json('profile')->nullable()->after('skipped_files');
            // The slop score when Structure (absence) findings are counted; slop_score excludes them until that is decided.
            $table->unsignedTinyInteger('slop_score_with_structure')->nullable()->after('slop_score');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn(['profile', 'slop_score_with_structure']);
        });
    }
};
