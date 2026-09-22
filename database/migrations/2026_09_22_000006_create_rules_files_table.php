<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('target_editor', 32);
            $table->string('filename');
            $table->longText('body');
            $table->timestamps();

            $table->unique(['scan_id', 'target_editor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rules_files');
    }
};
