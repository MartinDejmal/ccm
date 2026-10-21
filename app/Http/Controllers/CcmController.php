<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleEvent;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CcmController extends Controller
{
    private function vehicle(Request $r, string $id): Vehicle
    {
        return Vehicle::where('owner_id', $r->user()->id)->findOrFail($id);
    }

    public function catalogs()
    {
        return response()->json(collect(['currencies', 'fuel_sorts', 'fueling_types', 'expense_types', 'repetition_intervals'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()]));
    }

    public function vehicles(Request $r)
    {
        return Vehicle::where('owner_id', $r->user()->id)->orderByRaw('archived_at IS NOT NULL')->orderBy('name')->get();
    }

    public function createVehicle(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:160', 'make' => 'nullable|string|max:100', 'model' => 'nullable|string|max:100', 'registration_plate' => 'nullable|string|max:32', 'vin' => 'nullable|string|max:32', 'acquired_on' => 'nullable|date_format:Y-m-d', 'distance_unit' => ['required', Rule::in(['km', 'mi'])], 'currency_code' => 'required|exists:currencies,code', 'initial_odometer' => 'nullable|decimal:0,3|min:0|max:99999999999.999', 'fuel_sort_id' => 'required|exists:fuel_sorts,id']);

        return DB::transaction(function () use ($r, $data) {
            $fuel = DB::table('fuel_sorts')->find($data['fuel_sort_id']);
            unset($data['fuel_sort_id']);
            $v = new Vehicle($data);
            $v->owner_id = $r->user()->id;
            $v->save();
            $v->refresh();
            DB::table('vehicle_energy_streams')->insert(['vehicle_id' => $v->id, 'name' => $fuel->name, 'medium_group' => $fuel->medium_group, 'quantity_unit' => $fuel->quantity_unit, 'consumption_method' => in_array($fuel->medium_group, ['electricity', 'additive']) ? 'delivery_only' : 'full_to_full', 'created_at' => now(), 'updated_at' => now()]);
            GarageController::audit($v, $r, 'create', null);

            return response()->json($v, 201);
        });
    }

    public function streams(Request $r, string $vehicle)
    {
        $v = $this->vehicle($r, $vehicle);

        return DB::table('vehicle_energy_streams')->where('vehicle_id', $v->id)->get()->map(fn ($x) => array_merge((array) $x, ['id' => (string) $x->id, 'vehicle_id' => (string) $x->vehicle_id]));
    }

    private function filteredEvents(Request $r, Vehicle $v): Builder
    {
        $filters = $r->validate([
            'kind' => ['nullable', Rule::in(['fueling', 'expense'])],
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d'.($r->filled('from') ? '|after_or_equal:from' : ''),
            'currency' => 'nullable|exists:currencies,code',
            'expense_type_id' => 'nullable|exists:expense_types,id',
            'fuel_sort_id' => 'nullable|exists:fuel_sorts,id',
        ]);
        $q = VehicleEvent::where('vehicle_id', $v->id);
        foreach (['kind' => 'kind', 'currency' => 'currency_code'] as $key => $column) {
            if (! empty($filters[$key])) {
                $q->where($column, $filters[$key]);
            }
        }
        if (! empty($filters['from'])) {
            $q->where('occurred_on', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('occurred_on', '<=', $filters['to']);
        }
        foreach (['expense_type_id' => 'expenses', 'fuel_sort_id' => 'fuelings'] as $key => $table) {
            if (! empty($filters[$key])) {
                $q->whereExists(function ($sub) use ($key, $table, $filters) {
                    $sub->selectRaw('1')->from($table)->whereColumn($table.'.event_id', 'vehicle_events.id')->where($table.'.'.$key, $filters[$key]);
                });
            }
        }

        return $q;
    }

    public function events(Request $r, string $vehicle)
    {
        $q = $this->filteredEvents($r, $this->vehicle($r, $vehicle));

        return $q->orderByDesc('occurred_on')->orderByDesc('id')->paginate(25)->withQueryString();
    }

    public function summary(Request $r, string $vehicle)
    {
        return $this->filteredEvents($r, $this->vehicle($r, $vehicle))->toBase()
            ->select('currency_code', 'kind')->selectRaw('SUM(amount) AS total')
            ->groupBy('currency_code', 'kind')->get()->map(function ($row) {
                $row->total = (string) BigDecimal::of((string) $row->total)->toScale(4);

                return $row;
            });
    }

    public function createEvent(Request $r, string $vehicle)
    {
        return $this->saveEvent($r, $vehicle);
    }

    public function updateEvent(Request $r, string $vehicle, string $event)
    {
        return $this->saveEvent($r, $vehicle, $event);
    }

    public function detail(Request $r, string $vehicle, string $event)
    {
        $v = $this->vehicle($r, $vehicle);
        $e = VehicleEvent::where('vehicle_id', $v->id)->findOrFail($event);

        return $this->snapshot($e);
    }

    private function snapshot(VehicleEvent $e): array
    {
        $detail = (array) DB::table($e->kind === 'fueling' ? 'fuelings' : 'expenses')->where('event_id', $e->id)->first();
        foreach (['event_id', 'energy_stream_id'] as $key) {
            if (isset($detail[$key])) {
                $detail[$key] = (string) $detail[$key];
            }
        }
        foreach (['quantity', 'reported_trip', 'reported_consumption', 'bc_consumption', 'bc_quantity', 'bc_speed', 'latitude', 'longitude'] as $key) {
            if (isset($detail[$key])) {
                $detail[$key] = (string) $detail[$key];
            }
        }

        return ['event' => $e->toArray(), 'detail' => $detail];
    }

    private function saveEvent(Request $r, string $vehicle, ?string $id = null)
    {
        $v = $this->vehicle($r, $vehicle);
        abort_if($v->archived_at, 409, 'Vozidlo je archivované.');
        $d = $r->validate(['request_key' => ($id ? 'nullable' : 'required').'|uuid', 'kind' => ['required', Rule::in(['fueling', 'expense'])], 'occurred_on' => 'required|date_format:Y-m-d', 'odometer' => 'nullable|decimal:0,3|min:0|max:99999999999.999', 'amount' => 'required|decimal:0,4|between:-99999999999999.9999,99999999999999.9999', 'currency_code' => 'required|exists:currencies,code', 'note' => 'nullable|string|max:20000', 'revision' => ($id ? 'required' : 'nullable').'|integer|min:1', 'quantity' => 'required_if:kind,fueling|nullable|decimal:0,4|gt:0|max:9999999999.9999', 'energy_stream_id' => 'required_if:kind,fueling|nullable|integer', 'fuel_sort_id' => 'required_if:kind,fueling|nullable|exists:fuel_sorts,id', 'fueling_type_id' => 'required_if:kind,fueling|nullable|exists:fueling_types,id', 'expense_type_id' => 'required_if:kind,expense|nullable|exists:expense_types,id', 'repetition_months' => 'required_if:kind,expense|nullable|exists:repetition_intervals,id']);

        return DB::transaction(function () use ($r, $v, $d, $id) {
            $v = Vehicle::whereKey($v->id)->lockForUpdate()->firstOrFail();
            abort_if($v->archived_at, 409, 'Vozidlo je archivované. Nejprve jej obnovte v Mojí garáži.');
            ksort($d);
            $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
            if (! $id) {
                $existing = VehicleEvent::where('vehicle_id', $v->id)->where('request_key', $d['request_key'])->first();
                if ($existing) {
                    abort_if($existing->request_hash !== $hash, 409, 'Klíč požadavku již byl použit pro jiný obsah.');

                    return response()->json($this->snapshot($existing), 200);
                }
            }
            $e = $id ? VehicleEvent::where('vehicle_id', $v->id)->lockForUpdate()->findOrFail($id) : new VehicleEvent;
            if ($id) {
                abort_if((int) $e->revision !== (int) $d['revision'], 409, 'Záznam byl mezitím změněn.');
                abort_if($e->kind !== $d['kind'], 422, 'Druh záznamu nelze změnit.');
            }
            $before = $id ? $this->snapshot($e) : null;
            $detail = [];
            if ($d['kind'] === 'fueling') {
                $stream = DB::table('vehicle_energy_streams')->where('vehicle_id', $v->id)->where('is_active', true)->find($d['energy_stream_id']);
                $fuel = DB::table('fuel_sorts')->find($d['fuel_sort_id']);
                abort_unless($stream && $stream->medium_group === $fuel->medium_group && $stream->quantity_unit === $fuel->quantity_unit, 422, 'Palivo neodpovídá nádrži a jednotce.');
                $detail = ['energy_stream_id' => $stream->id, 'fuel_sort_id' => $fuel->id, 'fueling_type_id' => $d['fueling_type_id'], 'quantity' => $d['quantity'], 'quantity_unit' => $stream->quantity_unit, 'consumption_distance_unit' => $v->distance_unit, 'speed_unit' => $v->distance_unit.'/h'];
            } else {
                $detail = ['expense_type_id' => $d['expense_type_id'], 'repetition_months' => $d['repetition_months']];
            }
            foreach (['kind', 'occurred_on', 'odometer', 'amount', 'currency_code', 'note'] as $key) {
                $e->$key = $d[$key] ?? null;
            }
            $e->vehicle_id = $v->id;
            if (! $id) {
                $e->created_by = $r->user()->id;
                $e->request_key = $d['request_key'];
                $e->request_hash = $hash;
            } else {
                $e->revision++;
            }
            $e->save();
            DB::table($d['kind'] === 'fueling' ? 'fuelings' : 'expenses')->updateOrInsert(['event_id' => $e->id], $detail);
            DB::table('event_revisions')->insert(['event_id' => $e->id, 'actor_id' => $r->user()->id, 'action' => $id ? 'update' : 'create', 'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after_snapshot' => json_encode($this->snapshot($e), JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return response()->json($this->snapshot($e), $id ? 200 : 201);
        });
    }
}
