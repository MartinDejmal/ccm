<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\EventSnapshot;
use App\Services\RecurringExpenses;
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
        return EventSnapshot::make($e);
    }

    private function saveEvent(Request $r, string $vehicle, ?string $id = null)
    {
        $v = $this->vehicle($r, $vehicle);
        abort_if($v->archived_at, 409, 'Vozidlo je archivované.');
        $d = $r->validate(['request_key' => ($id ? 'nullable' : 'required').'|uuid', 'kind' => ['required', Rule::in(['fueling', 'expense'])], 'occurred_on' => 'required|date_format:Y-m-d', 'odometer' => 'nullable|decimal:0,3|min:0|max:99999999999.999', 'amount' => 'required|decimal:0,4|between:-99999999999999.9999,99999999999999.9999', 'currency_code' => 'required|exists:currencies,code', 'note' => 'nullable|string|max:20000', 'day_sequence' => 'nullable|integer|min:0|max:10000', 'break_before' => 'nullable|boolean', 'revision' => ($id ? 'required' : 'nullable').'|integer|min:1', 'quantity' => 'required_if:kind,fueling|nullable|decimal:0,4|gt:0|max:9999999999.9999', 'energy_stream_id' => 'required_if:kind,fueling|nullable|integer', 'fuel_sort_id' => 'required_if:kind,fueling|nullable|exists:fuel_sorts,id', 'fueling_type_id' => 'required_if:kind,fueling|nullable|exists:fueling_types,id', 'expense_type_id' => 'required_if:kind,expense|nullable|exists:expense_types,id', 'create_plan' => 'nullable|boolean', 'plan_id' => 'nullable|integer', 'plan_revision' => 'required_with:plan_id|integer|min:1', 'plan_due_on' => 'required_with:plan_id|date_format:Y-m-d', 'repetition_months' => 'required_if:kind,expense|nullable|exists:repetition_intervals,id']);

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
            abort_if(($d['create_plan'] ?? false) && ($d['kind'] !== 'expense' || ! empty($d['plan_id'])), 422, 'Plán lze vytvořit pouze ze samostatného výdaje.');
            abort_if(! empty($d['plan_id']) && ($id || $d['kind'] !== 'expense'), 422, 'Platbu plánu lze propojit pouze při vytvoření výdaje.');
            $before = $id ? $this->snapshot($e) : null;
            $detail = [];
            if ($d['kind'] === 'fueling') {
                $stream = DB::table('vehicle_energy_streams')->where('vehicle_id', $v->id)->where('is_active', true)->find($d['energy_stream_id']);
                $fuel = DB::table('fuel_sorts')->find($d['fuel_sort_id']);
                abort_unless($stream && $stream->medium_group === $fuel->medium_group && $stream->quantity_unit === $fuel->quantity_unit, 422, 'Palivo neodpovídá nádrži a jednotce.');
                $detail = ['break_before' => $d['break_before'] ?? ($id ? DB::table('fuelings')->where('event_id', $id)->value('break_before') : false), 'energy_stream_id' => $stream->id, 'fuel_sort_id' => $fuel->id, 'fueling_type_id' => $d['fueling_type_id'], 'quantity' => $d['quantity'], 'quantity_unit' => $stream->quantity_unit, 'consumption_distance_unit' => $v->distance_unit, 'speed_unit' => $v->distance_unit.'/h'];
            } else {
                $detail = ['expense_type_id' => $d['expense_type_id'], 'repetition_months' => $d['repetition_months']];
            }
            if ($d['kind'] === 'fueling') {
                $sequence = $d['day_sequence'] ?? ($id ? $e->day_sequence : 0);
                abort_if($sequence > 0 && DB::table('vehicle_events as x')->join('fuelings as f', 'f.event_id', '=', 'x.id')->where('x.vehicle_id', $v->id)->where('f.energy_stream_id', $d['energy_stream_id'])->where('x.occurred_on', $d['occurred_on'])->where('x.day_sequence', $sequence)->when($id, fn ($q) => $q->where('x.id', '!=', $id))->exists(), 422, 'Toto pořadí je ve stejném dni a nádrži již použité (i ve vyřazené historii).');
                $e->day_sequence = $sequence;
            }
            foreach (['kind', 'occurred_on', 'odometer', 'amount', 'currency_code', 'note'] as $key) {
                $e->$key = $d[$key] ?? null;
            }
            $e->vehicle_id = $v->id;
            if (! $id) {
                $e->revision = 1;
                $e->created_by = $r->user()->id;
                $e->request_key = $d['request_key'];
                $e->request_hash = $hash;
            } else {
                $e->revision++;
            }
            $e->save();
            DB::table($d['kind'] === 'fueling' ? 'fuelings' : 'expenses')->updateOrInsert(['event_id' => $e->id], $detail);
            if ($d['create_plan'] ?? false) {
                RecurringExpenses::create($r, $e);
            }
            if (! empty($d['plan_id'])) {
                RecurringExpenses::pay($r, $e, $d);
            }
            DB::table('event_revisions')->insert(['event_id' => $e->id, 'actor_id' => $r->user()->id, 'action' => $id ? 'update' : 'create', 'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after_snapshot' => json_encode($this->snapshot($e), JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return response()->json($this->snapshot($e), $id ? 200 : 201);
        });
    }
}
