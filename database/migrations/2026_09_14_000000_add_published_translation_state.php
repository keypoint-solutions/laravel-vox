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
        $schema = Schema::connection($connection);
        $schema->table('vox_translation_values', function (Blueprint $table): void {
            $table->text('file_value')->nullable();
            $table->text('published_override')->nullable();
            $table->boolean('is_approved')->default(false);
        });
        DB::connection($connection)->table('vox_translation_values')
            ->whereIn('translation_id', DB::connection($connection)->table('vox_translations')->where('status', 'approved')->select('id'))
            ->update(['is_approved' => true]);
        $schema->table('vox_remote_translations', function (Blueprint $table): void {
            $table->unsignedBigInteger('environment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('vox.database.connection', 'vox'));
        DB::connection(config('vox.database.connection', 'vox'))->table('vox_remote_translations')->whereNull('environment_id')->delete();
        $schema->table('vox_remote_translations', function (Blueprint $table): void {
            $table->unsignedBigInteger('environment_id')->nullable(false)->change();
        });
        $schema->table('vox_translation_values', function (Blueprint $table): void {
            $table->dropColumn(['file_value', 'published_override', 'is_approved']);
        });
    }
};
