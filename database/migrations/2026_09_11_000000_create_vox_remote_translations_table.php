<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('vox.database.connection', 'vox'));
        $schema->create('vox_remote_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained('vox_environments')->cascadeOnDelete();
            $table->string('identity', 64);
            $table->string('group');
            $table->string('key');
            $table->string('locale');
            $table->text('remote_value');
            $table->text('last_seen_value')->nullable();
            $table->text('base_value')->nullable();
            $table->text('base_local_value')->nullable();
            $table->boolean('has_baseline')->default(false);
            $table->boolean('remote_present')->default(true);
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['environment_id', 'identity']);
        });
        $schema->table('vox_environments', function (Blueprint $table): void {
            $table->unsignedBigInteger('sync_revision')->default(0);
            $table->timestamp('last_pulled_at')->nullable();
        });
        $schema->table('vox_translation_values', function (Blueprint $table): void {
            $table->boolean('is_pending_publish')->default(false);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('vox.database.connection', 'vox'));
        $schema->dropIfExists('vox_remote_translations');
        $schema->table('vox_environments', function (Blueprint $table): void {
            $table->dropColumn(['sync_revision', 'last_pulled_at']);
        });
        $schema->table('vox_translation_values', function (Blueprint $table): void {
            $table->dropColumn('is_pending_publish');
        });
    }
};
