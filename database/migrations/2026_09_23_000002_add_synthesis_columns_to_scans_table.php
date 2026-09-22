<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->unsignedInteger('suppression_count')->nullable()->after('lines_of_code');
            $table->decimal('suppression_density', 8, 2)->nullable()->after('suppression_count');
            $table->text('synthesis_error')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn(['suppression_count', 'suppression_density', 'synthesis_error']);
        });
    }
};
