<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('vox.database.connection', 'vox');
        DB::connection($connection)->table('vox_translations')->where('is_ignored', true)->update(['is_pending_delete' => true]);
        Schema::connection($connection)->table('vox_translations', function (Blueprint $table): void {
            $table->dropIndex(['is_ignored']);
            $table->dropColumn('is_ignored');
        });
    }

    public function down(): void
    {
        Schema::connection(config('vox.database.connection', 'vox'))->table('vox_translations', function (Blueprint $table): void {
            $table->boolean('is_ignored')->default(false)->index();
        });
        DB::connection(config('vox.database.connection', 'vox'))->table('vox_translations')->where('is_pending_delete', true)->update(['is_ignored' => true]);
    }
};
