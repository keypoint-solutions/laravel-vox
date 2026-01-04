<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->create('vox_translations', function (Blueprint $table): void {
                $table->id();
                $table->string('key');
                $table->string('group')->nullable();
                $table->boolean('is_frontend')->default(false);
                $table->string('source')->nullable();
                $table->string('status')->default('pending');
                $table->timestamps();

                $table->unique(['key', 'group']);
            });
    }

    public function down(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->dropIfExists('vox_translations');
    }
};
