<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(): string
    {
        $this->actingAs(User::factory()->create());

        return $this->postJson('/api/v1/vehicles', ['name' => 'Import test', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => 1])->assertCreated()->json('id');
    }

    private function upload(string $v, ?string $csv = null, string $kind = 'expense'): array
    {
        $stream = DB::table('vehicle_energy_streams')->where('vehicle_id', $v)->value('id');

        return $this->postJson("/api/v1/vehicles/$v/imports", [
            'file' => UploadedFile::fake()->createWithContent('test.csv', $csv ?? "Date;Cost type;Total price;Currency;Note;Repetition\n21.10.2026;1;100,1234;CZK;Servis;0\n22.10.2026;5;-10,0000;CZK;Vratka;12"),
            'kind' => $kind, 'profile' => 'spritmonitor', 'decimal_separator' => ',', 'distance_unit' => 'km', 'quantity_unit' => 'l', 'energy_stream_id' => $stream,
        ])->assertCreated()->json();
    }

    private function review(string $v, array $b): array
    {
        return $this->postJson("/api/v1/vehicles/$v/imports/{$b['id']}/review", ['revision' => $b['revision']])->assertOk()->json();
    }

    private function confirmation(array $b): array
    {
        return ['revision' => $b['revision'], 'review_token' => $b['review_token'], 'request_key' => (string) Str::uuid(), 'acknowledged' => true];
    }

    private function rows(string $v, array $b): array
    {
        return $this->getJson("/api/v1/vehicles/$v/imports/{$b['id']}/rows")->assertOk()->json('data');
    }

    public function test_confirm_is_exact_audited_idempotent_and_freezes_preview(): void
    {
        $v = $this->vehicle();
        $b = $this->review($v, $this->upload($v));
        $d = $this->confirmation($b);
        $url = "/api/v1/vehicles/$v/imports/{$b['id']}";
        $this->postJson("$url/confirm", $d)->assertOk()->assertJsonPath('status', 'imported')->assertJsonPath('result.imported_count', 2)->assertJsonPath('result.amounts_by_currency.CZK', '90.1234')->assertJsonMissingPath('confirm_hash');
        $this->postJson("$url/confirm", array_reverse($d, true))->assertOk();
        $this->assertDatabaseCount('vehicle_events', 2);
        $this->assertDatabaseCount('event_revisions', 2);
        $this->getJson("/api/v1/vehicles/$v/summary")->assertJsonPath('0.total', '90.1234');
        $rows = $this->rows($v, $b);
        $this->assertIsString($rows[0]['event_id']);
        $this->assertDatabaseHas('vehicle_events', ['id' => $rows[0]['event_id'], 'import_row_id' => $rows[0]['id'], 'import_batch_id' => $b['id']]);
        $this->assertDatabaseHas('expenses', ['event_id' => $rows[1]['event_id'], 'expense_type_id' => 5, 'repetition_months' => 12]);
        $this->deleteJson($url)->assertConflict();
        $this->patchJson("$url/rows/{$rows[0]['id']}", ['skip' => true])->assertConflict();
        $this->postJson("$url/confirm", [...$d, 'revision' => $d['revision'] + 1])->assertConflict();
        $this->assertDatabaseHas('import_actions', ['batch_id' => $b['id'], 'action' => 'confirm']);
    }

    public function test_errors_skips_and_empty_batch_block_confirmation(): void
    {
        $v = $this->vehicle();
        $b = $this->review($v, $this->upload($v, "Date;Cost type;Total price;Currency\n31.02.2026;1;100,00;CZK\n21.10.2026;1;10,00;CZK"));
        $url = "/api/v1/vehicles/$v/imports/{$b['id']}";
        $old = $this->confirmation($b);
        $this->postJson("$url/confirm", $old)->assertConflict();
        $rows = $this->rows($v, $b);
        $b = $this->patchJson("$url/rows/{$rows[0]['id']}", ['skip' => true, 'revision' => $b['revision']])->assertOk()->assertJsonPath('review_token', null)->json();
        $this->postJson("$url/confirm", $old)->assertConflict();
        $b = $this->review($v, $b);
        $this->postJson("$url/confirm", $this->confirmation($b))->assertOk()->assertJsonPath('result.imported_count', 1)->assertJsonPath('result.skipped_count', 1);
        $other = $this->upload($v, "Date;Cost type;Total price;Currency\n21.10.2026;1;11,00;CZK");
        $row = $this->rows($v, $other)[0];
        $other = $this->patchJson("/api/v1/vehicles/$v/imports/{$other['id']}/rows/{$row['id']}", ['skip' => true])->json();
        $other = $this->review($v, $other);
        $this->postJson("/api/v1/vehicles/$v/imports/{$other['id']}/confirm", $this->confirmation($other))->assertConflict();
        $this->assertDatabaseCount('vehicle_events', 1);
    }

    public function test_duplicate_candidates_can_be_kept_or_skipped_and_tokens_expire(): void
    {
        $v = $this->vehicle();
        $manual = ['request_key' => (string) Str::uuid(), 'kind' => 'expense', 'occurred_on' => '2026-10-21', 'amount' => '100.0000', 'currency_code' => 'CZK', 'expense_type_id' => 1, 'repetition_months' => 0, 'note' => 'Jiná poznámka', 'odometer' => '2000'];
        $e = $this->postJson("/api/v1/vehicles/$v/events", $manual)->assertCreated()->json('event');
        $b = $this->review($v, $this->upload($v, "Date;Cost type;Total price;Currency\n21.10.2026;1;100,00;CZK\n21.10.2026;1;100,00;CZK"));
        $url = "/api/v1/vehicles/$v/imports/{$b['id']}";
        $this->assertSame(2, $b['summary']['unresolved_duplicate_rows']);
        $rows = $this->rows($v, $b);
        $this->assertSame(1, $rows[1]['duplicates']['file_count']);
        $this->assertSame($e['id'], $rows[0]['duplicates']['history'][0]['id']);
        $this->postJson("$url/confirm", $this->confirmation($b))->assertConflict();
        $this->putJson("/api/v1/vehicles/$v/events/{$e['id']}", [...$manual, 'revision' => 1, 'note' => 'Upraveno'])->assertOk();
        $this->patchJson("$url/rows/{$rows[0]['id']}/duplicate", ['revision' => $b['revision'], 'duplicate_token' => $rows[0]['duplicates']['token'], 'keep' => true])->assertConflict();
        $b = $this->review($v, $b);
        $rows = $this->rows($v, $b);
        $b = $this->patchJson("$url/rows/{$rows[0]['id']}/duplicate", ['revision' => $b['revision'], 'duplicate_token' => $rows[0]['duplicates']['token'], 'keep' => true])->assertOk()->json();
        $b = $this->patchJson("$url/rows/{$rows[1]['id']}", ['skip' => true, 'revision' => $b['revision']])->assertOk()->json();
        $b = $this->review($v, $b);
        $this->assertSame(0, $b['summary']['unresolved_duplicate_rows']);
        $this->postJson("$url/confirm", $this->confirmation($b))->assertOk();
        $this->assertDatabaseCount('vehicle_events', 2);
    }

    public function test_two_reviewed_batches_are_rechecked_against_current_history(): void
    {
        $v = $this->vehicle();
        $a = $this->review($v, $this->upload($v));
        $b = $this->review($v, $this->upload($v));
        $key = $this->confirmation($a);
        $this->postJson("/api/v1/vehicles/$v/imports/{$a['id']}/confirm", $key)->assertOk();
        $url = "/api/v1/vehicles/$v/imports/{$b['id']}";
        $this->postJson("$url/confirm", $this->confirmation($b))->assertConflict();
        $this->postJson("$url/confirm", [...$this->confirmation($b), 'request_key' => $key['request_key']])->assertConflict();
        $b = $this->review($v, $b);
        $this->assertSame(2, $b['summary']['duplicate_rows']);
        foreach ($this->rows($v, $b) as $row) {
            $b = $this->patchJson("$url/rows/{$row['id']}/duplicate", ['revision' => $b['revision'], 'duplicate_token' => $row['duplicates']['token'], 'keep' => true])->assertOk()->json();
        }
        $this->postJson("$url/confirm", $this->confirmation($b))->assertOk();
        $this->assertDatabaseCount('vehicle_events', 4);
    }

    public function test_skipping_the_first_file_duplicate_removes_the_second_candidate(): void
    {
        $v = $this->vehicle();
        $b = $this->review($v, $this->upload($v, "Date;Cost type;Total price;Currency\n21.10.2026;1;100,00;CZK\n21.10.2026;1;100,00;CZK"));
        $this->assertSame(1, $b['summary']['unresolved_duplicate_rows']);
        $row = $this->rows($v, $b)[0];
        $b = $this->patchJson("/api/v1/vehicles/$v/imports/{$b['id']}/rows/{$row['id']}", ['skip' => true])->assertOk()->json();
        $b = $this->review($v, $b);
        $this->assertSame(0, $b['summary']['duplicate_rows']);
        $this->postJson("/api/v1/vehicles/$v/imports/{$b['id']}/confirm", $this->confirmation($b))->assertOk()->assertJsonPath('result.imported_count', 1);
    }

    public function test_reversal_is_audited_idempotent_and_keeps_raw_provenance(): void
    {
        $v = $this->vehicle();
        $b = $this->review($v, $this->upload($v));
        $confirm = $this->confirmation($b);
        $url = "/api/v1/vehicles/$v/imports/{$b['id']}";
        $this->postJson("$url/confirm", $confirm)->assertOk();
        $state = $this->getJson("$url/reversal")->assertOk()->json();
        $d = ['revision' => $state['revision'], 'review_token' => $state['review_token'], 'request_key' => (string) Str::uuid(), 'conflict_policy' => 'abort', 'acknowledged' => true];
        $this->postJson("$url/revert", $d)->assertOk()->assertJsonPath('status', 'reverted')->assertJsonPath('result.reversal.removed_count', 2);
        $this->postJson("$url/revert", $d)->assertOk();
        $this->postJson("$url/confirm", $confirm)->assertOk()->assertJsonPath('status', 'reverted');
        $this->assertSame(0, VehicleEvent::count());
        $this->assertSame([2, 2], VehicleEvent::withTrashed()->orderBy('id')->pluck('revision')->all());
        $this->assertDatabaseCount('event_revisions', 4);
        $this->assertDatabaseCount('import_rows', 2);
        $this->assertNotNull($this->rows($v, $b)[0]['event_deleted_at']);
        $this->getJson("/api/v1/vehicles/$v/summary")->assertExactJson([]);
        $this->assertDatabaseHas('import_actions', ['batch_id' => $b['id'], 'action' => 'revert']);
    }

    public function test_reversal_requires_current_conflicts_and_can_keep_edited_events(): void
    {
        $v = $this->vehicle();
        $b = $this->review($v, $this->upload($v));
        $url = "/api/v1/vehicles/$v/imports/{$b['id']}";
        $this->postJson("$url/confirm", $this->confirmation($b))->assertOk();
        $old = $this->getJson("$url/reversal")->json();
        $e = VehicleEvent::firstOrFail();
        $this->putJson("/api/v1/vehicles/$v/events/{$e->id}", ['revision' => 1, 'kind' => 'expense', 'occurred_on' => '2026-10-21', 'amount' => '101.0000', 'currency_code' => 'CZK', 'expense_type_id' => 1, 'repetition_months' => 0])->assertOk();
        $d = ['revision' => $old['revision'], 'review_token' => $old['review_token'], 'request_key' => (string) Str::uuid(), 'conflict_policy' => 'keep_changed', 'acknowledged' => true];
        $this->postJson("$url/revert", $d)->assertConflict();
        $state = $this->getJson("$url/reversal")->assertOk()->assertJsonPath('removable_count', 1)->json();
        $this->assertSame($e->id, $state['conflicts'][0]['id']);
        $d['review_token'] = $state['review_token'];
        $this->postJson("$url/revert", [...$d, 'conflict_policy' => 'abort'])->assertConflict();
        $this->assertSame(2, VehicleEvent::count());
        $this->postJson("$url/revert", $d)->assertOk()->assertJsonPath('result.reversal.removed_count', 1)->assertJsonPath('result.reversal.retained_event_ids.0', $e->id);
        $this->assertSame(1, VehicleEvent::count());
        $this->getJson("/api/v1/vehicles/$v/summary")->assertJsonPath('0.total', '101.0000');
    }

    public function test_failure_midway_rolls_back_entire_import_and_can_retry(): void
    {
        $v = $this->vehicle();
        $b = $this->review($v, $this->upload($v));
        $d = $this->confirmation($b);
        Log::spy();
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into `event_revisions`')) {
                $fail = false;
                throw new \RuntimeException('Simulated audit write failure');
            }
        });
        $this->postJson("/api/v1/vehicles/$v/imports/{$b['id']}/confirm", $d)->assertStatus(500);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('event_revisions', 0);
        $this->assertDatabaseHas('import_batches', ['id' => $b['id'], 'status' => 'ready', 'confirm_key' => null]);
        $this->postJson("/api/v1/vehicles/$v/imports/{$b['id']}/confirm", $d)->assertOk();
        $this->assertDatabaseCount('vehicle_events', 2);
        $state = $this->getJson("/api/v1/vehicles/$v/imports/{$b['id']}/reversal")->json();
        $reverse = ['revision' => $state['revision'], 'review_token' => $state['review_token'], 'request_key' => (string) Str::uuid(), 'conflict_policy' => 'abort', 'acknowledged' => true];
        $fail = true;
        $this->postJson("/api/v1/vehicles/$v/imports/{$b['id']}/revert", $reverse)->assertStatus(500);
        $this->assertSame(2, VehicleEvent::count());
        $this->assertDatabaseCount('event_revisions', 2);
        $this->assertDatabaseHas('import_batches', ['id' => $b['id'], 'status' => 'imported', 'revert_key' => null]);
        $this->postJson("/api/v1/vehicles/$v/imports/{$b['id']}/revert", $reverse)->assertOk();
        $this->assertSame(0, VehicleEvent::count());
    }

    public function test_archive_ownership_cancelled_and_corrupted_preview_guards(): void
    {
        $v = $this->vehicle();
        $owner = auth()->user();
        $b = $this->review($v, $this->upload($v));
        $url = "/api/v1/vehicles/$v/imports/{$b['id']}";
        $d = $this->confirmation($b);
        $this->actingAs(User::factory()->create());
        $this->postJson("$url/confirm", $d)->assertNotFound();
        $this->postJson("$url/review", ['revision' => $b['revision']])->assertNotFound();
        $this->getJson("$url/reversal")->assertNotFound();
        $this->postJson("$url/revert", $d)->assertNotFound();
        $this->actingAs($owner);
        $this->patchJson("/api/v1/vehicles/$v/archive", ['revision' => 1, 'archived' => true])->assertOk();
        $this->postJson("$url/confirm", $d)->assertConflict();
        $this->patchJson("/api/v1/vehicles/$v/archive", ['revision' => 2, 'archived' => false])->assertOk();
        DB::table('import_batches')->where('id', $b['id'])->update(['raw_csv' => 'tampered']);
        $this->postJson("$url/confirm", $d)->assertConflict();
        $this->deleteJson($url)->assertNoContent();
        $this->postJson("$url/review", ['revision' => $b['revision'] + 1])->assertConflict();
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_maximum_batch_commits_and_reverts_without_partial_history(): void
    {
        $v = $this->vehicle();
        $csv = "Date;Cost type;Total price;Currency\n";
        for ($i = 0; $i < 5000; $i++) {
            $csv .= (new \DateTimeImmutable('2000-01-01'))->modify("+$i days")->format('d.m.Y').";1;1,0000;CZK\n";
        }
        $b = $this->review($v, $this->upload($v, $csv));
        $url = "/api/v1/vehicles/$v/imports/{$b['id']}";
        $this->postJson("$url/confirm", $this->confirmation($b))->assertOk()->assertJsonPath('result.imported_count', 5000)->assertJsonPath('result.amounts_by_currency.CZK', '5000.0000');
        $this->assertSame(5000, VehicleEvent::count());
        $this->assertDatabaseCount('event_revisions', 5000);
        $state = $this->getJson("$url/reversal")->json();
        $this->postJson("$url/revert", ['revision' => $state['revision'], 'review_token' => $state['review_token'], 'request_key' => (string) Str::uuid(), 'conflict_policy' => 'abort', 'acknowledged' => true])->assertOk()->assertJsonPath('result.reversal.removed_count', 5000);
        $this->assertSame(0, VehicleEvent::count());
    }

    public function test_currency_case_and_fuel_stream_changes_are_checked(): void
    {
        $v = $this->vehicle();
        $this->postJson("/api/v1/vehicles/$v/events", ['request_key' => (string) Str::uuid(), 'kind' => 'expense', 'occurred_on' => '2026-10-21', 'amount' => '100.1234', 'currency_code' => 'czk', 'expense_type_id' => 1, 'repetition_months' => 0])->assertCreated();
        $b = $this->review($v, $this->upload($v));
        $this->assertSame(1, $b['summary']['unresolved_duplicate_rows']);
        $csv = "Date;Trip;Quantity;Total price;Currency;Type;Fuel\n21.10.2026;10000000000,000;10,00;100,00;CZK;1;1";
        $b = $this->upload($v, $csv, 'fueling');
        $this->assertSame(1, $b['summary']['error_rows']);
        $b = $this->upload($v, str_replace('10000000000,000', '100,000', $csv), 'fueling');
        $b = $this->review($v, $b);
        DB::table('vehicle_energy_streams')->where('vehicle_id', $v)->update(['is_active' => false]);
        $this->postJson("/api/v1/vehicles/$v/imports/{$b['id']}/confirm", $this->confirmation($b))->assertConflict();
        $this->assertDatabaseCount('fuelings', 0);
    }

    public function test_provided_exports_can_be_imported_with_all_details_when_available(): void
    {
        if (! $root = getenv('CCM_EXAMPLE_FILES')) {
            $this->markTestSkipped('Private examples are not committed.');
        }
        $v = $this->vehicle();
        $found = 0;
        foreach (glob($root.'/*/*.csv') as $file) {
            $kind = str_ends_with($file, '1724509_fuelings.csv') ? 'fueling' : (str_ends_with($file, '1724509_costs (1).csv') ? 'expense' : null);
            if (! $kind) {
                continue;
            }
            $b = $this->review($v, $this->upload($v, file_get_contents($file), $kind));
            foreach ($this->rows($v, $b) as $row) {
                if ($row['duplicates']['required'] ?? false) {
                    $b = $this->patchJson("/api/v1/vehicles/$v/imports/{$b['id']}/rows/{$row['id']}/duplicate", ['revision' => $b['revision'], 'duplicate_token' => $row['duplicates']['token'], 'keep' => true])->assertOk()->json();
                }
            }
            $this->postJson("/api/v1/vehicles/$v/imports/{$b['id']}/confirm", $this->confirmation($b))->assertOk();
            $found++;
        }
        $this->assertSame(2, $found);
        $this->assertDatabaseCount('vehicle_events', 41);
        $this->assertDatabaseCount('fuelings', 17);
        $this->assertDatabaseCount('expenses', 24);
        $summary = $this->getJson("/api/v1/vehicles/$v/summary")->json();
        $totals = [];
        foreach ($summary as $row) {
            $totals[$row['kind']][$row['currency_code']] = $row['total'];
        }
        $this->assertSame('35016.6000', $totals['fueling']['CZK']);
        $this->assertSame('236.4300', $totals['fueling']['EUR']);
        $this->assertSame('560093.9400', $totals['expense']['CZK']);
        $this->assertGreaterThan(0, DB::table('fueling_roads')->count());
        $this->assertDatabaseHas('fuelings', ['country_code' => 'SI']);
    }
}
