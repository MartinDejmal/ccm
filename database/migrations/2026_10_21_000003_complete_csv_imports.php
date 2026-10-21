<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $t) {
            $t->unsignedInteger('revision')->default(1);
            $t->char('review_token', 64)->nullable();
            $t->uuid('confirm_key')->nullable()->unique();
            $t->char('confirm_hash', 64)->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users');
            $t->uuid('revert_key')->nullable()->unique();
            $t->char('revert_hash', 64)->nullable();
            $t->timestamp('reverted_at')->nullable();
            $t->foreignId('reverted_by')->nullable()->constrained('users');
            $t->longText('result')->nullable();
        });
        Schema::table('import_rows', function (Blueprint $t) {
            $t->longText('duplicates')->nullable();
            $t->char('approved_duplicate_token', 64)->nullable();
        });
        Schema::table('vehicle_events', function (Blueprint $t) {
            $t->foreignId('import_batch_id')->nullable()->constrained('import_batches')->restrictOnDelete();
            $t->foreignId('import_row_id')->nullable()->unique()->constrained('import_rows')->restrictOnDelete();
        });
        Schema::create('import_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('import_batches')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 24);
            $t->longText('context');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_actions');
        Schema::table('vehicle_events', function (Blueprint $t) {
            $t->dropConstrainedForeignId('import_row_id');
            $t->dropConstrainedForeignId('import_batch_id');
        });
        Schema::table('import_rows', fn (Blueprint $t) => $t->dropColumn(['duplicates', 'approved_duplicate_token']));
        Schema::table('import_batches', function (Blueprint $t) {
            $t->dropConstrainedForeignId('confirmed_by');
            $t->dropConstrainedForeignId('reverted_by');
            $t->dropColumn(['revision', 'review_token', 'confirm_key', 'confirm_hash', 'confirmed_at', 'revert_key', 'revert_hash', 'reverted_at', 'result']);
        });
    }
};
