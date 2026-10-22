<?php

namespace App\Services;

use App\Models\VehicleEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventSnapshot
{
    public static function make(VehicleEvent $e): array
    {
        return self::many(collect([$e]))[$e->id];
    }

    public static function many(Collection $events): array
    {
        $details = [];
        $roads = [];
        foreach (['fueling' => 'fuelings', 'expense' => 'expenses'] as $kind => $table) {
            $ids = $events->where('kind', $kind)->pluck('id')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (DB::table($table)->whereIn('event_id', $chunk)->get() as $row) {
                    $details[(string) $row->event_id] = (array) $row;
                }
                if ($kind === 'expense') {
                    foreach (DB::table('recurring_expense_plans')->whereIn('source_event_id', $chunk)->get() as $plan) {
                        $details[(string) $plan->source_event_id]['recurring_plan_id'] = (string) $plan->id;
                    }
                    foreach (DB::table('recurring_expense_payments')->whereIn('event_id', $chunk)->get() as $payment) {
                        $details[(string) $payment->event_id]['payment_plan_id'] = (string) $payment->plan_id;
                        $details[(string) $payment->event_id]['payment_due_on'] = $payment->due_on;
                    }
                }
                if ($kind === 'fueling') {
                    foreach (DB::table('fueling_roads')->whereIn('event_id', $chunk)->orderBy('road_type_id')->get() as $road) {
                        $roads[(string) $road->event_id][] = (int) $road->road_type_id;
                    }
                }
            }
        }
        $snapshots = [];
        foreach ($events as $e) {
            $detail = $details[$e->id] ?? [];
            foreach (['event_id', 'energy_stream_id', 'quantity', 'reported_trip', 'reported_consumption', 'bc_consumption', 'bc_quantity', 'bc_speed', 'latitude', 'longitude'] as $key) {
                if (isset($detail[$key])) {
                    $detail[$key] = (string) $detail[$key];
                }
            }
            if ($e->kind === 'fueling') {
                $detail['roads'] = $roads[$e->id] ?? [];
            }
            $snapshots[$e->id] = ['event' => $e->toArray(), 'detail' => $detail];
        }

        return $snapshots;
    }
}
