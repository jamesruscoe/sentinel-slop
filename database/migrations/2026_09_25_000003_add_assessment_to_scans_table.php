<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            // The reviewer's assessment: summary prose, strengths, structural problems, recommended refactors.
            $table->json('assessment')->nullable()->after('synthesis_payload');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn('assessment');
        });
    }
};
