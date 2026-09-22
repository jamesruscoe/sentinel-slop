<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 64);
            $table->string('rule_id')->nullable();
            $table->string('category', 48);
            $table->string('severity', 16);
            $table->string('file_path', 1024);
            $table->unsignedInteger('line')->nullable();
            $table->text('message');
            $table->text('snippet')->nullable();
            $table->timestamps();

            $table->index(['scan_id', 'severity']);
            $table->index(['scan_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
