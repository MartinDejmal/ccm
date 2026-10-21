<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HistoryFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_and_totals_share_inclusive_date_currency_and_category_filters(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->postJson('/api/v1/vehicles', ['name' => 'Filtry', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => 1])->json('id');
        foreach ([['2026-10-01', '100.00', 'CZK', 2], ['2026-10-21', '-20.50', 'CZK', 2], ['2026-10-21', '30.00', 'EUR', 2], ['2026-10-21', '50.00', 'CZK', 5]] as [$date,$amount,$currency,$type]) {
            $this->postJson("/api/v1/vehicles/$v/events", ['request_key' => (string) Str::uuid(), 'kind' => 'expense', 'occurred_on' => $date, 'amount' => $amount, 'currency_code' => $currency, 'expense_type_id' => $type, 'repetition_months' => 0])->assertCreated();
        }
        $query = 'kind=expense&from=2026-10-21&to=2026-10-21&currency=CZK&expense_type_id=2';
        $this->getJson("/api/v1/vehicles/$v/events?$query")->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.amount', '-20.5000');
        $this->getJson("/api/v1/vehicles/$v/summary?$query")->assertOk()->assertJsonCount(1)->assertJsonPath('0.total', '-20.5000');
        foreach (['events', 'summary'] as $endpoint) {
            $this->getJson("/api/v1/vehicles/$v/$endpoint?from=2026-10-22&to=2026-10-01")->assertUnprocessable();
        }
    }
}
