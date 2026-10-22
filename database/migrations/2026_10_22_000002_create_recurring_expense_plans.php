<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expense_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained();
            $t->unsignedBigInteger('source_event_id')->unique();
            $t->foreign('source_event_id')->references('event_id')->on('expenses');
            $t->string('name', 160);
            $t->unsignedSmallInteger('interval_months');
            $t->foreign('interval_months')->references('id')->on('repetition_intervals');
            $t->date('anchor_date');
            $t->unsignedInteger('occurrence')->default(1);
            $t->date('next_due_on');
            $t->date('ended_on')->nullable();
            $t->string('end_reason', 200)->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->index(['vehicle_id', 'ended_on', 'next_due_on']);
        });
        Schema::create('recurring_expense_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained('recurring_expense_plans');
            $t->foreignId('event_id')->unique()->constrained('vehicle_events');
            $t->date('due_on');
            $t->timestamps();
            $t->unique(['plan_id', 'due_on']);
        });
        Schema::create('recurring_expense_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained('recurring_expense_plans');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action', 30);
            $t->longText('before_snapshot')->nullable();
            $t->longText('after_snapshot');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_expense_revisions');
        Schema::dropIfExists('recurring_expense_payments');
        Schema::dropIfExists('recurring_expense_plans');
    }
};
