<?php

namespace Tests\Unit;

use App\Services\Consumption\ConsumptionCalculator;
use PHPUnit\Framework\TestCase;

class ConsumptionCalculatorTest extends TestCase
{
    private function stream(array $changes = []): array
    {
        return array_replace(['id' => '11', 'name' => 'Nafta', 'medium_group' => 'diesel', 'quantity_unit' => 'l', 'consumption_method' => 'full_to_full', 'is_active' => true], $changes);
    }

    private function row(int $id, ?string $odometer, string $quantity = '10.0000', int $type = 1, array $changes = []): array
    {
        return array_replace(['id' => (string) $id, 'occurred_on' => '2026-01-'.str_pad((string) $id, 2, '0', STR_PAD_LEFT), 'day_sequence' => 0, 'odometer' => $odometer,
            'quantity' => $quantity, 'quantity_unit' => 'l', 'consumption_distance_unit' => 'km', 'fuel_medium_group' => 'diesel', 'fueling_type_id' => $type,
            'reported_trip' => null, 'reported_consumption' => null, 'bc_consumption' => null, 'break_before' => false, 'deleted_at' => null], $changes);
    }

    public function test_full_partial_intervals_and_weighted_average_exclude_initial_fill(): void
    {
        $r = (new ConsumptionCalculator)->calculate($this->stream(), [$this->row(1, '1000', '50'), $this->row(2, '1100', '10', 2), $this->row(3, '1500', '20', 1, ['reported_consumption' => '8.0000', 'bc_consumption' => '9.0000']), $this->row(4, '1600', '10')], 'km');
        $this->assertSame('6.6667', $r['summary']['average_consumption']);
        $this->assertSame('40.0000', $r['summary']['accounted_quantity']);
        $this->assertSame('600.000', $r['summary']['accounted_distance']);
        $this->assertSame('90.0000', $r['summary']['delivered_quantity']);
        $this->assertSame(2, $r['summary']['interval_count']);
        $this->assertSame('partial_in_complete_interval', $r['observations'][1]['reason']);
        $this->assertSame(['2', '3'], $r['observations'][2]['interval']['event_ids']);
        $this->assertSame('1', $r['observations'][2]['interval']['start']['event_id']);
        $this->assertSame('6.0000', $r['observations'][2]['computed_consumption']);
        $this->assertSame('8.0000', $r['observations'][2]['reported_consumption']);
        $this->assertSame('9.0000', $r['observations'][2]['bc_consumption']);
    }

    public function test_first_and_partial_fills_do_not_create_a_full_anchor(): void
    {
        $r = (new ConsumptionCalculator)->calculate($this->stream(), [$this->row(1, '1000', '50', 3), $this->row(2, '1100', '10', 2), $this->row(3, '1500', '20'), $this->row(4, '1600', '5')], 'km');
        $this->assertSame('first_fueling', $r['observations'][0]['reason']);
        $this->assertSame('missing_anchor', $r['observations'][1]['reason']);
        $this->assertSame('full_anchor', $r['observations'][2]['reason']);
        $this->assertSame('5.0000', $r['summary']['average_consumption']);
        $this->assertSame(1, $r['summary']['interval_count']);
    }

    public function test_breaks_interrupt_pending_quantity_and_next_full_restarts_chain(): void
    {
        foreach ([
            ['fueling_type_id' => 0], ['fueling_type_id' => 3], ['odometer' => null], ['deleted_at' => '2026-01-03 00:00:00'],
            ['quantity_unit' => 'kg'], ['consumption_distance_unit' => 'mi'], ['fuel_medium_group' => 'gasoline'],
        ] as $changes) {
            $r = (new ConsumptionCalculator)->calculate($this->stream(), [$this->row(1, '1000'), $this->row(2, '1100', '10', 2), $this->row(3, '1200', '3', 2, $changes), $this->row(4, '1500', '20'), $this->row(5, '1600', '5')], 'km');
            $this->assertSame('5.0000', $r['summary']['average_consumption'], json_encode($changes));
            $this->assertSame('interrupted_interval', $r['observations'][1]['reason']);
            $this->assertSame('4', $r['observations'][4]['interval']['start']['event_id']);
        }
        $r = (new ConsumptionCalculator)->calculate($this->stream(), [$this->row(1, '1000'), $this->row(2, '1100', '10', 2), $this->row(3, '1500', '20', 1, ['break_before' => true]), $this->row(4, '1600', '5')], 'km');
        $this->assertSame('history_gap', $r['observations'][2]['reason']);
        $this->assertTrue($r['observations'][2]['starts_new_chain']);
        $this->assertSame('5.0000', $r['summary']['average_consumption']);
    }

    public function test_zero_distance_and_odometer_reset_never_produce_a_rate(): void
    {
        foreach ([['1000', 'zero_distance'], ['50', 'odometer_decrease']] as [$odo, $reason]) {
            $next = (string) ((int) $odo + 100);
            $r = (new ConsumptionCalculator)->calculate($this->stream(), [$this->row(1, '1000'), $this->row(2, $odo), $this->row(3, $next)], 'km');
            $this->assertNull($r['observations'][1]['computed_consumption']);
            $this->assertSame($reason, $r['observations'][1]['reason']);
            $this->assertSame('10.0000', $r['summary']['average_consumption']);
            $this->assertSame('2', $r['observations'][2]['interval']['start']['event_id']);
        }
    }

    public function test_same_day_requires_explicit_unique_positive_sequences(): void
    {
        $a = $this->row(1, '1000');
        $b = $this->row(2, '1100', '10', 1, ['occurred_on' => $a['occurred_on']]);
        $tail = [$this->row(3, '1200'), $this->row(4, '1300')];
        $r = (new ConsumptionCalculator)->calculate($this->stream(), [$a, $b, ...$tail], 'km');
        $this->assertSame('ambiguous_order', $r['observations'][0]['reason']);
        $this->assertSame('ambiguous_order', $r['observations'][1]['reason']);
        $this->assertSame(1, $r['summary']['interval_count']);
        $a['day_sequence'] = 1;
        $b['day_sequence'] = 2;
        $r = (new ConsumptionCalculator)->calculate($this->stream(), [$b, $a, ...$tail], 'km');
        $this->assertSame(3, $r['summary']['interval_count']);
        $this->assertSame('10.0000', $r['summary']['average_consumption']);
        $b['day_sequence'] = 1;
        $r = (new ConsumptionCalculator)->calculate($this->stream(), [$a, $b], 'km');
        $this->assertNull($r['summary']['average_consumption']);
    }

    public function test_date_range_uses_context_but_only_counts_whole_intervals(): void
    {
        $r = (new ConsumptionCalculator)->calculate($this->stream(), [$this->row(1, '1000'), $this->row(2, '1100', '10', 2), $this->row(3, '1500', '20'), $this->row(4, '1600', '10')], 'km', '2026-01-03', '2026-01-04');
        $this->assertCount(2, $r['observations']);
        $this->assertSame('outside_period', $r['observations'][0]['reason']);
        $this->assertFalse($r['observations'][0]['interval']['included_in_average']);
        $this->assertSame('6.0000', $r['observations'][0]['computed_consumption']);
        $this->assertSame('10.0000', $r['summary']['average_consumption']);
        $this->assertSame('2026-01-03', $r['summary']['coverage_from']);
        $this->assertSame('30.0000', $r['summary']['delivered_quantity']);
    }

    public function test_open_partial_and_trip_warning_preserve_source_values_and_precision(): void
    {
        $r = (new ConsumptionCalculator)->calculate($this->stream(), [$this->row(1, '1000'), $this->row(2, '1000.003', '0.0001', 1, ['reported_trip' => '100.000']), $this->row(3, '1000.004', '0.0001', 2)], 'km');
        $this->assertSame('3.3333', $r['summary']['average_consumption']);
        $this->assertSame(['trip_difference'], $r['observations'][1]['warnings']);
        $this->assertSame('0.0001', $r['summary']['open_interval']['quantity']);
        $this->assertSame(['3'], $r['summary']['open_interval']['event_ids']);
        $this->assertNull($r['observations'][2]['computed_consumption']);
    }

    public function test_electricity_and_adblue_only_report_deliveries_even_with_full_method(): void
    {
        foreach ([['electricity', 'kWh'], ['additive', 'l']] as [$medium, $unit]) {
            $changes = ['fuel_medium_group' => $medium, 'quantity_unit' => $unit];
            $r = (new ConsumptionCalculator)->calculate($this->stream(['medium_group' => $medium, 'quantity_unit' => $unit]), [$this->row(1, '1000', '10', 3, $changes), $this->row(2, '1100', '20', 2, $changes), $this->row(3, '1300', '60', 1, $changes)], 'km');
            $this->assertNull($r['summary']['average_consumption']);
            $this->assertSame('26.6667', $r['summary']['delivery_per_distance']);
            $this->assertSame('delivery_only', $r['summary']['method']);
            $this->assertNull($r['observations'][1]['computed_consumption']);
        }
    }

    public function test_kg_and_miles_are_preserved_and_empty_history_has_no_fake_zero(): void
    {
        $changes = ['quantity_unit' => 'kg', 'fuel_medium_group' => 'hydrogen', 'consumption_distance_unit' => 'mi'];
        $r = (new ConsumptionCalculator)->calculate($this->stream(['quantity_unit' => 'kg', 'medium_group' => 'hydrogen']), [$this->row(1, '1000', '1', 1, $changes), $this->row(2, '1100', '1', 1, $changes)], 'mi');
        $this->assertSame('1.0000', $r['summary']['average_consumption']);
        $this->assertSame('mi', $r['summary']['distance_unit']);
        $empty = (new ConsumptionCalculator)->calculate($this->stream(), [], 'km');
        $this->assertNull($empty['summary']['average_consumption']);
        $this->assertSame('0.0000', $empty['summary']['delivered_quantity']);
        $this->assertSame([], $empty['observations']);
    }
}
