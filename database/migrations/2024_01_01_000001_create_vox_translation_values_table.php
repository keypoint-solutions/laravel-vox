<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->create('vox_translation_values', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('translation_id')->constrained('vox_translations')->cascadeOnDelete();
                $table->string('locale');
                $table->text('value');
                $table->boolean('is_obsolete')->default(false);
                $table->timestamps();

                $table->unique(['translation_id', 'locale']);
            });
    }

    public function down(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->dropIfExists('vox_translation_values');
    }
};
