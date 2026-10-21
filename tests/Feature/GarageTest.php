<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GarageTest extends TestCase
{
    use RefreshDatabase;

    private function createVehicle(): array
    {
        return $this->postJson('/api/v1/vehicles', [
            'name' => 'Rodinné auto', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => 1,
            'vin' => 'TEST123', 'acquired_on' => '2024-01-01', 'initial_odometer' => '12345.678',
        ])->assertCreated()->assertJsonPath('revision', 1)->json();
    }

    private function expense(): array
    {
        return ['request_key' => (string) Str::uuid(), 'kind' => 'expense', 'occurred_on' => '2026-10-21',
            'amount' => '123.4567', 'currency_code' => 'CZK', 'expense_type_id' => 1, 'repetition_months' => 0];
    }

    public function test_edit_revision_audit_and_distance_unit_preserve_history(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->createVehicle();
        $id = $v['id'];
        $v['name'] = 'Nové jméno';
        $v['distance_unit'] = 'mi';
        $v['currency_code'] = 'EUR';
        $v = $this->putJson("/api/v1/vehicles/$id", $v)->assertOk()->assertJsonPath('revision', 2)->json();
        $this->assertSame('12345.678', $v['initial_odometer']);
        $this->assertSame('2024-01-01', $v['acquired_on']);
        $stale = $v;
        $stale['revision'] = 1;
        $this->putJson("/api/v1/vehicles/$id", $stale)->assertConflict();
        $this->postJson("/api/v1/vehicles/$id/events", $this->expense())->assertCreated();
        $v['distance_unit'] = 'km';
        $this->putJson("/api/v1/vehicles/$id", $v)->assertUnprocessable()->assertJsonValidationErrors('distance_unit');
        $v['distance_unit'] = 'mi';
        $v['currency_code'] = 'USD';
        $this->putJson("/api/v1/vehicles/$id", $v)->assertOk();
        $this->assertDatabaseHas('vehicle_events', ['vehicle_id' => $id, 'currency_code' => 'CZK', 'amount' => '123.4567']);
        $this->assertDatabaseCount('vehicle_revisions', 3);
        $audit = json_decode(DB::table('vehicle_revisions')->where('action', 'update')->first()->before_snapshot, true);
        $this->assertSame('Rodinné auto', $audit['name']);
        $this->assertSame($id, $this->getJson('/api/v1/vehicles')->json('0.id'));
    }

    public function test_archive_restore_keeps_history_and_prevents_writes(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->createVehicle();
        $id = $v['id'];
        $d = $this->expense();
        $e = $this->postJson("/api/v1/vehicles/$id/events", $d)->assertCreated()->json('event');
        $this->patchJson("/api/v1/vehicles/$id/archive", ['revision' => 1, 'archived' => true])->assertOk()->assertJsonPath('revision', 2);
        $this->postJson("/api/v1/vehicles/$id/events", $this->expense())->assertConflict();
        $this->putJson("/api/v1/vehicles/$id/events/{$e['id']}", $d + ['revision' => 1])->assertConflict();
        $this->postJson("/api/v1/vehicles/$id/imports", [])->assertConflict();
        $this->getJson("/api/v1/vehicles/$id/events")->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/v1/vehicles/$id/summary")->assertJsonPath('0.total', '123.4567');
        $this->patchJson("/api/v1/vehicles/$id/archive", ['revision' => 1, 'archived' => false])->assertConflict();
        $this->patchJson("/api/v1/vehicles/$id/archive", ['revision' => 2, 'archived' => false])->assertOk()->assertJsonPath('archived_at', null);
        $this->postJson("/api/v1/vehicles/$id/events", $this->expense())->assertCreated();
        $this->assertDatabaseCount('vehicle_events', 2);
        $this->assertSame(['create', 'archive', 'restore'], DB::table('vehicle_revisions')->orderBy('id')->pluck('action')->all());
    }

    public function test_avatar_conversion_private_access_replacement_cleanup_and_removal(): void
    {
        Storage::fake('local');
        $a = User::factory()->create();
        $this->actingAs($a);
        $v = $this->createVehicle();
        $id = $v['id'];
        $file = UploadedFile::fake()->image('camera.png', 2000, 1000);
        file_put_contents($file->getPathname(), 'PRIVATE-METADATA-TRAILER', FILE_APPEND);
        $v = $this->postJson("/api/v1/vehicles/$id/avatar", ['avatar' => $file, 'revision' => 1])->assertOk()->assertJsonMissingPath('avatar_path')->json();
        $this->assertStringContainsString("/vehicles/$id/avatar?v=2", $v['avatar_url']);
        $path = Vehicle::findOrFail($id)->avatar_path;
        Storage::disk('local')->assertExists($path);
        $bytes = Storage::disk('local')->get($path);
        $this->assertStringNotContainsString('PRIVATE-METADATA-TRAILER', $bytes);
        $info = getimagesizefromstring($bytes);
        $this->assertSame([1280, 640, IMAGETYPE_WEBP], array_slice($info, 0, 3));
        $this->get($v['avatar_url'])->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->create())->getJson($v['avatar_url'])->assertNotFound();
        $this->actingAs($a);
        $this->postJson("/api/v1/vehicles/$id/avatar", ['avatar' => UploadedFile::fake()->image('other.jpg', 100, 100), 'revision' => 1])->assertConflict();
        $this->assertCount(1, Storage::disk('local')->allFiles('vehicle-avatars'));
        $v = $this->postJson("/api/v1/vehicles/$id/avatar", ['avatar' => UploadedFile::fake()->image('other.jpg', 100, 100), 'revision' => 2])->assertOk()->json();
        Storage::disk('local')->assertMissing($path);
        $this->assertCount(1, Storage::disk('local')->allFiles('vehicle-avatars'));
        $this->deleteJson("/api/v1/vehicles/$id/avatar", ['revision' => $v['revision']])->assertOk()->assertJsonPath('avatar_url', null);
        $this->assertCount(0, Storage::disk('local')->allFiles('vehicle-avatars'));
        $this->getJson("/api/v1/vehicles/$id/avatar")->assertNotFound();
        $this->assertSame(['create', 'avatar_upload', 'avatar_upload', 'avatar_delete'], DB::table('vehicle_revisions')->orderBy('id')->pluck('action')->all());
    }

    public function test_invalid_uploads_do_not_change_vehicle_or_write_files(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $id = $this->createVehicle()['id'];
        foreach ([
            UploadedFile::fake()->createWithContent('bad.jpg', '<script>bad</script>'),
            UploadedFile::fake()->createWithContent('car.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->image('tiny.png', 10, 10),
            UploadedFile::fake()->image('huge.png', 4097, 32),
            UploadedFile::fake()->image('big.png', 50, 50)->size(5121),
        ] as $file) {
            $this->postJson("/api/v1/vehicles/$id/avatar", ['avatar' => $file, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('avatar');
        }
        $this->getJson("/api/v1/vehicles/$id")->assertJsonPath('revision', 1)->assertJsonPath('avatar_url', null);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('vehicle_revisions', 1);
    }

    public function test_all_garage_routes_are_scoped_to_owner(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $v = $this->createVehicle();
        $id = $v['id'];
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/vehicles')->assertExactJson([]);
        $this->getJson("/api/v1/vehicles/$id")->assertNotFound();
        $this->putJson("/api/v1/vehicles/$id", $v)->assertNotFound();
        $this->patchJson("/api/v1/vehicles/$id/archive", ['revision' => 1, 'archived' => true])->assertNotFound();
        $this->getJson("/api/v1/vehicles/$id/avatar")->assertNotFound();
        $this->postJson("/api/v1/vehicles/$id/avatar", ['avatar' => UploadedFile::fake()->image('car.png'), 'revision' => 1])->assertNotFound();
        $this->deleteJson("/api/v1/vehicles/$id/avatar", ['revision' => 1])->assertNotFound();
        $this->assertDatabaseCount('vehicle_revisions', 1);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_cancelled_preview_still_prevents_reinterpreting_distance_unit(): void
    {
        $this->actingAs(User::factory()->create());
        $v = $this->createVehicle();
        $id = $v['id'];
        $batch = $this->postJson("/api/v1/vehicles/$id/imports", [
            'file' => UploadedFile::fake()->createWithContent('example.csv', "Date;Cost type;Total price;Currency;Repetition\n21.10.2026;1;100,00;CZK;0"),
            'kind' => 'expense', 'profile' => 'spritmonitor', 'decimal_separator' => ',', 'distance_unit' => 'km',
        ])->assertCreated()->json('id');
        $this->deleteJson("/api/v1/vehicles/$id/imports/$batch")->assertNoContent();
        $v['distance_unit'] = 'mi';
        $this->putJson("/api/v1/vehicles/$id", $v)->assertUnprocessable()->assertJsonValidationErrors('distance_unit');
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_jpeg_orientation_is_applied_and_webp_can_be_uploaded(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $id = $this->createVehicle()['id'];
        $file = UploadedFile::fake()->image('portrait.jpg', 160, 80);
        $jpeg = file_get_contents($file->getRealPath());
        // Little-endian TIFF IFD: orientation 6 means a 90-degree clockwise turn.
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
        file_put_contents($file->getRealPath(), substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
        $this->postJson("/api/v1/vehicles/$id/avatar", ['avatar' => $file, 'revision' => 1])->assertOk();
        $image = Storage::disk('local')->get(Vehicle::findOrFail($id)->avatar_path);
        $this->assertSame([80, 160], array_slice(getimagesizefromstring($image), 0, 2));
        $this->postJson("/api/v1/vehicles/$id/avatar", ['avatar' => UploadedFile::fake()->createWithContent('car.webp', $image), 'revision' => 2])->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles('vehicle-avatars'));
    }
}
