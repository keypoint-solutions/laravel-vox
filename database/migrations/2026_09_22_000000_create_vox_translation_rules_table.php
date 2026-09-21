<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))->create('vox_translation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 35);
            $table->string('scope', 10);
            $table->string('target', 64);
            $table->string('group')->nullable();
            $table->text('key')->nullable();
            $table->string('mode', 15)->default('inherit');
            $table->string('published_mode', 15)->default('inherit');
            $table->timestamps();
            $table->unique(['locale', 'scope', 'target']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))->dropIfExists('vox_translation_rules');
    }
};
