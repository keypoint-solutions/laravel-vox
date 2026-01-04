<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->create('vox_audits', function (Blueprint $table): void {
                $table->id();
                $table->string('action');
                $table->json('context')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('created_at');
            });
    }

    public function down(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->dropIfExists('vox_audits');
    }
};
