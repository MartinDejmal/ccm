<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Services\Consumption\ConsumptionCalculator;
use Brick\Math\BigInteger;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConsumptionController extends Controller
{
    public function show(Request $r, string $vehicle, ConsumptionCalculator $calculator)
    {
        return DB::transaction(function () use ($r, $vehicle, $calculator) {
            $v = Vehicle::where('owner_id', $r->user()->id)->sharedLock()->findOrFail($vehicle);
            $d = $r->validate([
                'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($r->filled('from') ? '|after_or_equal:from' : ''),
                'energy_stream_id' => ['nullable', 'integer', Rule::exists('vehicle_energy_streams', 'id')->where('vehicle_id', $v->id)],
                'page' => 'nullable|integer|min:1|max:1000000',
            ]);
            $streams = DB::table('vehicle_energy_streams')->where('vehicle_id', $v->id)->when($r->filled('energy_stream_id'), fn ($q) => $q->where('id', $d['energy_stream_id']))->orderBy('id')->get();
            $rows = DB::table('vehicle_events as e')->join('fuelings as f', 'f.event_id', '=', 'e.id')->join('fuel_sorts as s', 's.id', '=', 'f.fuel_sort_id')
                ->where('e.vehicle_id', $v->id)->where('e.kind', 'fueling')->whereIn('f.energy_stream_id', $streams->pluck('id'))
                ->when($r->filled('to'), fn ($q) => $q->where('e.occurred_on', '<=', $d['to']))
                ->select(['e.id', 'e.occurred_on', 'e.day_sequence', 'e.odometer', 'e.deleted_at', 'f.energy_stream_id', 'f.quantity', 'f.quantity_unit', 'f.fueling_type_id', 'f.consumption_distance_unit', 'f.reported_trip', 'f.reported_consumption', 'f.bc_consumption', 'f.break_before', 's.medium_group as fuel_medium_group'])
                ->orderBy('e.occurred_on')->orderBy('e.id')->get()->map(fn ($row) => (array) $row)->groupBy('energy_stream_id');
            $summaries = [];
            $observations = [];
            foreach ($streams as $stream) {
                $result = $calculator->calculate((array) $stream, ($rows[$stream->id] ?? collect())->all(), $v->distance_unit, $d['from'] ?? null, $d['to'] ?? null);
                $summaries[] = $result['summary'];
                array_push($observations, ...$result['observations']);
            }
            usort($observations, fn ($a, $b) => strcmp($b['occurred_on'], $a['occurred_on']) ?: ($b['day_sequence'] <=> $a['day_sequence']) ?: BigInteger::of($b['event_id'])->compareTo($a['event_id']));
            $page = (int) ($d['page'] ?? 1);
            $paginator = new LengthAwarePaginator(array_slice($observations, ($page - 1) * 25, 25), count($observations), 25, $page, ['path' => $r->url(), 'query' => $r->query()]);

            return ['vehicle_id' => $v->id, 'distance_unit' => $v->distance_unit, 'from' => $d['from'] ?? null, 'to' => $d['to'] ?? null, 'streams' => $summaries, 'observations' => $paginator];
        });
    }
}
