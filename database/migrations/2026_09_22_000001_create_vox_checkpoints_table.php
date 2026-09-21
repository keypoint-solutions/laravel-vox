<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))->create('vox_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->boolean('is_manual')->default(false);
            $table->longText('changes');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))->dropIfExists('vox_checkpoints');
    }
};
