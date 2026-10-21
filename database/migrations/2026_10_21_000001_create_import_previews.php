<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('kind', 10);
            $t->string('profile', 30);
            $t->string('profile_version', 20)->default('1');
            $t->string('original_filename');
            $t->char('file_sha256', 64);
            $t->longText('raw_csv');
            $t->longText('parameters');
            $t->longText('summary');
            $t->string('status', 20);
            $t->timestamps();
            $t->index(['vehicle_id', 'created_at']);
            $t->index(['vehicle_id', 'file_sha256']);
        });
        Schema::create('import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('import_batches')->restrictOnDelete();
            $t->unsignedInteger('record_number');
            $t->unsignedInteger('physical_line_start');
            $t->longText('raw_record');
            $t->longText('source_values');
            $t->longText('normalized');
            $t->longText('issues');
            $t->char('fingerprint', 64);
            $t->string('status', 20);
            $t->unique(['batch_id', 'record_number']);
            $t->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
