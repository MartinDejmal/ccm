<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuelings', fn (Blueprint $t) => $t->boolean('break_before')->default(false));
    }

    public function down(): void
    {
        Schema::table('fuelings', fn (Blueprint $t) => $t->dropColumn('break_before'));
    }
};
