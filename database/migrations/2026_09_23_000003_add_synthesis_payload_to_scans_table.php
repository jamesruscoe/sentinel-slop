<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            // Exactly what was sent to the LLM (system + user prompt), so users can see it.
            $table->longText('synthesis_payload')->nullable()->after('synthesis_error');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn('synthesis_payload');
        });
    }
};
