<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RecurringExpenses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecurringExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_anchor_dates_keep_original_day_and_leap_year(): void
    {
        foreach ([1, 3, 6, 12, 24, 36] as $interval) {
            $first = RecurringExpenses::due('2024-01-31', $interval, 1);
            $this->assertSame('31', substr(RecurringExpenses::due('2024-01-31', $interval, 12), 8));
            $this->assertGreaterThan('2024-01-31', $first);
        }
        $this->assertSame('2024-02-29', RecurringExpenses::due('2024-01-31', 1, 1));
        $this->assertSame('2024-03-31', RecurringExpenses::due('2024-01-31', 1, 2));
        $this->assertSame('2025-02-28', RecurringExpenses::due('2024-02-29', 12, 1));
        $this->assertSame('2028-02-29', RecurringExpenses::due('2024-02-29', 12, 4));
    }

    private function setupVehicle(): string
    {
        $this->actingAs(User::factory()->create());

        return $this->postJson('/api/v1/vehicles', ['name' => 'Plány', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => 1])->assertCreated()->json('id');
    }

    private function expense(array $extra = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'kind' => 'expense', 'occurred_on' => '2024-01-31', 'amount' => '123.4567', 'currency_code' => 'CZK', 'expense_type_id' => 5, 'repetition_months' => 1], $extra);
    }

    public function test_explicit_plan_payment_retry_and_stale_payment_are_atomic(): void
    {
        $v = $this->setupVehicle();
        $body = $this->expense(['create_plan' => true]);
        $source = $this->postJson("/api/v1/vehicles/$v/events", $body)->assertCreated()->json();
        $this->postJson("/api/v1/vehicles/$v/events", $body)->assertOk();
        $p = $this->getJson("/api/v1/vehicles/$v/plans")->assertOk()->json('data.0');
        $this->assertSame('2024-02-29', $p['next_due_on']);
        $this->assertSame('123.4567', $p['estimated_amount']);
        $this->assertSame('123.4567', $this->getJson("/api/v1/vehicles/$v/summary")->json('0.total'));
        $payment = $this->expense(['occurred_on' => '2026-10-22', 'plan_id' => $p['id'], 'plan_revision' => $p['revision'], 'plan_due_on' => $p['next_due_on']]);
        $this->postJson("/api/v1/vehicles/$v/events", $payment)->assertCreated();
        $this->postJson("/api/v1/vehicles/$v/events", $payment)->assertOk();
        $payment['request_key'] = (string) Str::uuid();
        $this->postJson("/api/v1/vehicles/$v/events", $payment)->assertConflict();
        $this->assertDatabaseCount('vehicle_events', 2);
        $this->assertDatabaseCount('recurring_expense_payments', 1);
        $this->assertDatabaseHas('recurring_expense_plans', ['next_due_on' => '2024-03-31', 'revision' => 2]);
        $this->assertDatabaseCount('recurring_expense_revisions', 2);
        $this->postJson("/api/v1/vehicles/$v/plans", ['source_event_id' => $source['event']['id'], 'source_revision' => 1])->assertConflict();
    }

    public function test_manual_plan_edit_end_owner_and_archive_guards(): void
    {
        $v = $this->setupVehicle();
        $source = $this->postJson("/api/v1/vehicles/$v/events", $this->expense())->assertCreated()->json('event');
        $this->assertDatabaseCount('recurring_expense_plans', 0);
        $p = $this->postJson("/api/v1/vehicles/$v/plans", ['source_event_id' => $source['id'], 'source_revision' => 1, 'name' => 'Pojištění'])->assertCreated()->json();
        $edit = ['name' => 'Nový název', 'revision' => 1, 'interval_months' => 12];
        $this->putJson("/api/v1/vehicles/$v/plans/{$p['id']}", $edit)->assertOk()->assertJsonPath('next_due_on', '2025-01-31');
        $this->putJson("/api/v1/vehicles/$v/plans/{$p['id']}", $edit)->assertConflict();
        $this->putJson("/api/v1/vehicles/$v/plans/{$p['id']}", [...$edit, 'revision' => 2, 'end' => true])->assertOk();
        $this->getJson("/api/v1/vehicles/$v/plans")->assertJsonPath('total', 0);
        $this->getJson("/api/v1/vehicles/$v/plans?status=ended")->assertJsonPath('total', 1);
        $this->actingAs(User::factory()->create())->getJson("/api/v1/vehicles/$v/plans")->assertNotFound();
        $this->postJson("/api/v1/vehicles/$v/plans", ['source_event_id' => $source['id'], 'source_revision' => 1])->assertNotFound();
    }

    public function test_paid_expense_edits_do_not_advance_and_frequency_changes_keep_anchor(): void
    {
        $v = $this->setupVehicle();
        $this->postJson("/api/v1/vehicles/$v/events", $this->expense(['create_plan' => true]))->assertCreated();
        $p = $this->getJson("/api/v1/vehicles/$v/plans")->json('data.0');
        $payload = $this->expense(['plan_id' => $p['id'], 'plan_revision' => 1, 'plan_due_on' => $p['next_due_on']]);
        $paid = $this->postJson("/api/v1/vehicles/$v/events", $payload)->assertCreated()->json();
        $this->assertSame((string) $p['id'], $paid['detail']['payment_plan_id']);
        $this->putJson("/api/v1/vehicles/$v/events/{$paid['event']['id']}", [...$paid['detail'], ...$paid['event'], 'amount' => '200.0000'])->assertOk();
        $this->assertDatabaseHas('recurring_expense_plans', ['revision' => 2, 'next_due_on' => '2024-03-31']);
        $this->putJson("/api/v1/vehicles/$v/plans/{$p['id']}", ['revision' => 2, 'name' => 'Čtvrtletně', 'interval_months' => 3])->assertOk()->assertJsonPath('anchor_date', '2024-01-31')->assertJsonPath('next_due_on', '2024-04-30');
        DB::table('vehicles')->where('id', $v)->update(['archived_at' => now()]);
        $this->putJson("/api/v1/vehicles/$v/plans/{$p['id']}", ['revision' => 3, 'name' => 'Nelze', 'interval_months' => 1])->assertConflict();
        $this->postJson("/api/v1/vehicles/$v/events", $this->expense(['create_plan' => true]))->assertConflict();
        $this->getJson("/api/v1/vehicles/$v/plans")->assertOk();
    }

    public function test_invalid_plan_creation_rolls_back_financial_entry(): void
    {
        $v = $this->setupVehicle();
        $this->postJson("/api/v1/vehicles/$v/events", $this->expense(['create_plan' => true, 'repetition_months' => 0]))->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertDatabaseCount('event_revisions', 0);
    }

    public function test_source_removal_ends_plan_with_reason_and_audit(): void
    {
        $v = $this->setupVehicle();
        $e = $this->postJson("/api/v1/vehicles/$v/events", $this->expense(['create_plan' => true]))->assertCreated()->json('event');
        DB::transaction(function () use ($e) {
            RecurringExpenses::endForRemovedEvents(request()->setUserResolver(fn () => auth()->user()), [$e['id']]);
        });
        $this->assertDatabaseHas('recurring_expense_revisions', ['action' => 'import_revert']);
        $this->assertNotNull(DB::table('recurring_expense_plans')->first()->end_reason);
        $this->getJson("/api/v1/vehicles/$v/plans")->assertJsonPath('total', 0);
    }
}
