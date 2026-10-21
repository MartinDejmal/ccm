<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(): string
    {
        $this->actingAs(User::factory()->create());

        return $this->postJson('/api/v1/vehicles', ['name' => 'CSV test', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => 1])->assertCreated()->json('id');
    }

    private function upload(string $vehicle, string $csv, array $options = [])
    {
        $stream = DB::table('vehicle_energy_streams')->where('vehicle_id', $vehicle)->value('id');

        return $this->postJson("/api/v1/vehicles/$vehicle/imports", array_merge([
            'file' => UploadedFile::fake()->createWithContent('example.csv', $csv),
            'kind' => 'expense', 'profile' => 'spritmonitor', 'decimal_separator' => ',', 'distance_unit' => 'km', 'quantity_unit' => 'l', 'energy_stream_id' => $stream,
        ], $options));
    }

    private function expenses(): string
    {
        return <<<'CSV'
Date;Odometer;Cost type;Total price;Currency;Note;Repetition
16.10.2026;1000,00;Repair;1000,50;"CZK";"První řádek
Druhý řádek se systémem \"A\"";0
17.10.2026;;Insurance;-100,00;"CZK";"Vratka";12
CSV;
    }

    private function fuel(): string
    {
        return <<<'CSV'
Date;Odometer;Trip;Quantity;Total price;Currency;Type;Tires;Roads;Driving style;Fuel;Note;Consumption;BC-Consumption;BC-Quantity;BC-Speed;Company;Country;Area;Location;Latitude;Longitude
16.10.2026;1100,00;100,00;5,00;100,30;"EUR";1;1;10;2;1;"";5,00;0,00;0,00;0,00;"Stanice";"SLO";"";"Místo";50,058743;14,313211
15.10.2026;1000,00;;60,00;3000,00;"CZK";3;2;0;3;1;"";;0,00;0,00;0,00;"Stanice";"CZ";"";"";;
CSV;
    }

    public function test_preview_preserves_multiline_notes_negative_amounts_and_does_not_create_events(): void
    {
        $v = $this->vehicle();
        $b = $this->upload($v, $this->expenses())->assertCreated()->assertJsonPath('summary.total_rows', 2)->assertJsonPath('summary.amounts_by_currency.CZK', '900.5000')->json('id');
        $rows = $this->getJson("/api/v1/vehicles/$v/imports/$b/rows")->assertOk()->json('data');
        $this->assertSame("První řádek\nDruhý řádek se systémem \"A\"", $rows[0]['normalized']['event']['note']);
        $this->assertSame(4, $rows[1]['physical_line_start']);
        $this->assertSame(5, $rows[1]['normalized']['detail']['expense_type_id']);
        $this->assertSame(12, $rows[1]['normalized']['detail']['repetition_months']);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertDatabaseCount('event_revisions', 0);
        $this->assertSame($this->expenses(), DB::table('import_batches')->find($b)->raw_csv);
    }

    public function test_fueling_mask_country_alias_bc_zero_and_currencies(): void
    {
        $v = $this->vehicle();
        $batch = $this->upload($v, $this->fuel(), ['kind' => 'fueling'])->assertCreated()->assertJsonPath('summary.amounts_by_currency.CZK', '3000.0000')->assertJsonPath('summary.amounts_by_currency.EUR', '100.3000')->json('id');
        $row = $this->getJson("/api/v1/vehicles/$v/imports/$batch/rows")->json('data.0');
        $this->assertSame([2, 8], $row['normalized']['detail']['roads']);
        $this->assertSame('SI', $row['normalized']['detail']['country_code']);
        $this->assertNull($row['normalized']['detail']['bc_speed']);
        $this->assertSame('SLO', $row['source_values']['Country']);
        $this->assertSame('50.058743', $row['normalized']['detail']['latitude']);
        $ccm = $this->upload($v, $this->fuel(), ['kind' => 'fueling', 'profile' => 'ccm'])->assertCreated()->json('id');
        $this->getJson("/api/v1/vehicles/$v/imports/$ccm/rows")->assertJsonPath('data.0.normalized.detail.bc_speed', '0.0000');
    }

    public function test_bad_values_can_be_skipped_and_restored_without_hiding_issues(): void
    {
        $v = $this->vehicle();
        $csv = "Date;Cost type;Total price;Currency;Repetition\n31.02.2026;Not known;1,12345;XYZ;7";
        $b = $this->upload($v, $csv)->assertCreated()->assertJsonPath('status', 'needs_review')->assertJsonPath('summary.error_rows', 1)->json('id');
        $row = $this->getJson("/api/v1/vehicles/$v/imports/$b/rows?status=error")->json('data.0');
        $this->assertGreaterThanOrEqual(5, count($row['issues']));
        $this->patchJson("/api/v1/vehicles/$v/imports/$b/rows/{$row['id']}", ['skip' => true])->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('summary.skipped_rows', 1);
        $this->patchJson("/api/v1/vehicles/$v/imports/$b/rows/{$row['id']}", ['skip' => false])->assertOk()->assertJsonPath('status', 'needs_review');
        $this->deleteJson("/api/v1/vehicles/$v/imports/$b")->assertNoContent();
        $this->patchJson("/api/v1/vehicles/$v/imports/$b/rows/{$row['id']}", ['skip' => true])->assertConflict();
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_malformed_encoding_quoting_and_duplicate_headers_are_rejected_atomically(): void
    {
        $v = $this->vehicle();
        foreach (["Date;Cost type;Total price;Currency\n16.10.2026;Repair;1;CZK;\"unfinished", "Date;date;Cost type;Total price;Currency\n16.10.2026;16.10.2026;Repair;1;CZK", "\xFF\xFEinvalid"] as $csv) {
            $this->upload($v, $csv)->assertUnprocessable();
        }
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('import_rows', 0);
    }

    public function test_double_quote_profile_and_structural_row_errors(): void
    {
        $v = $this->vehicle();
        $csv = "Date;Cost type;Total price;Currency;Note\r\n16.10.2026;Repair;12,50;CZK;\"Systém \"\"A\"\"\"\r\n16.10.2026;Repair\r\n";
        $b = $this->upload($v, $csv, ['profile' => 'spritmonitor_rfc'])->assertCreated()->assertJsonPath('summary.error_rows', 1)->json('id');
        $this->getJson("/api/v1/vehicles/$v/imports/$b/rows")->assertJsonPath('data.0.normalized.event.note', 'Systém "A"')->assertJsonPath('data.1.issues.0.code', 'column_count');
    }

    public function test_preview_batch_and_rows_are_scoped_to_the_vehicle_owner(): void
    {
        $v = $this->vehicle();
        $b = $this->upload($v, $this->expenses())->json('id');
        $other = $this->vehicle();
        $this->getJson("/api/v1/vehicles/$v/imports/$b")->assertNotFound();
        $this->getJson("/api/v1/vehicles/$other/imports/$b/rows")->assertNotFound();
        $this->upload($v, $this->expenses())->assertNotFound();
    }

    public function test_units_duplicates_and_row_limit(): void
    {
        $v = $this->vehicle();
        $this->upload($v, $this->fuel(), ['kind' => 'fueling', 'quantity_unit' => 'kg'])->assertUnprocessable();
        $this->upload($v, $this->fuel())->assertUnprocessable();
        $this->upload($v, $this->expenses(), ['kind' => 'fueling'])->assertUnprocessable();
        $csv = "Date;Cost type;Total price;Currency\n16.10.2026;Repair;10,00;CZK\n16.10.2026;Repair;10,00;CZK";
        $b = $this->upload($v, $csv)->assertCreated()->assertJsonPath('summary.warning_rows', 1)->json('id');
        $this->getJson("/api/v1/vehicles/$v/imports/$b/rows")->assertJsonPath('data.1.issues.0.code', 'duplicate_in_file');
        $this->upload($v, "Date;Cost type;Total price;Currency\n".str_repeat("16.10.2026;Repair;10,00;CZK\n", 5001))->assertUnprocessable();
    }

    public function test_readiness_requires_the_new_import_schema_migration(): void
    {
        $this->getJson('/ready')->assertOk();
        DB::table('migrations')->where('migration', '2026_10_21_000001_create_import_previews')->delete();
        $this->getJson('/ready')->assertStatus(503);
    }

    public function test_provided_exports_when_available_outside_repository(): void
    {
        $root = getenv('CCM_EXAMPLE_FILES');
        if (! $root) {
            $this->markTestSkipped('Original private CSV examples are intentionally not committed.');
        }
        $files = glob($root.'/*/*.csv');
        $found = 0;
        $v = $this->vehicle();
        foreach ($files as $file) {
            if (str_ends_with($file, '1724509_fuelings.csv')) {
                $d = $this->upload($v, file_get_contents($file), ['kind' => 'fueling'])->assertCreated();
                $d->assertJsonPath('summary.total_rows', 17)->assertJsonPath('summary.error_rows', 0)->assertJsonPath('summary.amounts_by_currency.CZK', '35016.6000')->assertJsonPath('summary.amounts_by_currency.EUR', '236.4300');
                $found++;
            } elseif (str_ends_with($file, '1724509_costs (1).csv')) {
                $d = $this->upload($v, file_get_contents($file))->assertCreated();
                $d->assertJsonPath('summary.total_rows', 24)->assertJsonPath('summary.error_rows', 0)->assertJsonPath('summary.amounts_by_currency.CZK', '560093.9400');
                $found++;
            }
        }
        $this->assertSame(2, $found);
        $this->assertDatabaseCount('vehicle_events', 0);
    }
}
