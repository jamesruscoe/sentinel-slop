<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            // Enclosing class/function resolved from the AST, e.g. App\Services\InvoiceService::render()
            $table->string('symbol', 512)->nullable()->after('line');
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropColumn('symbol');
        });
    }
};
