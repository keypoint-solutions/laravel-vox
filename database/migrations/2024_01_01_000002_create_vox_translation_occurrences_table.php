<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->create('vox_translation_occurrences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('translation_id')->constrained('vox_translations')->cascadeOnDelete();
                $table->string('file_path');
                $table->unsignedInteger('line_number')->nullable();
                $table->text('context_before')->nullable();
                $table->text('context_after')->nullable();
                $table->timestamps();
            });
    }

    public function down(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->dropIfExists('vox_translation_occurrences');
    }
};
