<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\RecurringExpenses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RecurringExpenseController extends Controller
{
    private function vehicle(Request $r, string $id, bool $lock = false): Vehicle
    {
        return Vehicle::where('owner_id', $r->user()->id)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function index(Request $r, string $vehicle)
    {
        $v = $this->vehicle($r, $vehicle);
        $d = $r->validate(['status' => ['nullable', Rule::in(['active', 'ended', 'all'])], 'page' => 'nullable|integer|min:1|max:1000000']);

        return DB::table('recurring_expense_plans as p')->join('vehicle_events as e', 'e.id', '=', 'p.source_event_id')->join('expenses as x', 'x.event_id', '=', 'e.id')->where('p.vehicle_id', $v->id)
            ->when(($d['status'] ?? 'active') === 'active', fn ($q) => $q->whereNull('p.ended_on'))
            ->when(($d['status'] ?? '') === 'ended', fn ($q) => $q->whereNotNull('p.ended_on'))
            ->select('p.*', 'e.amount as estimated_amount', 'e.currency_code', 'e.note', 'x.expense_type_id', 'e.deleted_at as source_deleted_at')
            ->orderBy('p.next_due_on')->orderBy('p.id')->paginate(25)->through(function ($p) {
                foreach (['id', 'vehicle_id', 'source_event_id'] as $key) {
                    $p->$key = (string) $p->$key;
                }

                return $p;
            });
    }

    public function store(Request $r, string $vehicle)
    {
        $d = $r->validate(['source_event_id' => 'required|integer', 'source_revision' => 'required|integer|min:1', 'name' => 'nullable|string|max:160']);

        return DB::transaction(function () use ($r, $vehicle, $d) {
            $v = $this->vehicle($r, $vehicle, true);
            abort_if($v->archived_at, 409, 'Vozidlo je archivované.');
            $e = VehicleEvent::where('vehicle_id', $v->id)->where('kind', 'expense')->findOrFail($d['source_event_id']);
            abort_unless($e->revision === (int) $d['source_revision'], 409, 'Zdrojový výdaj se změnil.');

            return response()->json(RecurringExpenses::create($r, $e, $d['name'] ?? null), 201);
        });
    }

    public function update(Request $r, string $vehicle, string $plan)
    {
        $d = $r->validate(['revision' => 'required|integer|min:1', 'name' => 'required|string|max:160', 'interval_months' => ['required', Rule::in([1, 3, 6, 12, 24, 36])], 'end' => 'nullable|boolean']);

        return DB::transaction(function () use ($r, $vehicle, $plan, $d) {
            $v = $this->vehicle($r, $vehicle, true);
            abort_if($v->archived_at, 409, 'Vozidlo je archivované.');
            $p = DB::table('recurring_expense_plans')->where('vehicle_id', $v->id)->lockForUpdate()->find($plan);
            abort_unless($p, 404);
            abort_if($p->ended_on || $p->revision !== (int) $d['revision'], 409, 'Plán se změnil nebo byl ukončen.');
            // Changing frequency starts a new schedule at the last covered anchor occurrence.
            $anchor = RecurringExpenses::due($p->anchor_date, $p->interval_months, $p->occurrence - 1);
            $occurrence = 1;
            while (RecurringExpenses::due($p->anchor_date, (int) $d['interval_months'], $occurrence) <= $anchor) {
                $occurrence++;
            }
            $changed = (int) $p->interval_months !== (int) $d['interval_months'];
            DB::table('recurring_expense_plans')->where('id', $p->id)->update(['name' => $d['name'], 'interval_months' => $d['interval_months'], 'anchor_date' => $p->anchor_date, 'occurrence' => $changed ? $occurrence : $p->occurrence, 'next_due_on' => $changed ? RecurringExpenses::due($p->anchor_date, (int) $d['interval_months'], $occurrence) : $p->next_due_on, 'ended_on' => ($d['end'] ?? false) ? now()->toDateString() : null, 'end_reason' => ($d['end'] ?? false) ? 'Ukončeno uživatelem.' : null, 'revision' => $p->revision + 1, 'updated_at' => now()]);
            RecurringExpenses::audit($r, $p->id, ($d['end'] ?? false) ? 'end' : 'update', $p);

            return DB::table('recurring_expense_plans')->find($p->id);
        });
    }
}
