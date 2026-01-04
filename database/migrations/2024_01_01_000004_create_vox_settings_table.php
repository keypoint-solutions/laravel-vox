<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->create('vox_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->text('value');
                $table->timestamps();
            });
    }

    public function down(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->dropIfExists('vox_settings');
    }
};
