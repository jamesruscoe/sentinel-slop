<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            // Analysers that timed out or crashed during this scan: the pipeline continues without them and says so.
            $table->json('analyser_failures')->nullable()->after('skipped_files');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn('analyser_failures');
        });
    }
};
