<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Services\Consumption\ConsumptionCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function show(Request $r, string $vehicle, ConsumptionCalculator $calculator, ConsumptionController $consumption)
    {
        return DB::transaction(function () use ($r, $vehicle, $calculator, $consumption) {
            $v = Vehicle::where('owner_id', $r->user()->id)->sharedLock()->findOrFail($vehicle);
            $d = $r->validate(['currency' => 'nullable|exists:currencies,code']);
            $currency = $d['currency'] ?? $v->currency_code;
            $report = $consumption->report($r, $vehicle, $calculator, false);
            $rows = DB::table('vehicle_events as e')->leftJoin('expenses as x', 'x.event_id', '=', 'e.id')->leftJoin('expense_types as t', 't.id', '=', 'x.expense_type_id')
                ->where('e.vehicle_id', $v->id)->whereNull('e.deleted_at')->where('e.currency_code', $currency)
                ->when($r->filled('from'), fn ($q) => $q->where('e.occurred_on', '>=', $r->input('from')))
                ->when($r->filled('to'), fn ($q) => $q->where('e.occurred_on', '<=', $r->input('to')))
                ->selectRaw("CASE WHEN e.kind = 'fueling' THEN 'fuel' ELSE CONCAT('expense-', x.expense_type_id) END AS category, CASE WHEN e.kind = 'fueling' THEN 'Palivo a energie' ELSE t.label END AS label, SUM(CASE WHEN e.amount > 0 THEN e.amount ELSE 0 END) AS positive, SUM(CASE WHEN e.amount < 0 THEN -e.amount ELSE 0 END) AS refunds")
                ->groupBy('e.kind', 'x.expense_type_id', 't.label')->orderByDesc('positive')->orderBy('category')->get();
            $gross = BigDecimal::zero();
            $refunds = BigDecimal::zero();
            foreach ($rows as $row) {
                $gross = $gross->plus($row->positive);
                $refunds = $refunds->plus($row->refunds);
                $row->positive = (string) BigDecimal::of($row->positive)->toScale(4);
                $row->refunds = (string) BigDecimal::of($row->refunds)->toScale(4);
                $row->net = (string) BigDecimal::of($row->positive)->minus($row->refunds)->toScale(4);
            }
            foreach ($rows as $row) {
                $row->percent = $gross->isZero() ? '0.00' : (string) BigDecimal::of($row->positive)->multipliedBy(100)->dividedBy($gross, 2, RoundingMode::HalfUp);
            }
            $series = [];
            foreach ($report['streams'] as $stream) {
                $months = [];
                $bins = [];
                $count = 0;
                foreach ($report['observations'] as $o) {
                    if ($o['energy_stream_id'] !== $stream['id'] || ! ($o['interval']['included_in_average'] ?? false)) {
                        continue;
                    }
                    $i = $o['interval'];
                    $month = substr($o['occurred_on'], 0, 7);
                    $months[$month] ??= ['quantity' => BigDecimal::zero(), 'distance' => BigDecimal::zero(), 'count' => 0];
                    $months[$month]['quantity'] = $months[$month]['quantity']->plus($i['quantity']);
                    $months[$month]['distance'] = $months[$month]['distance']->plus($i['distance']);
                    $months[$month]['count']++;
                    $count++;
                    if ($stream['method'] === 'full_to_full' && $stream['quantity_unit'] === 'l') {
                        // Bin the exact ratio, not its rounded display value. Bins are [lower, upper).
                        $bin = BigDecimal::of($i['quantity'])->multipliedBy(200)->dividedBy($i['distance'], 0, RoundingMode::Floor)->toInt();
                        $bins[$bin] = ($bins[$bin] ?? 0) + 1;
                    }
                }
                ksort($months);
                ksort($bins);
                $monthly = [];
                foreach ($months as $month => $m) {
                    $monthly[] = ['month' => $month, 'value' => (string) $m['quantity']->multipliedBy(100)->dividedBy($m['distance'], 4, RoundingMode::HalfUp), 'quantity' => (string) $m['quantity']->toScale(4), 'distance' => (string) $m['distance']->toScale(3), 'interval_count' => $m['count']];
                }
                $histogram = [];
                foreach ($bins as $bin => $n) {
                    $histogram[] = ['lower' => (string) BigDecimal::of($bin)->dividedBy(2, 1), 'upper' => (string) BigDecimal::of($bin + 1)->dividedBy(2, 1), 'count' => $n];
                }
                $series[] = [...$stream, 'monthly' => $monthly, 'histogram' => $histogram, 'sample_count' => $count];
            }

            return ['vehicle_id' => $v->id, 'currency_code' => $currency, 'from' => $report['from'], 'to' => $report['to'], 'costs' => ['categories' => $rows, 'gross' => (string) $gross->toScale(4), 'refunds' => (string) $refunds->toScale(4), 'net' => (string) $gross->minus($refunds)->toScale(4)], 'streams' => $series];
        });
    }
}
