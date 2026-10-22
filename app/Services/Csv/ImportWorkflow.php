<?php

namespace App\Services\Csv;

use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\EventSnapshot;
use App\Services\RecurringExpenses;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportWorkflow
{
    public const DRAFT = ['ready', 'needs_review'];

    public static function audit(Request $r, string $batch, string $action, array $context = []): void
    {
        DB::table('import_actions')->insert(['batch_id' => $batch, 'actor_id' => $r->user()->id, 'action' => $action, 'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function transaction(callable $callback): mixed
    {
        try {
            return DB::transaction($callback, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Klíč operace nebo importovaný řádek již byl použit. Načtěte aktuální stav dávky.');
        }
    }

    private function locked(Request $r, string $vehicle, string $batch): array
    {
        $v = Vehicle::where('owner_id', $r->user()->id)->lockForUpdate()->findOrFail($vehicle);
        $b = DB::table('import_batches')->where('vehicle_id', $v->id)->where('id', $batch)->lockForUpdate()->firstOrFail();

        return [$v, $b];
    }

    private function draft(Vehicle $v, object $b, int $revision): void
    {
        abort_unless(in_array($b->status, self::DRAFT, true), 409, 'Dávka již není upravitelný náhled.');
        abort_if($v->archived_at, 409, 'Vozidlo je archivované. Nejprve jej obnovte.');
        abort_if((int) $b->revision !== $revision, 409, 'Dávka byla mezitím změněna. Načtěte její aktuální stav.');
    }

    // Candidate identity is deliberately broader than exact CSV identity: notes and
    // odometer differences must not conceal a second payment on the same day.
    private function key(array $event, array $detail): string
    {
        return hash('sha256', json_encode([
            $event['kind'], $event['occurred_on'], (string) BigDecimal::of($event['amount'])->toScale(4), strtoupper($event['currency_code']),
            $event['kind'] === 'expense' ? [(int) $detail['expense_type_id']] : [(int) $detail['fuel_sort_id'], (string) BigDecimal::of($detail['quantity'])->toScale(4), $detail['quantity_unit'], (string) $detail['energy_stream_id']],
        ], JSON_THROW_ON_ERROR));
    }

    private function inspect(Vehicle $v, object $b): array
    {
        abort_unless($b->profile_version === '1' && hash_equals($b->file_sha256, hash('sha256', $b->raw_csv)), 409, 'Podklad nebo verze náhledu se změnily. Vytvořte nový náhled.');
        $options = json_decode($b->parameters, true, 512, JSON_THROW_ON_ERROR);
        abort_unless($options['distance_unit'] === $v->distance_unit, 409, 'Jednotka vozidla neodpovídá náhledu.');
        $stream = null;
        if ($b->kind === 'fueling') {
            $stream = DB::table('vehicle_energy_streams')->where('vehicle_id', $v->id)->where('is_active', true)->find($options['energy_stream_id']);
            abort_unless($stream && $stream->quantity_unit === $options['quantity_unit'], 409, 'Nádrž nebo její jednotka již neodpovídají náhledu.');
        }
        $parsed = app(ImportPreview::class)->parse($b->raw_csv, $options, $stream);
        $stored = DB::table('import_rows')->where('batch_id', $b->id)->orderBy('record_number')->get();
        abort_unless(count($parsed['rows']) === $stored->count(), 409, 'Počet řádků náhledu se změnil. Vytvořte nový náhled.');
        $rows = [];
        $dates = [];
        foreach ($stored as $i => $row) {
            $fresh = $parsed['rows'][$i];
            abort_unless($fresh['fingerprint'] === $row->fingerprint && $fresh['raw_record'] === $row->raw_record, 409, 'Interpretace náhledu se změnila. Vytvořte nový náhled.');
            $row->data = $fresh['normalized'];
            $row->originalIssues = $row->issues;
            $row->originalStatus = $row->status;
            $row->issues = $fresh['issues'];
            $row->status = $row->status === 'skipped' ? 'skipped' : $fresh['status'];
            if (in_array($row->status, ['valid', 'warning'], true)) {
                $dates[] = $row->data['event']['occurred_on'];
            }
            $rows[] = $row;
        }
        $history = [];
        if ($dates) {
            $query = DB::table('vehicle_events as e')->join($b->kind === 'fueling' ? 'fuelings as d' : 'expenses as d', 'd.event_id', '=', 'e.id')
                ->where('e.vehicle_id', $v->id)->where('e.kind', $b->kind)->whereNull('e.deleted_at')->whereIn('e.occurred_on', array_values(array_unique($dates)))
                ->select(['e.id', 'e.kind', 'e.occurred_on', 'e.amount', 'e.currency_code', 'e.revision', ...($b->kind === 'fueling' ? ['d.fuel_sort_id', 'd.quantity', 'd.quantity_unit', 'd.energy_stream_id'] : ['d.expense_type_id'])])->orderBy('e.id');
            foreach ($query->get() as $event) {
                $history[$this->key((array) $event, (array) $event)][] = ['id' => (string) $event->id, 'revision' => (int) $event->revision];
            }
        }
        foreach ($history as &$group) {
            $group = ['items' => array_slice($group, 0, 20), 'count' => count($group), 'token' => hash('sha256', json_encode($group, JSON_THROW_ON_ERROR))];
        }
        unset($group);
        $seen = [];
        $unresolved = 0;
        $duplicateRows = 0;
        $errors = 0;
        $included = 0;
        $tokens = [];
        foreach ($rows as $row) {
            $matches = ['history' => [], 'file' => [], 'required' => false, 'token' => null, 'approved' => false];
            if ($row->status === 'error') {
                $errors++;
            }
            if (in_array($row->status, ['valid', 'warning'], true)) {
                $included++;
                $key = $this->key($row->data['event'], $row->data['detail']);
                $group = $history[$key] ?? ['items' => [], 'count' => 0, 'token' => ''];
                $file = $seen[$key] ?? ['items' => [], 'count' => 0, 'token' => ''];
                $required = $group['count'] > 0 || $file['count'] > 0;
                $token = hash('sha256', json_encode([$key, $group['token'], $file['token']], JSON_THROW_ON_ERROR));
                $approved = $required && $row->approved_duplicate_token === $token;
                $matches = ['history' => $group['items'], 'history_count' => $group['count'], 'file' => $file['items'], 'file_count' => $file['count'], 'required' => $required, 'token' => $token, 'approved' => $approved];
                $seen[$key] = ['items' => $file['items'] ?: [['id' => (string) $row->id, 'record_number' => $row->record_number]], 'count' => $file['count'] + 1, 'token' => hash('sha256', $file['token'].'|'.$row->id.'|'.$row->fingerprint)];
                if ($required) {
                    $duplicateRows++;
                    if (! $approved) {
                        $unresolved++;
                    }
                }
            }
            $row->matches = $matches;
            $tokens[] = [(string) $row->id, $row->status, $row->fingerprint, $matches['token'], $matches['approved']];
        }

        return ['rows' => $rows, 'token' => hash('sha256', json_encode($tokens, JSON_THROW_ON_ERROR)), 'errors' => $errors, 'included' => $included, 'duplicate_rows' => $duplicateRows, 'unresolved_duplicate_rows' => $unresolved];
    }

    private function saveReview(object $b, array $inspection): void
    {
        $summary = json_decode($b->summary, true, 512, JSON_THROW_ON_ERROR);
        foreach (['valid', 'warning', 'error', 'skipped'] as $status) {
            $summary[$status.'_rows'] = 0;
        }
        $summary['amounts_by_currency'] = [];
        foreach ($inspection['rows'] as $row) {
            $duplicates = $row->matches['required'] ? json_encode($row->matches, JSON_THROW_ON_ERROR) : null;
            $issues = json_encode($row->issues, JSON_THROW_ON_ERROR);
            if ($duplicates !== $row->duplicates || $issues !== $row->originalIssues || $row->status !== $row->originalStatus) {
                DB::table('import_rows')->where('id', $row->id)->update(['duplicates' => $duplicates, 'issues' => $issues, 'status' => $row->status]);
            }
            $summary[$row->status.'_rows']++;
            if (in_array($row->status, ['valid', 'warning'], true)) {
                $event = $row->data['event'];
                $currency = $event['currency_code'];
                $summary['amounts_by_currency'][$currency] = (string) BigDecimal::of($summary['amounts_by_currency'][$currency] ?? '0')->plus($event['amount'])->toScale(4);
            }
        }
        $summary['duplicate_rows'] = $inspection['duplicate_rows'];
        $summary['unresolved_duplicate_rows'] = $inspection['unresolved_duplicate_rows'];
        DB::table('import_batches')->where('id', $b->id)->update(['review_token' => $inspection['token'], 'status' => $inspection['errors'] ? 'needs_review' : 'ready', 'summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'revision' => $b->revision + 1, 'updated_at' => now()]);
    }

    public function review(Request $r, string $vehicle, string $batch, array $d): void
    {
        $this->transaction(function () use ($r, $vehicle, $batch, $d) {
            [$v, $b] = $this->locked($r, $vehicle, $batch);
            $this->draft($v, $b, (int) $d['revision']);
            $inspection = $this->inspect($v, $b);
            $this->saveReview($b, $inspection);
            self::audit($r, $batch, 'review', ['duplicate_rows' => $inspection['duplicate_rows']]);
        });
    }

    public function decide(Request $r, string $vehicle, string $batch, string $rowId, array $d): void
    {
        $this->transaction(function () use ($r, $vehicle, $batch, $rowId, $d) {
            [$v, $b] = $this->locked($r, $vehicle, $batch);
            $this->draft($v, $b, (int) $d['revision']);
            $inspection = $this->inspect($v, $b);
            $row = collect($inspection['rows'])->first(fn ($row) => (string) $row->id === $rowId);
            abort_unless($row, 404);
            abort_unless($row->matches['required'] && hash_equals($row->matches['token'], $d['duplicate_token']), 409, 'Shody se změnily. Znovu ověřte duplicity.');
            DB::table('import_rows')->where('id', $rowId)->update(['approved_duplicate_token' => $d['keep'] ? $d['duplicate_token'] : null]);
            $this->saveReview($b, $this->inspect($v, $b));
            self::audit($r, $batch, 'duplicate_decision', ['row_id' => $rowId, 'keep' => $d['keep']]);
        });
    }

    public function confirm(Request $r, string $vehicle, string $batch, array $d): void
    {
        $this->transaction(function () use ($r, $vehicle, $batch, $d) {
            [$v, $b] = $this->locked($r, $vehicle, $batch);
            ksort($d);
            $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
            if ($b->confirm_key === $d['request_key']) {
                abort_unless(hash_equals($b->confirm_hash, $hash), 409, 'Klíč potvrzení byl použit pro jiný obsah.');

                return;
            }
            $this->draft($v, $b, (int) $d['revision']);
            abort_if(DB::table('import_batches')->where('confirm_key', $d['request_key'])->exists(), 409, 'Klíč potvrzení již patří jiné dávce.');
            $inspection = $this->inspect($v, $b);
            abort_unless($b->review_token && hash_equals($inspection['token'], $d['review_token']) && hash_equals($b->review_token, $d['review_token']), 409, 'Náhled nebo historie se změnily. Znovu ověřte duplicity.');
            abort_if($inspection['errors'] || $inspection['unresolved_duplicate_rows'] || ! $inspection['included'], 409, 'Vyřešte chyby a duplicity; dávka musí obsahovat alespoň jeden zahrnutý záznam.');
            $now = now();
            $inserts = [];
            $includedRows = [];
            foreach ($inspection['rows'] as $row) {
                if (! in_array($row->status, ['valid', 'warning'], true)) {
                    continue;
                }
                $includedRows[(string) $row->id] = $row;
                $inserts[] = [...$row->data['event'], 'vehicle_id' => $v->id, 'created_by' => $r->user()->id,
                    'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', json_encode($row->data, JSON_THROW_ON_ERROR)),
                    'revision' => 1, 'import_batch_id' => $batch, 'import_row_id' => (string) $row->id, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($inserts, 200) as $chunk) {
                DB::table('vehicle_events')->insert($chunk);
            }
            $events = VehicleEvent::where('import_batch_id', $batch)->orderBy('id')->get();
            $details = [];
            $roads = [];
            foreach ($events as $e) {
                $detail = $includedRows[$e->import_row_id]->data['detail'];
                foreach ($detail['roads'] ?? [] as $road) {
                    $roads[] = ['event_id' => $e->id, 'road_type_id' => $road];
                }
                unset($detail['roads']);
                $details[] = ['event_id' => $e->id, ...$detail];
            }
            foreach (array_chunk($details, 200) as $chunk) {
                DB::table($b->kind === 'fueling' ? 'fuelings' : 'expenses')->insert($chunk);
            }
            foreach (array_chunk($roads, 500) as $chunk) {
                DB::table('fueling_roads')->insert($chunk);
            }
            $audits = [];
            foreach (EventSnapshot::many($events) as $id => $snapshot) {
                $audits[] = ['event_id' => $id, 'actor_id' => $r->user()->id, 'action' => 'import', 'before_snapshot' => null, 'after_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => $now];
            }
            foreach (array_chunk($audits, 100) as $chunk) {
                DB::table('event_revisions')->insert($chunk);
            }
            $selectedPlans = array_map('strval', $d['plan_row_ids'] ?? []);
            foreach ($selectedPlans as $rowId) {
                abort_unless($b->kind === 'expense' && isset($includedRows[$rowId]), 422, 'Plán musí odkazovat na zahrnutý výdaj této dávky.');
                $event = $events->first(fn ($e) => (string) $e->import_row_id === $rowId);
                RecurringExpenses::create($r, $event);
            }
            $summary = json_decode($b->summary, true, 512, JSON_THROW_ON_ERROR);
            $result = ['created_plans' => count($selectedPlans), 'imported_count' => $inspection['included'], 'skipped_count' => $summary['skipped_rows'], 'amounts_by_currency' => $summary['amounts_by_currency']];
            DB::table('import_batches')->where('id', $batch)->update(['status' => 'imported', 'revision' => $b->revision + 1, 'confirm_key' => $d['request_key'], 'confirm_hash' => $hash, 'confirmed_at' => now(), 'confirmed_by' => $r->user()->id, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            self::audit($r, $batch, 'confirm', $result);
        });
    }

    private function reversalState(object $b): array
    {
        $events = VehicleEvent::withTrashed()->where('import_batch_id', $b->id)->orderBy('id')->get();
        $conflicts = [];
        $eligible = [];
        foreach ($events as $e) {
            if ((int) $e->revision !== 1 || $e->trashed()) {
                $conflicts[] = ['id' => $e->id, 'revision' => (int) $e->revision, 'occurred_on' => $e->occurred_on, 'amount' => $e->amount, 'currency_code' => $e->currency_code, 'deleted' => $e->trashed()];
            } else {
                $eligible[] = $e;
            }
        }
        $plans = DB::table('recurring_expense_plans')->whereIn('source_event_id', array_map(fn ($e) => $e->id, $eligible))->whereNull('ended_on')->orderBy('id')->get(['id', 'revision']);
        $token = hash('sha256', json_encode([$events->map(fn ($e) => [$e->id, (int) $e->revision, $e->deleted_at?->toISOString()])->all(), $plans->all()], JSON_THROW_ON_ERROR));

        return ['ending_plans' => $plans->count(), 'token' => $token, 'eligible' => $eligible, 'conflicts' => $conflicts];
    }

    public function reversal(Request $r, string $vehicle, string $batch): array
    {
        return $this->transaction(function () use ($r, $vehicle, $batch) {
            [, $b] = $this->locked($r, $vehicle, $batch);
            abort_unless($b->status === 'imported', 409, 'Vrátit lze pouze potvrzenou dávku.');
            $state = $this->reversalState($b);

            return ['revision' => (int) $b->revision, 'review_token' => $state['token'], 'ending_plans' => $state['ending_plans'], 'removable_count' => count($state['eligible']), 'conflicts' => $state['conflicts']];
        });
    }

    public function revert(Request $r, string $vehicle, string $batch, array $d): void
    {
        $this->transaction(function () use ($r, $vehicle, $batch, $d) {
            [$v, $b] = $this->locked($r, $vehicle, $batch);
            ksort($d);
            $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
            if ($b->revert_key === $d['request_key']) {
                abort_unless(hash_equals($b->revert_hash, $hash), 409, 'Klíč vrácení byl použit pro jiný obsah.');

                return;
            }
            abort_unless($b->status === 'imported' && (int) $b->revision === (int) $d['revision'], 409, 'Stav dávky se změnil. Načtěte aktuální stav.');
            abort_if($v->archived_at, 409, 'Nejprve obnovte archivované vozidlo.');
            abort_if(DB::table('import_batches')->where('revert_key', $d['request_key'])->exists(), 409, 'Klíč vrácení již patří jiné dávce.');
            $state = $this->reversalState($b);
            abort_unless(hash_equals($state['token'], $d['review_token']), 409, 'Importované záznamy se mezitím změnily. Znovu zkontrolujte vrácení.');
            abort_if($state['conflicts'] && $d['conflict_policy'] !== 'keep_changed', 409, 'Dávka obsahuje později změněné záznamy. Výslovně zvolte jejich ponechání.');
            RecurringExpenses::endForRemovedEvents($r, array_map(fn ($e) => $e->id, $state['eligible']));
            $before = EventSnapshot::many(collect($state['eligible']));
            $now = now();
            foreach (array_chunk(array_keys($before), 200) as $ids) {
                DB::table('vehicle_events')->whereIn('id', $ids)->update(['revision' => DB::raw('revision + 1'), 'deleted_at' => $now, 'updated_at' => $now]);
            }
            $audits = [];
            foreach ($before as $id => $snapshot) {
                $after = $snapshot;
                $after['event']['revision']++;
                $after['event']['deleted_at'] = $now->toISOString();
                $after['event']['updated_at'] = $now->toISOString();
                $audits[] = ['event_id' => $id, 'actor_id' => $r->user()->id, 'action' => 'import_revert', 'before_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => $now];
            }
            foreach (array_chunk($audits, 100) as $chunk) {
                DB::table('event_revisions')->insert($chunk);
            }
            $result = json_decode($b->result, true, 512, JSON_THROW_ON_ERROR);
            $result['reversal'] = ['removed_count' => count($state['eligible']), 'retained_event_ids' => array_column($state['conflicts'], 'id')];
            DB::table('import_batches')->where('id', $batch)->update(['status' => 'reverted', 'revision' => $b->revision + 1, 'revert_key' => $d['request_key'], 'revert_hash' => $hash, 'reverted_at' => now(), 'reverted_by' => $r->user()->id, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            self::audit($r, $batch, 'revert', $result['reversal']);
        });
    }
}
