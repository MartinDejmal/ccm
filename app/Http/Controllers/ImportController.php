<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Services\Csv\ImportPreview;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    private function vehicle(Request $r, string $vehicle): Vehicle
    {
        return Vehicle::where('owner_id', $r->user()->id)->findOrFail($vehicle);
    }

    private function batch(Request $r, string $vehicle, string $batch): object
    {
        $v = $this->vehicle($r, $vehicle);

        return DB::table('import_batches')->where('vehicle_id', $v->id)->where('id', $batch)->firstOrFail();
    }

    private function json(object $batch): array
    {
        $data = (array) $batch;
        unset($data['raw_csv']);
        foreach (['id', 'vehicle_id', 'created_by'] as $key) {
            $data[$key] = (string) $data[$key];
        }
        foreach (['parameters', 'summary'] as $key) {
            $data[$key] = json_decode($data[$key], true, 512, JSON_THROW_ON_ERROR);
        }

        return $data;
    }

    public function index(Request $r, string $vehicle)
    {
        $v = $this->vehicle($r, $vehicle);

        return DB::table('import_batches')->where('vehicle_id', $v->id)->select(['id', 'vehicle_id', 'created_by', 'kind', 'profile', 'profile_version', 'original_filename', 'file_sha256', 'parameters', 'summary', 'status', 'created_at', 'updated_at'])->orderByDesc('id')->paginate(10)->through(fn ($batch) => $this->json($batch));
    }

    public function show(Request $r, string $vehicle, string $batch)
    {
        return $this->json($this->batch($r, $vehicle, $batch));
    }

    public function rows(Request $r, string $vehicle, string $batch)
    {
        $this->batch($r, $vehicle, $batch);
        $r->validate(['status' => ['nullable', Rule::in(['valid', 'warning', 'error', 'skipped'])]]);
        $q = DB::table('import_rows')->where('batch_id', $batch);
        if ($r->filled('status')) {
            $q->where('status', $r->string('status')->toString());
        }

        return $q->orderBy('record_number')->paginate(25)->through(function ($row) {
            $data = (array) $row;
            $data['id'] = (string) $row->id;
            $data['batch_id'] = (string) $row->batch_id;
            foreach (['source_values', 'normalized', 'issues'] as $key) {
                $data[$key] = json_decode($data[$key], true, 512, JSON_THROW_ON_ERROR);
            }

            return $data;
        });
    }

    public function store(Request $r, string $vehicle, ImportPreview $parser)
    {
        $v = $this->vehicle($r, $vehicle);
        abort_if($v->archived_at, 409, 'Vozidlo je archivované.');
        $d = $r->validate([
            'file' => 'required|file|extensions:csv|max:2048',
            'kind' => ['required', Rule::in(['fueling', 'expense'])],
            'profile' => ['required', Rule::in(['spritmonitor', 'spritmonitor_rfc', 'ccm'])],
            'distance_unit' => ['required', Rule::in(['km', 'mi'])],
            'quantity_unit' => ['nullable', Rule::in(['l', 'kg', 'kWh'])],
            'energy_stream_id' => 'nullable|integer',
            'decimal_separator' => ['required', Rule::in([',', '.'])],
        ]);
        if ($d['distance_unit'] !== $v->distance_unit) {
            throw ValidationException::withMessages(['distance_unit' => 'Jednotka souboru musí odpovídat vozidlu; převody jednotek zatím nejsou podporovány.']);
        }
        $stream = null;
        if ($d['kind'] === 'fueling') {
            $stream = DB::table('vehicle_energy_streams')->where('vehicle_id', $v->id)->where('is_active', true)->find($d['energy_stream_id'] ?? 0);
            if (! $stream || $stream->quantity_unit !== ($d['quantity_unit'] ?? null)) {
                throw ValidationException::withMessages(['energy_stream_id' => 'Vyberte platnou nádrž/médium a její jednotku množství.']);
            }
        }
        $file = $d['file'];
        unset($d['file']);
        $csv = file_get_contents($file->getRealPath());
        $result = $parser->parse($csv, $d, $stream);
        $hash = hash('sha256', $csv);
        $previous = DB::table('import_batches')->where('vehicle_id', $v->id)->where('kind', $d['kind'])->where('file_sha256', $hash)->where('status', '!=', 'cancelled')->value('id');
        if ($previous) {
            $result['warnings'][] = 'Stejný soubor již má náhled v dávce '.$previous.'. Nejde o potvrzení zápisu do historie.';
        }

        return DB::transaction(function () use ($r, $v, $d, $file, $csv, $hash, $result) {
            $now = now();
            $id = DB::table('import_batches')->insertGetId([
                'vehicle_id' => $v->id, 'created_by' => $r->user()->id, 'kind' => $d['kind'], 'profile' => $d['profile'], 'profile_version' => '1',
                'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'file_sha256' => $hash, 'raw_csv' => $csv,
                'parameters' => json_encode($d, JSON_THROW_ON_ERROR), 'summary' => json_encode(['warnings' => $result['warnings']], JSON_THROW_ON_ERROR), 'status' => 'validating', 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (array_chunk($result['rows'], 200) as $chunk) {
                DB::table('import_rows')->insert(array_map(function ($row) use ($id) {
                    $row['batch_id'] = $id;
                    foreach (['source_values', 'normalized', 'issues'] as $key) {
                        $row[$key] = json_encode($row[$key], JSON_THROW_ON_ERROR);
                    }

                    return $row;
                }, $chunk));
            }
            $this->summarize($id);

            return response()->json($this->json(DB::table('import_batches')->find($id)), 201);
        });
    }

    public function skip(Request $r, string $vehicle, string $batch, string $row)
    {
        $this->batch($r, $vehicle, $batch);
        $d = $r->validate(['skip' => 'required|boolean']);

        return DB::transaction(function () use ($batch, $row, $d) {
            $b = DB::table('import_batches')->where('id', $batch)->lockForUpdate()->firstOrFail();
            abort_if($b->status === 'cancelled', 409, 'Náhled byl zrušen.');
            $record = DB::table('import_rows')->where('batch_id', $batch)->where('id', $row)->firstOrFail();
            $issues = json_decode($record->issues, true, 512, JSON_THROW_ON_ERROR);
            $status = $d['skip'] ? 'skipped' : (in_array('error', array_column($issues, 'severity'), true) ? 'error' : ($issues ? 'warning' : 'valid'));
            DB::table('import_rows')->where('id', $record->id)->update(['status' => $status]);
            $this->summarize((int) $batch);

            return $this->json(DB::table('import_batches')->find($batch));
        });
    }

    public function cancel(Request $r, string $vehicle, string $batch)
    {
        $this->batch($r, $vehicle, $batch);

        return DB::transaction(function () use ($batch) {
            DB::table('import_batches')->where('id', $batch)->lockForUpdate()->firstOrFail();
            DB::table('import_batches')->where('id', $batch)->update(['status' => 'cancelled', 'updated_at' => now()]);

            return response()->noContent();
        });
    }

    private function summarize(int $id): void
    {
        $batch = DB::table('import_batches')->find($id);
        $summary = json_decode($batch->summary, true, 512, JSON_THROW_ON_ERROR);
        $summary = array_merge(['warnings' => $summary['warnings'] ?? []], ['total_rows' => 0, 'valid_rows' => 0, 'warning_rows' => 0, 'error_rows' => 0, 'skipped_rows' => 0, 'amounts_by_currency' => []]);
        foreach (DB::table('import_rows')->where('batch_id', $id)->orderBy('id')->cursor() as $row) {
            $summary['total_rows']++;
            $summary[$row->status.'_rows']++;
            if (in_array($row->status, ['valid', 'warning'], true)) {
                $event = json_decode($row->normalized, true, 512, JSON_THROW_ON_ERROR)['event'];
                $currency = $event['currency_code'];
                $summary['amounts_by_currency'][$currency] = (string) BigDecimal::of($summary['amounts_by_currency'][$currency] ?? '0')->plus($event['amount'])->toScale(4);
            }
        }
        DB::table('import_batches')->where('id', $id)->update(['summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'status' => $summary['error_rows'] ? 'needs_review' : 'ready', 'updated_at' => now()]);
    }
}
