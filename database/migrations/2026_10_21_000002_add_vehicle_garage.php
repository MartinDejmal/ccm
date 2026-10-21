<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            $t->unsignedInteger('revision')->default(1);
            $t->string('avatar_path', 160)->nullable();
        });
        Schema::create('vehicle_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 24);
            $t->longText('before_snapshot')->nullable();
            $t->longText('after_snapshot');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_revisions');
        Schema::table('vehicles', fn (Blueprint $t) => $t->dropColumn(['revision', 'avatar_path']));
    }
};
