<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CcmTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(): string
    {
        return $this->postJson('/api/v1/vehicles', ['name' => 'Test auto', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => 1])->assertCreated()->json('id');
    }

    private function expense(): array
    {
        return ['request_key' => (string) Str::uuid(), 'kind' => 'expense', 'occurred_on' => '2026-10-20', 'amount' => '-5065.0000', 'currency_code' => 'CZK', 'expense_type_id' => 5, 'repetition_months' => 12, 'note' => "Více řádků\n\"poznámka\""];
    }

    public function test_guest_cannot_access_private_data(): void
    {
        $this->getJson('/api/v1/vehicles')->assertUnauthorized();
    }

    public function test_catalogs_and_ownership(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a);
        $id = $this->vehicle();
        $this->assertSame(22, DB::table('fuel_sorts')->count());
        $this->assertSame(23, DB::table('expense_types')->count());
        $this->actingAs($b)->getJson("/api/v1/vehicles/$id/events")->assertNotFound();
        $this->postJson("/api/v1/vehicles/$id/events", $this->expense())->assertNotFound();
    }

    public function test_retry_revision_and_signed_totals(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        $d = $this->expense();
        $e = $this->postJson("/api/v1/vehicles/$v/events", $d)->assertCreated()->json('event');
        $this->postJson("/api/v1/vehicles/$v/events", $d)->assertOk();
        $this->assertDatabaseCount('vehicle_events', 1);
        $changed = $d;
        $changed['amount'] = '1.00';
        $this->postJson("/api/v1/vehicles/$v/events", $changed)->assertConflict();
        $s = $this->getJson("/api/v1/vehicles/$v/summary")->assertOk()->json('0.total');
        $this->assertSame('-5065.0000', (string) $s);
        $d['revision'] = 1;
        $d['amount'] = '-5000.0000';
        $this->putJson("/api/v1/vehicles/$v/events/{$e['id']}", $d)->assertOk()->assertJsonPath('event.revision', 2);
        $this->putJson("/api/v1/vehicles/$v/events/{$e['id']}", $d)->assertConflict();
        $this->assertDatabaseCount('event_revisions', 2);
    }

    public function test_fuel_stream_validation_is_atomic(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        $stream = $this->getJson("/api/v1/vehicles/$v/streams")->json('0.id');
        $d = ['request_key' => (string) Str::uuid(), 'kind' => 'fueling', 'occurred_on' => '2026-10-20', 'amount' => '1600.12', 'currency_code' => 'CZK', 'energy_stream_id' => $stream, 'fuel_sort_id' => 19, 'fueling_type_id' => 0, 'quantity' => '40.1234'];
        $this->postJson("/api/v1/vehicles/$v/events", $d)->assertUnprocessable();
        $this->assertDatabaseCount('vehicle_events', 0);
        $d['fuel_sort_id'] = 1;
        $this->postJson("/api/v1/vehicles/$v/events", $d)->assertCreated()->assertJsonPath('detail.fueling_type_id', 0);
        $this->assertDatabaseCount('fuelings', 1);
        $this->assertDatabaseCount('vehicle_events', 1);
    }

    public function test_invalid_precision_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        $d = $this->expense();
        $d['amount'] = '1.12345';
        $this->postJson("/api/v1/vehicles/$v/events", $d)->assertUnprocessable();
    }
}
