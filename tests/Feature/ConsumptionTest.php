<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(int $fuel = 1): array
    {
        $v = $this->postJson('/api/v1/vehicles', ['name' => 'Spotřeba', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => $fuel])->assertCreated()->json();
        $v['stream'] = $this->getJson("/api/v1/vehicles/{$v['id']}/streams")->json('0.id');

        return $v;
    }

    private function fill(array $v, string $date, ?string $odometer, string $quantity = '10', int $type = 1, array $changes = []): array
    {
        return $this->postJson("/api/v1/vehicles/{$v['id']}/events", array_replace(['request_key' => (string) Str::uuid(), 'kind' => 'fueling', 'occurred_on' => $date, 'odometer' => $odometer, 'quantity' => $quantity, 'amount' => '100.0000', 'currency_code' => 'CZK', 'energy_stream_id' => $v['stream'], 'fuel_sort_id' => 1, 'fueling_type_id' => $type], $changes))->assertCreated()->json();
    }

    private function report(array $v, string $query = ''): array
    {
        return $this->getJson("/api/v1/vehicles/{$v['id']}/consumption?$query")->assertOk()->json();
    }

    public function test_ownership_filters_and_pagination_keep_full_history_context(): void
    {
        $this->getJson('/api/v1/vehicles/1/consumption')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        for ($i = 0; $i < 30; $i++) {
            $date = (new \DateTimeImmutable('2026-01-01'))->modify("+$i days")->format('Y-m-d');
            $this->fill($v, $date, (string) ($i * 100));
        }
        $r = $this->report($v, 'page=2');
        $this->assertSame(30, $r['observations']['total']);
        $this->assertCount(5, $r['observations']['data']);
        $this->assertSame(29, $r['streams'][0]['interval_count']);
        $this->assertSame('10.0000', $r['streams'][0]['average_consumption']);
        $r = $this->report($v, 'from=2026-01-15&to=2026-01-16');
        $this->assertSame(2, $r['observations']['total']);
        $this->assertSame(1, $r['streams'][0]['interval_count']);
        $this->assertSame('outside_period', $r['observations']['data'][1]['reason']);
        $this->getJson("/api/v1/vehicles/{$v['id']}/consumption?from=2026-02-01&to=2026-01-01")->assertUnprocessable();
        $other = $this->vehicle();
        $this->getJson("/api/v1/vehicles/{$v['id']}/consumption?energy_stream_id={$other['stream']}")->assertUnprocessable();
        $this->getJson("/api/v1/vehicles/{$v['id']}/consumption?page=999999999999999999")->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/vehicles/{$v['id']}/consumption")->assertNotFound();
    }

    public function test_revisions_order_gap_and_retroactive_changes_recompute_without_cache(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        $a = $this->fill($v, '2026-01-01', '1000');
        $b = $this->fill($v, '2026-01-01', '1100');
        $c = $this->fill($v, '2026-01-02', '1200');
        $d = $this->fill($v, '2026-01-03', '1300');
        $this->assertSame(1, $this->report($v)['streams'][0]['interval_count']);
        foreach ([[$a, 1], [$b, 2]] as [$snapshot, $sequence]) {
            $payload = [...$snapshot['detail'], ...$snapshot['event'], 'day_sequence' => $sequence];
            $this->putJson("/api/v1/vehicles/{$v['id']}/events/{$snapshot['event']['id']}", $payload)->assertOk();
        }
        $this->assertSame(3, $this->report($v)['streams'][0]['interval_count']);
        $payload = [...$c['detail'], ...$c['event'], 'break_before' => true];
        $url = "/api/v1/vehicles/{$v['id']}/events/{$c['event']['id']}";
        $updated = $this->putJson($url, $payload)->assertOk()->json();
        $this->putJson($url, $payload)->assertConflict();
        $this->assertSame(2, $this->report($v)['streams'][0]['interval_count']);
        $payload = [...$updated['detail'], ...$updated['event'], 'quantity' => '20.0000', 'break_before' => false];
        $this->putJson($url, $payload)->assertOk();
        $this->assertSame('13.3333', $this->report($v)['streams'][0]['average_consumption']);
        $payload = [...$b['detail'], ...$b['event'], 'revision' => 2, 'day_sequence' => 1];
        $this->putJson("/api/v1/vehicles/{$v['id']}/events/{$b['event']['id']}", $payload)->assertUnprocessable();
        $this->assertDatabaseHas('event_revisions', ['event_id' => $c['event']['id'], 'action' => 'update']);
    }

    public function test_date_odometer_type_and_stream_edits_change_neighboring_intervals(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        $this->fill($v, '2026-01-01', '1000');
        $b = $this->fill($v, '2026-01-02', '1100');
        $this->fill($v, '2026-01-03', '1300', '20');
        $url = "/api/v1/vehicles/{$v['id']}/events/{$b['event']['id']}";
        $payload = [...$b['detail'], ...$b['event'], 'fueling_type_id' => 2];
        $b = $this->putJson($url, $payload)->assertOk()->json();
        $this->assertSame(1, $this->report($v)['streams'][0]['interval_count']);
        $b = $this->putJson($url, [...$b['detail'], ...$b['event'], 'odometer' => '1400'])->assertOk()->json();
        $this->assertNull($this->report($v)['streams'][0]['average_consumption']);
        $b = $this->putJson($url, [...$b['detail'], ...$b['event'], 'odometer' => '1100', 'occurred_on' => '2026-01-01'])->assertOk()->json();
        $this->assertNull($this->report($v)['streams'][0]['average_consumption']);
        $b = $this->putJson($url, [...$b['detail'], ...$b['event'], 'occurred_on' => '2026-01-02'])->assertOk()->json();
        $this->assertSame('10.0000', $this->report($v)['streams'][0]['average_consumption']);
        $stream = DB::table('vehicle_energy_streams')->insertGetId(['vehicle_id' => $v['id'], 'name' => 'Druhá nádrž', 'medium_group' => 'diesel', 'quantity_unit' => 'l', 'consumption_method' => 'full_to_full', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson($url, [...$b['detail'], ...$b['event'], 'energy_stream_id' => (string) $stream])->assertOk();
        $r = $this->report($v);
        $this->assertSame('6.6667', $r['streams'][0]['average_consumption']);
        $this->assertNull($r['streams'][1]['average_consumption']);
    }

    public function test_import_reversal_and_restoration_change_all_affected_intervals(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        $this->fill($v, '2026-01-01', '1000');
        $this->fill($v, '2026-01-03', '1500', '20');
        $this->assertSame('4.0000', $this->report($v)['streams'][0]['average_consumption']);
        $b = $this->postJson("/api/v1/vehicles/{$v['id']}/imports", ['file' => UploadedFile::fake()->createWithContent('fuel.csv', "Date;Odometer;Quantity;Total price;Currency;Type;Fuel;Consumption;BC-Consumption\n02.01.2026;1100,000;10,0000;100,0000;CZK;2;1;6,9000;7,1000"), 'kind' => 'fueling', 'profile' => 'ccm', 'distance_unit' => 'km', 'quantity_unit' => 'l', 'energy_stream_id' => $v['stream'], 'decimal_separator' => ','])->assertCreated()->json();
        $base = "/api/v1/vehicles/{$v['id']}/imports/{$b['id']}";
        $b = $this->postJson("$base/review", ['revision' => $b['revision']])->assertOk()->json();
        $this->postJson("$base/confirm", ['revision' => $b['revision'], 'review_token' => $b['review_token'], 'request_key' => (string) Str::uuid(), 'acknowledged' => true])->assertOk();
        $this->assertSame('6.0000', $this->report($v)['streams'][0]['average_consumption']);
        $e = VehicleEvent::where('import_batch_id', $b['id'])->firstOrFail();
        $before = (array) DB::table('fuelings')->where('event_id', $e->id)->first();
        $this->report($v);
        $this->assertSame($before, (array) DB::table('fuelings')->where('event_id', $e->id)->first());
        $state = $this->getJson("$base/reversal")->json();
        $this->postJson("$base/revert", ['revision' => $state['revision'], 'review_token' => $state['review_token'], 'request_key' => (string) Str::uuid(), 'conflict_policy' => 'abort', 'acknowledged' => true])->assertOk();
        $this->assertNull($this->report($v)['streams'][0]['average_consumption']);
        // Simulate a future restoration operation; report reads current data, never a saved rate.
        $e = VehicleEvent::withTrashed()->findOrFail($e->id);
        $e->restore();
        $e->increment('revision');
        $this->assertSame('6.0000', $this->report($v)['streams'][0]['average_consumption']);
        $this->assertDatabaseHas('fuelings', ['event_id' => $e->id, 'reported_consumption' => '6.9000', 'bc_consumption' => '7.1000']);
    }

    public function test_multiple_media_are_separate_and_electricity_and_adblue_are_deliveries(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        foreach (['2026-01-01' => '1000', '2026-01-02' => '1100'] as $date => $odo) {
            $this->fill($v, $date, $odo);
        }
        foreach ([[19, 'electricity', 'kWh'], [21, 'additive', 'l']] as [$fuel, $group, $unit]) {
            $id = DB::table('vehicle_energy_streams')->insertGetId(['vehicle_id' => $v['id'], 'name' => $group, 'medium_group' => $group, 'quantity_unit' => $unit, 'consumption_method' => 'delivery_only', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
            // Seed historical rows for an inactive stream (normal writes correctly require active).
            foreach (['2026-01-01' => '1000', '2026-01-02' => '1100'] as $date => $odo) {
                $e = VehicleEvent::create(['vehicle_id' => $v['id'], 'created_by' => auth()->id(), 'kind' => 'fueling', 'occurred_on' => $date, 'odometer' => $odo, 'amount' => '100', 'currency_code' => 'CZK', 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)]);
                DB::table('fuelings')->insert(['event_id' => $e->id, 'energy_stream_id' => $id, 'fuel_sort_id' => $fuel, 'fueling_type_id' => 2, 'quantity' => '1.0000', 'quantity_unit' => $unit, 'consumption_distance_unit' => 'km', 'speed_unit' => 'km/h']);
            }
        }
        $r = $this->report($v);
        $this->assertCount(3, $r['streams']);
        $this->assertSame('10.0000', $r['streams'][0]['average_consumption']);
        $this->assertSame('1.0000', $r['streams'][1]['delivery_per_distance']);
        $this->assertNull($r['streams'][1]['average_consumption']);
        $this->assertSame('1.0000', $r['streams'][2]['delivery_per_distance']);
        $this->assertFalse($r['streams'][1]['is_active']);
        $this->assertCount(1, $this->report($v, 'energy_stream_id='.$v['stream'])['streams']);
    }
}
