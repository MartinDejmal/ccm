<?php

namespace App\Services;

use App\Models\VehicleEvent;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecurringExpenses
{
    public static function due(string $anchor, int $interval, int $occurrence): string
    {
        $date = new DateTimeImmutable($anchor);
        $month = $date->modify('first day of this month')->modify('+'.($interval * $occurrence).' months');
        abort_if((int) $month->format('Y') > 9999, 422, 'Další termín je mimo podporovaný rozsah data.');

        return $month->setDate((int) $month->format('Y'), (int) $month->format('m'), min((int) $date->format('d'), (int) $month->format('t')))->format('Y-m-d');
    }

    public static function audit(Request $r, int|string $id, string $action, ?object $before): void
    {
        DB::table('recurring_expense_revisions')->insert(['plan_id' => $id, 'actor_id' => $r->user()->id, 'action' => $action, 'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after_snapshot' => json_encode(DB::table('recurring_expense_plans')->find($id), JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    // Caller holds the vehicle lock; all changes share the financial transaction.
    public static function create(Request $r, VehicleEvent $event, ?string $name = null): object
    {
        $expense = DB::table('expenses')->where('event_id', $event->id)->first();
        abort_unless($expense && ! $event->trashed() && $expense->repetition_months > 0, 422, 'Zdroj musí být platný výdaj s nenulovým intervalem.');
        $existing = DB::table('recurring_expense_plans')->where('source_event_id', $event->id)->first();
        abort_if($existing, 409, 'Z tohoto výdaje již plán existuje, případně byl ukončen.');
        $anchor = (string) $event->occurred_on;
        $id = DB::table('recurring_expense_plans')->insertGetId(['vehicle_id' => $event->vehicle_id, 'source_event_id' => $event->id, 'name' => $name ?? DB::table('expense_types')->where('id', $expense->expense_type_id)->value('label'), 'interval_months' => $expense->repetition_months, 'anchor_date' => $anchor, 'occurrence' => 1, 'next_due_on' => self::due($anchor, $expense->repetition_months, 1), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        self::audit($r, $id, 'create', null);

        return DB::table('recurring_expense_plans')->find($id);
    }

    public static function pay(Request $r, VehicleEvent $event, array $d): void
    {
        $p = DB::table('recurring_expense_plans')->where('vehicle_id', $event->vehicle_id)->lockForUpdate()->find($d['plan_id']);
        abort_unless($p, 404);
        abort_if($p->ended_on || (int) $p->revision !== (int) $d['plan_revision'] || $p->next_due_on !== $d['plan_due_on'], 409, 'Plán se změnil. Načtěte aktuální termín.');
        abort_unless(VehicleEvent::whereKey($p->source_event_id)->exists() && $event->kind === 'expense', 422, 'Plán nemá platný zdrojový výdaj.');
        DB::table('recurring_expense_payments')->insert(['plan_id' => $p->id, 'event_id' => $event->id, 'due_on' => $p->next_due_on, 'created_at' => now(), 'updated_at' => now()]);
        $next = self::due($p->anchor_date, $p->interval_months, $p->occurrence + 1);
        abort_if($next > '9999-12-31', 422, 'Další termín je mimo podporované datum.');
        DB::table('recurring_expense_plans')->where('id', $p->id)->update(['occurrence' => $p->occurrence + 1, 'next_due_on' => $next, 'revision' => $p->revision + 1, 'updated_at' => now()]);
        self::audit($r, $p->id, 'payment', $p);
    }

    public static function endForRemovedEvents(Request $r, array $ids): void
    {
        $plans = DB::table('recurring_expense_plans')->whereNull('ended_on')->where(function ($q) use ($ids) {
            $q->whereIn('source_event_id', $ids)->orWhereIn('id', DB::table('recurring_expense_payments')->whereIn('event_id', $ids)->select('plan_id'));
        })->get();
        foreach ($plans as $p) {
            DB::table('recurring_expense_plans')->where('id', $p->id)->update(['ended_on' => now()->toDateString(), 'end_reason' => 'Vrácení importu vyřadilo zdroj nebo propojenou platbu.', 'revision' => $p->revision + 1, 'updated_at' => now()]);
            self::audit($r, $p->id, 'import_revert', $p);
        }
    }
}
