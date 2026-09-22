<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_repo_id')->unique();
            $table->string('full_name');
            $table->string('default_branch')->nullable();
            $table->boolean('is_private')->default(false);
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
