<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->create('vox_environments', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('type');
                $table->string('url');
                $table->string('secret_key');
                $table->timestamps();
            });
    }

    public function down(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->dropIfExists('vox_environments');
    }
};
