<?php

namespace App\Services\Consumption;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class ConsumptionCalculator
{
    public function calculate(array $stream, array $rows, string $distanceUnit, ?string $from = null, ?string $to = null): array
    {
        $method = in_array($stream['medium_group'], ['electricity', 'additive'], true) ? 'delivery_only' : $stream['consumption_method'];
        $deliveryOnly = $method === 'delivery_only';
        $anchor = null;
        $previous = null;
        $pending = BigDecimal::zero();
        $members = [];
        $output = [];
        $totalQuantity = BigDecimal::zero();
        $totalDistance = BigDecimal::zero();
        $intervalCount = 0;
        $coverageFrom = null;
        $coverageTo = null;
        $covered = [];
        $inRange = fn ($date) => ($from === null || $date >= $from) && ($to === null || $date <= $to);
        $boundary = fn ($r) => ['event_id' => (string) $r['id'], 'date' => $r['occurred_on'], 'odometer' => (string) BigDecimal::of($r['odometer'])->toScale(3)];
        $reset = function (?string $cause = null) use (&$anchor, &$previous, &$pending, &$members, &$output) {
            foreach ($members as $id) {
                if (isset($output[$id]) && $output[$id]['status'] === 'partial') {
                    $output[$id]['reason'] = 'interrupted_interval';
                    $output[$id]['break_event_id'] = $cause;
                }
            }
            $anchor = null;
            $previous = null;
            $pending = BigDecimal::zero();
            $members = [];
        };
        $days = [];
        foreach ($rows as $row) {
            if ($to === null || $row['occurred_on'] <= $to) {
                $days[$row['occurred_on']][] = $row;
            }
        }
        ksort($days);
        foreach ($days as $day) {
            $sequences = array_column($day, 'day_sequence');
            $ambiguous = count($day) > 1 && (in_array(0, array_map('intval', $sequences), true) || count(array_unique($sequences)) !== count($day));
            if ($ambiguous) {
                $reset((string) $day[0]['id']);
            } else {
                usort($day, fn ($a, $b) => (int) $a['day_sequence'] <=> (int) $b['day_sequence']);
            }
            foreach ($day as $r) {
                $id = (string) $r['id'];
                $quantity = BigDecimal::of($r['quantity']);
                $unitsMatch = $r['quantity_unit'] === $stream['quantity_unit'] && $r['consumption_distance_unit'] === $distanceUnit;
                $mediumMatches = $r['fuel_medium_group'] === $stream['medium_group'];
                $base = ['event_id' => $id, 'energy_stream_id' => (string) $stream['id'], 'occurred_on' => $r['occurred_on'], 'day_sequence' => (int) $r['day_sequence'],
                    'odometer' => $r['odometer'] === null ? null : (string) BigDecimal::of($r['odometer'])->toScale(3), 'quantity' => (string) $quantity->toScale(4),
                    'quantity_unit' => $r['quantity_unit'], 'distance_unit' => $distanceUnit, 'fueling_type_id' => (int) $r['fueling_type_id'],
                    'reported_consumption' => $r['reported_consumption'], 'bc_consumption' => $r['bc_consumption'], 'source_distance_unit' => $r['consumption_distance_unit'],
                    'deleted' => $r['deleted_at'] !== null, 'break_before' => (bool) $r['break_before'], 'status' => 'unavailable', 'reason' => null,
                    'computed_consumption' => null, 'delivery_per_distance' => null, 'interval' => null, 'warnings' => [], 'starts_new_chain' => false,
                    'counted_delivery' => $r['deleted_at'] === null && $r['quantity_unit'] === $stream['quantity_unit'] && $mediumMatches && $quantity->isGreaterThan(0)];
                if ($base['deleted'] || $ambiguous || ! $unitsMatch || ! $mediumMatches || ! $quantity->isGreaterThan(0) || ! in_array($method, ['full_to_full', 'delivery_only'], true)) {
                    $base['status'] = $base['deleted'] ? 'excluded' : 'unavailable';
                    $base['reason'] = $base['deleted'] ? 'deleted_event' : ($ambiguous ? 'ambiguous_order' : (! $unitsMatch ? 'unit_mismatch' : (! $mediumMatches ? 'medium_mismatch' : (! $quantity->isGreaterThan(0) ? 'invalid_quantity' : 'unsupported_method'))));
                    $reset($id);
                    $output[$id] = $base;

                    continue;
                }
                if ($base['break_before']) {
                    $reset($id);
                    $base['warnings'][] = 'explicit_history_gap';
                }
                $type = $base['fueling_type_id'];
                if ($type === 0 || ! in_array($type, [1, 2, 3], true)) {
                    $base['reason'] = $type === 0 ? 'invalid_fueling' : 'unknown_fueling_type';
                    $reset($id);
                    $output[$id] = $base;

                    continue;
                }
                if ($r['odometer'] === null) {
                    $base['reason'] = 'missing_odometer';
                    $reset($id);
                    $output[$id] = $base;

                    continue;
                }
                $reading = BigDecimal::of($r['odometer']);
                $decreased = $previous !== null && $reading->isLessThan($previous);
                if ($previous !== null && $r['reported_trip'] !== null && ! $decreased && $reading->minus($previous)->minus($r['reported_trip'])->abs()->isGreaterThan('1')) {
                    $base['warnings'][] = 'trip_difference';
                }
                if ($decreased) {
                    $reset($id);
                    $base['reason'] = 'odometer_decrease';
                    if ($type === 1 || $deliveryOnly) {
                        $anchor = $boundary($r);
                        $base['starts_new_chain'] = true;
                    }
                    $previous = $reading;
                    $output[$id] = $base;

                    continue;
                }
                $previous = $reading;
                if ($type === 3) {
                    $reset($id);
                    $previous = $reading;
                    $base['reason'] = 'first_fueling';
                    if ($deliveryOnly) {
                        $anchor = $boundary($r);
                        $base['starts_new_chain'] = true;
                    }
                    $output[$id] = $base;

                    continue;
                }
                if ($anchor === null) {
                    if ($type === 1 || $deliveryOnly) {
                        $anchor = $boundary($r);
                        $base['status'] = 'anchor';
                        $base['reason'] = $base['break_before'] ? 'history_gap' : ($deliveryOnly ? 'delivery_anchor' : 'full_anchor');
                        $base['starts_new_chain'] = true;
                    } else {
                        $base['reason'] = $base['break_before'] ? 'history_gap' : 'missing_anchor';
                    }
                    $output[$id] = $base;

                    continue;
                }
                $pending = $pending->plus($quantity);
                $members[] = $id;
                if ($type === 2 && ! $deliveryOnly) {
                    $base['status'] = 'partial';
                    $base['reason'] = 'partial_fueling';
                    $output[$id] = $base;

                    continue;
                }
                $distance = $reading->minus($anchor['odometer']);
                if (! $distance->isGreaterThan(0)) {
                    $reset($id);
                    $previous = $reading;
                    $anchor = $boundary($r);
                    $base['reason'] = 'zero_distance';
                    $base['starts_new_chain'] = true;
                    $output[$id] = $base;

                    continue;
                }
                $eligible = $inRange($anchor['date']) && $inRange($r['occurred_on']);
                $value = (string) $pending->multipliedBy(100)->dividedBy($distance, 4, RoundingMode::HalfUp);
                $base['status'] = 'computed';
                $base['reason'] = $eligible ? ($deliveryOnly ? 'delivery_interval' : 'complete_interval') : 'outside_period';
                $base[$deliveryOnly ? 'delivery_per_distance' : 'computed_consumption'] = $value;
                $base['interval'] = ['start' => $anchor, 'end' => $boundary($r), 'quantity' => (string) $pending->toScale(4), 'distance' => (string) $distance->toScale(3), 'event_ids' => $members, 'included_in_average' => $eligible];
                foreach ($members as $member) {
                    if (isset($output[$member]) && $output[$member]['status'] === 'partial') {
                        $output[$member]['reason'] = $eligible ? 'partial_in_complete_interval' : 'partial_outside_period';
                        $output[$member]['closing_event_id'] = $id;
                    }
                    if ($eligible) {
                        $covered[$member] = true;
                    }
                }
                if ($eligible) {
                    $totalQuantity = $totalQuantity->plus($pending);
                    $totalDistance = $totalDistance->plus($distance);
                    $intervalCount++;
                    $coverageFrom ??= $anchor['date'];
                    $coverageTo = $r['occurred_on'];
                }
                $output[$id] = $base;
                $anchor = $boundary($r);
                $pending = BigDecimal::zero();
                $members = [];
            }
        }
        $selected = array_values(array_filter($output, fn ($row) => $inRange($row['occurred_on'])));
        $delivered = BigDecimal::zero();
        $uncovered = 0;
        $quality = [];
        foreach ($selected as $row) {
            $quality[$row['reason']] = ($quality[$row['reason']] ?? 0) + 1;
            if ($row['counted_delivery']) {
                $delivered = $delivered->plus($row['quantity']);
                if (! isset($covered[$row['event_id']])) {
                    $uncovered++;
                }
            }
        }
        $value = $totalDistance->isGreaterThan(0) ? (string) $totalQuantity->multipliedBy(100)->dividedBy($totalDistance, 4, RoundingMode::HalfUp) : null;
        $hasOpen = (bool) array_filter($members, fn ($id) => isset($output[$id]) && $inRange($output[$id]['occurred_on']));

        return ['summary' => ['id' => (string) $stream['id'], 'name' => $stream['name'], 'medium_group' => $stream['medium_group'], 'quantity_unit' => $stream['quantity_unit'],
            'distance_unit' => $distanceUnit, 'method' => $method, 'is_active' => (bool) $stream['is_active'], 'interval_count' => $intervalCount,
            'average_consumption' => $deliveryOnly ? null : $value, 'delivery_per_distance' => $deliveryOnly ? $value : null,
            'accounted_quantity' => (string) $totalQuantity->toScale(4), 'accounted_distance' => (string) $totalDistance->toScale(3), 'delivered_quantity' => (string) $delivered->toScale(4),
            'uncovered_event_count' => $uncovered, 'coverage_from' => $coverageFrom, 'coverage_to' => $coverageTo, 'quality' => $quality,
            'open_interval' => $hasOpen ? ['start' => $anchor, 'quantity' => (string) $pending->toScale(4), 'event_ids' => $members] : null], 'observations' => $selected];
    }
}
