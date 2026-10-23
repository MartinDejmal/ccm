<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(): string
    {
        return $this->postJson('/api/v1/vehicles', ['name' => 'Mazání', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => 1])->assertCreated()->json('id');
    }

    private function expense(string $v, array $extra = []): array
    {
        return $this->postJson("/api/v1/vehicles/$v/events", array_replace(['request_key' => (string) Str::uuid(), 'kind' => 'expense', 'occurred_on' => '2026-01-31', 'amount' => '123.4567', 'currency_code' => 'CZK', 'expense_type_id' => 5, 'repetition_months' => 1], $extra))->assertCreated()->json();
    }

    public function test_deletion_is_owned_revision_checked_idempotent_and_audited(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $v = $this->vehicle();
        $e = $this->expense($v)['event'];
        $otherVehicle = $this->vehicle();
        $url = "/api/v1/vehicles/$v/events/{$e['id']}";
        $this->deleteJson("/api/v1/vehicles/$otherVehicle/events/{$e['id']}", ['revision' => 1])->assertNotFound();
        $this->actingAs(User::factory()->create())->deleteJson($url, ['revision' => 1])->assertNotFound();
        $this->actingAs($owner)->deleteJson($url, [])->assertUnprocessable();
        $this->deleteJson($url, ['revision' => 2])->assertConflict();
        DB::table('vehicles')->where('id', $v)->update(['archived_at' => now()]);
        $this->deleteJson($url, ['revision' => 1])->assertConflict();
        DB::table('vehicles')->where('id', $v)->update(['archived_at' => null]);
        $this->deleteJson($url, ['revision' => 1])->assertNoContent();
        $this->deleteJson($url, ['revision' => 1])->assertNoContent();
        $this->assertSoftDeleted('vehicle_events', ['id' => $e['id'], 'revision' => 2]);
        $this->assertDatabaseCount('event_revisions', 2);
        $audit = DB::table('event_revisions')->where('action', 'delete')->first();
        $this->assertSame(1, json_decode($audit->before_snapshot, true)['event']['revision']);
        $this->assertNotNull(json_decode($audit->after_snapshot, true)['event']['deleted_at']);
        $this->getJson($url)->assertNotFound();
        $this->getJson("/api/v1/vehicles/$v/events")->assertJsonPath('total', 0);
        $this->getJson("/api/v1/vehicles/$v/summary")->assertExactJson([]);
    }

    public function test_removing_payment_or_source_ends_plan_without_deleting_other_payments(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['source', 'payment'] as $target) {
            $v = $this->vehicle();
            $source = $this->expense($v, ['create_plan' => true])['event'];
            $p = $this->getJson("/api/v1/vehicles/$v/plans")->json('data.0');
            $payment = $this->expense($v, ['plan_id' => $p['id'], 'plan_revision' => 1, 'plan_due_on' => $p['next_due_on']])['event'];
            $e = $target === 'source' ? $source : $payment;
            $this->deleteJson("/api/v1/vehicles/$v/events/{$e['id']}", ['revision' => 1])->assertNoContent();
            $this->getJson("/api/v1/vehicles/$v/plans")->assertJsonPath('total', 0);
            $this->assertDatabaseHas('recurring_expense_revisions', ['plan_id' => $p['id'], 'action' => 'event_delete']);
            $this->assertSame('Smazání záznamu vyřadilo zdroj nebo propojenou platbu.', DB::table('recurring_expense_plans')->find($p['id'])->end_reason);
            $this->getJson("/api/v1/vehicles/$v/summary")->assertJsonPath('0.total', '123.4567');
        }
    }

    public function test_deleted_fueling_is_a_gap_not_a_new_consumption_interval(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        $stream = DB::table('vehicle_energy_streams')->where('vehicle_id', $v)->value('id');
        $events = [];
        foreach ([1, 2, 3] as $day) {
            $events[] = $this->postJson("/api/v1/vehicles/$v/events", ['request_key' => (string) Str::uuid(), 'kind' => 'fueling', 'occurred_on' => "2026-01-0$day", 'odometer' => (string) (1000 + 100 * $day), 'amount' => '100.0000', 'currency_code' => 'CZK', 'quantity' => '10', 'fuel_sort_id' => 1, 'fueling_type_id' => 1, 'energy_stream_id' => $stream])->assertCreated()->json('event');
        }
        $this->getJson("/api/v1/vehicles/$v/consumption")->assertJsonPath('streams.0.interval_count', 2);
        $this->deleteJson("/api/v1/vehicles/$v/events/{$events[1]['id']}", ['revision' => 1])->assertNoContent();
        $this->getJson("/api/v1/vehicles/$v/consumption")->assertJsonPath('streams.0.interval_count', 0);
        $this->getJson("/api/v1/vehicles/$v/summary")->assertJsonPath('0.total', '200.0000');
    }
}
