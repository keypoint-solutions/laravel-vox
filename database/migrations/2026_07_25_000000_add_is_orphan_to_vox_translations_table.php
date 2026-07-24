<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->table('vox_translations', function (Blueprint $table): void {
                $table->boolean('is_orphan')->default(false)->after('is_frontend');
            });
    }

    public function down(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))
            ->table('vox_translations', function (Blueprint $table): void {
                $table->dropColumn('is_orphan');
            });
    }
};
