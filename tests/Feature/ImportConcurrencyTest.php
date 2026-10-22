<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ImportConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function preview(string $v, string $date): array
    {
        $b = $this->postJson("/api/v1/vehicles/$v/imports", [
            'file' => UploadedFile::fake()->createWithContent('concurrent.csv', "Date;Cost type;Total price;Currency\n$date;1;1,00;CZK"),
            'kind' => 'expense', 'profile' => 'spritmonitor', 'decimal_separator' => ',', 'distance_unit' => 'km',
        ])->assertCreated()->json();

        return $this->postJson("/api/v1/vehicles/$v/imports/{$b['id']}/review", ['revision' => $b['revision']])->assertOk()->json();
    }

    private function concurrent(array $jobs): array
    {
        $directory = sys_get_temp_dir().'/ccm-concurrency-'.Str::uuid();
        mkdir($directory, 0700);
        $gate = $directory.'/go';
        $processes = [];
        try {
            foreach ($jobs as $i => $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/import-worker.php'), $gate, $directory.'/ready-'.$i], base_path(), ['APP_ENV' => 'testing']);
                $process->setInput(json_encode($job, JSON_THROW_ON_ERROR));
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) !== count($jobs) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(count($jobs), glob($directory.'/ready-*'), implode("\n", array_map(fn ($p) => $p->getErrorOutput(), $processes)));
            touch($gate);
            $statuses = [];
            foreach ($processes as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $statuses[] = (int) $p->getOutput();
            }
            sort($statuses);

            return $statuses;
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_concurrent_plan_payments_advance_once_and_rollback_competing_expense(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $v = $this->postJson('/api/v1/vehicles', ['name' => 'Concurrent plans', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => 1])->json('id');
        $expense = ['request_key' => (string) Str::uuid(), 'kind' => 'expense', 'occurred_on' => '2024-01-31', 'amount' => '100.0000', 'currency_code' => 'CZK', 'expense_type_id' => 5, 'repetition_months' => 1, 'create_plan' => true];
        $this->postJson("/api/v1/vehicles/$v/events", $expense)->assertCreated();
        $plan = $this->getJson("/api/v1/vehicles/$v/plans")->json('data.0');
        $job = ['action' => 'payment', 'user' => (string) $user->id, 'vehicle' => $v, 'data' => [...$expense, 'request_key' => (string) Str::uuid(), 'create_plan' => false, 'plan_id' => $plan['id'], 'plan_revision' => $plan['revision'], 'plan_due_on' => $plan['next_due_on']]];
        $this->assertSame([200, 201], $this->concurrent([$job, $job]));
        $this->assertDatabaseCount('vehicle_events', 2);
        $plan = $this->getJson("/api/v1/vehicles/$v/plans")->json('data.0');
        $job['data'] = [...$job['data'], 'request_key' => (string) Str::uuid(), 'plan_revision' => $plan['revision'], 'plan_due_on' => $plan['next_due_on']];
        $second = $job;
        $second['data']['request_key'] = (string) Str::uuid();
        $this->assertSame([201, 409], $this->concurrent([$job, $second]));
        $this->assertDatabaseCount('vehicle_events', 3);
        $this->assertDatabaseCount('recurring_expense_payments', 2);
        $this->assertDatabaseCount('recurring_expense_revisions', 3);
        $this->assertDatabaseHas('recurring_expense_plans', ['revision' => 3, 'next_due_on' => '2024-04-30']);
    }

    public function test_same_and_competing_batches_are_serialized_on_mariadb(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $v = $this->postJson('/api/v1/vehicles', ['name' => 'Concurrency test', 'distance_unit' => 'km', 'currency_code' => 'CZK', 'fuel_sort_id' => 1])->json('id');
        $a = $this->preview($v, '21.10.2026');
        $job = ['user' => (string) $user->id, 'vehicle' => $v, 'batch' => $a['id'], 'data' => ['revision' => $a['revision'], 'review_token' => $a['review_token'], 'request_key' => (string) Str::uuid(), 'acknowledged' => true]];
        $this->assertSame([200, 200], $this->concurrent([$job, $job]));
        $this->assertDatabaseCount('vehicle_events', 1);
        $a = $this->preview($v, '22.10.2026');
        $b = $this->preview($v, '22.10.2026');
        $jobs = [];
        foreach ([$a, $b] as $batch) {
            $jobs[] = ['user' => (string) $user->id, 'vehicle' => $v, 'batch' => $batch['id'], 'data' => ['revision' => $batch['revision'], 'review_token' => $batch['review_token'], 'request_key' => (string) Str::uuid(), 'acknowledged' => true]];
        }
        $this->assertSame([200, 409], $this->concurrent($jobs));
        $this->assertDatabaseCount('vehicle_events', 2);
        $this->assertSame(2, DB::table('import_batches')->where('status', 'imported')->count());
        $this->assertDatabaseCount('event_revisions', 2);
    }
}
