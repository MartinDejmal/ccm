<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(int $fuel = 1): array
    {
        $v = $this->postJson('/api/v1/vehicles', ['name' => 'Analytika', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => $fuel])->assertCreated()->json();
        $v['stream'] = DB::table('vehicle_energy_streams')->where('vehicle_id', $v['id'])->value('id');

        return $v;
    }

    private function fuel(array $v, string $date, string $odo, string $quantity, int $sort = 1): array
    {
        return $this->postJson("/api/v1/vehicles/{$v['id']}/events", ['request_key' => (string) Str::uuid(), 'kind' => 'fueling', 'occurred_on' => $date, 'odometer' => $odo, 'quantity' => $quantity, 'amount' => '100.0000', 'currency_code' => 'CZK', 'energy_stream_id' => $v['stream'], 'fuel_sort_id' => $sort, 'fueling_type_id' => 1])->assertCreated()->json('event');
    }

    public function test_weighted_months_exact_half_liter_bins_filters_and_deleted_gaps(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        $this->fuel($v, '2025-12-31', '1000', '40');
        $jan = $this->fuel($v, '2026-01-31', '1250', '12.4999');
        $this->fuel($v, '2026-02-10', '1350', '10');
        $this->fuel($v, '2026-02-20', '1650', '15');
        $url = "/api/v1/vehicles/{$v['id']}/analytics";
        $r = $this->getJson($url)->assertOk()->json('streams.0');
        $this->assertSame('5.0000', $r['monthly'][0]['value']);
        $this->assertSame('6.2500', $r['monthly'][1]['value']);
        $this->assertSame('400.000', $r['monthly'][1]['distance']);
        $this->assertSame(2, $r['monthly'][1]['interval_count']);
        $this->assertSame([['lower' => '4.5', 'upper' => '5.0', 'count' => 1], ['lower' => '5.0', 'upper' => '5.5', 'count' => 1], ['lower' => '10.0', 'upper' => '10.5', 'count' => 1]], $r['histogram']);
        $this->getJson("$url?from=2026-02-01&to=2026-02-28")->assertJsonPath('streams.0.sample_count', 1)->assertJsonPath('streams.0.monthly.0.value', '5.0000');
        $this->deleteJson("/api/v1/vehicles/{$v['id']}/events/{$jan['id']}", ['revision' => 1])->assertNoContent();
        $this->getJson($url)->assertJsonPath('streams.0.sample_count', 1)->assertJsonPath('streams.0.monthly.0.month', '2026-02');
    }

    public function test_cost_categories_refunds_currencies_plans_and_permissions(): void
    {
        $this->getJson('/api/v1/vehicles/1/analytics')->assertUnauthorized();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $v = $this->vehicle();
        foreach ([['200.1234', 'CZK', 10], ['-50.0001', 'CZK', 16], ['999.9999', 'EUR', 1]] as [$amount, $currency, $type]) {
            $this->postJson("/api/v1/vehicles/{$v['id']}/events", ['request_key' => (string) Str::uuid(), 'kind' => 'expense', 'occurred_on' => '2026-01-01', 'amount' => $amount, 'currency_code' => $currency, 'expense_type_id' => $type, 'repetition_months' => 12, 'create_plan' => true])->assertCreated();
        }
        $url = "/api/v1/vehicles/{$v['id']}/analytics";
        $this->getJson($url)->assertOk()->assertJsonPath('costs.gross', '200.1234')->assertJsonPath('costs.refunds', '50.0001')->assertJsonPath('costs.net', '150.1233')->assertJsonPath('costs.categories.0.percent', '100.00');
        $this->getJson("$url?currency=EUR")->assertJsonPath('costs.net', '999.9999');
        $this->getJson("$url?currency=USD")->assertJsonPath('costs.categories', [])->assertJsonPath('costs.gross', '0.0000');
        $this->getJson("$url?from=2026-02-01")->assertJsonPath('costs.net', '0.0000');
        $this->getJson("$url?from=2026-02-01&to=2026-01-01")->assertUnprocessable();
        $other = $this->vehicle();
        $this->getJson("$url?energy_stream_id={$other['stream']}")->assertUnprocessable();
        $this->getJson("$url?currency=INVALID")->assertUnprocessable();
        DB::table('vehicles')->where('id', $v['id'])->update(['archived_at' => now()]);
        $this->getJson($url)->assertOk();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
    }

    public function test_analytics_uses_all_intervals_not_only_first_history_page(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle();
        for ($i = 0; $i < 31; $i++) {
            $this->fuel($v, (new \DateTimeImmutable('2026-01-01'))->modify("+$i days")->format('Y-m-d'), (string) (1000 + $i * 100), '5');
        }
        $this->getJson("/api/v1/vehicles/{$v['id']}/analytics")->assertOk()->assertJsonPath('streams.0.sample_count', 30)->assertJsonPath('streams.0.monthly.0.value', '5.0000')->assertJsonPath('streams.0.histogram.0.count', 30);
    }

    public function test_electricity_is_delivery_ratio_and_has_no_liter_histogram(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->vehicle(19);
        $this->fuel($v, '2026-01-01', '1000', '10', 19);
        $this->fuel($v, '2026-01-02', '1100', '30', 19);
        $this->getJson("/api/v1/vehicles/{$v['id']}/analytics")->assertOk()->assertJsonPath('streams.0.method', 'delivery_only')->assertJsonPath('streams.0.monthly.0.value', '30.0000')->assertJsonPath('streams.0.histogram', []);
    }
}
