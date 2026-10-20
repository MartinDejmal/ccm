<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $t) {
            $t->char('code', 3)->primary();
            $t->string('name');
        });
        foreach (['fueling_types', 'tire_types', 'driving_styles', 'road_types', 'repetition_intervals'] as $name) {
            Schema::create($name, function (Blueprint $t) {
                $t->unsignedSmallInteger('id')->primary();
                $t->string('name');
            });
        }
        Schema::create('fuel_sorts', function (Blueprint $t) {
            $t->unsignedSmallInteger('id')->primary();
            $t->string('name');
            $t->string('medium_group', 20);
            $t->string('quantity_unit', 10);
        });
        Schema::create('expense_types', function (Blueprint $t) {
            $t->unsignedSmallInteger('id')->primary();
            $t->string('name');
            $t->string('label');
            $t->string('report_group', 20);
        });
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->string('name', 160);
            $t->string('make', 100)->nullable();
            $t->string('model', 100)->nullable();
            $t->string('vin', 32)->nullable();
            $t->string('registration_plate', 32)->nullable();
            $t->string('distance_unit', 10)->default('km');
            $t->char('currency_code', 3);
            $t->foreign('currency_code')->references('code')->on('currencies');
            $t->decimal('initial_odometer', 14, 3)->nullable();
            $t->date('acquired_on')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['owner_id', 'archived_at']);
        });
        Schema::create('vehicle_energy_streams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->string('medium_group', 20);
            $t->string('quantity_unit', 10);
            $t->string('consumption_method', 24)->default('full_to_full');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('vehicle_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->char('request_hash', 64);
            $t->string('kind', 10);
            $t->date('occurred_on');
            $t->unsignedInteger('day_sequence')->default(0);
            $t->decimal('odometer', 14, 3)->nullable();
            $t->decimal('amount', 18, 4);
            $t->char('currency_code', 3);
            $t->foreign('currency_code')->references('code')->on('currencies');
            $t->text('note')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['vehicle_id', 'occurred_on', 'id']);
            $t->index(['vehicle_id', 'currency_code', 'occurred_on']);
        });
        Schema::create('fuelings', function (Blueprint $t) {
            $t->unsignedBigInteger('event_id')->primary();
            $t->foreign('event_id')->references('id')->on('vehicle_events')->restrictOnDelete();
            $t->foreignId('energy_stream_id')->constrained('vehicle_energy_streams')->restrictOnDelete();
            foreach (['fuel_sort_id' => 'fuel_sorts', 'fueling_type_id' => 'fueling_types'] as $col => $table) {
                $t->unsignedSmallInteger($col);
                $t->foreign($col)->references('id')->on($table);
            }
            $t->decimal('quantity', 14, 4);
            $t->string('quantity_unit', 10);
            foreach (['reported_trip', 'reported_consumption', 'bc_consumption', 'bc_quantity', 'bc_speed'] as $col) {
                $t->decimal($col, 14, 4)->nullable();
            }
            $t->string('consumption_distance_unit', 10)->default('km');
            $t->string('speed_unit', 10)->default('km/h');
            foreach (['tire_type_id' => 'tire_types', 'driving_style_id' => 'driving_styles'] as $col => $table) {
                $t->unsignedSmallInteger($col)->nullable();
                $t->foreign($col)->references('id')->on($table);
            }
            foreach (['company', 'country_code', 'area', 'location'] as $col) {
                $t->string($col)->nullable();
            }$t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 10, 6)->nullable();
        });
        Schema::create('fueling_roads', function (Blueprint $t) {
            $t->unsignedBigInteger('event_id');
            $t->foreign('event_id')->references('event_id')->on('fuelings');
            $t->unsignedSmallInteger('road_type_id');
            $t->foreign('road_type_id')->references('id')->on('road_types');
            $t->primary(['event_id', 'road_type_id']);
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->unsignedBigInteger('event_id')->primary();
            $t->foreign('event_id')->references('id')->on('vehicle_events');
            $t->unsignedSmallInteger('expense_type_id');
            $t->foreign('expense_type_id')->references('id')->on('expense_types');
            $t->decimal('reported_trip', 14, 3)->nullable();
            $t->unsignedSmallInteger('repetition_months')->default(0);
            $t->foreign('repetition_months')->references('id')->on('repetition_intervals');
        });
        Schema::create('event_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained('vehicle_events');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action', 20);
            $t->longText('before_snapshot')->nullable();
            $t->longText('after_snapshot');
            $t->timestamp('created_at');
        });
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_values CHECK (distance_unit IN ('km','mi') AND (initial_odometer IS NULL OR initial_odometer >= 0))");
            DB::statement("ALTER TABLE vehicle_events ADD CONSTRAINT events_values CHECK (kind IN ('fueling','expense') AND (odometer IS NULL OR odometer >= 0))");
            DB::statement("ALTER TABLE fuelings ADD CONSTRAINT fuelings_values CHECK (quantity > 0 AND quantity_unit IN ('l','kg','kWh') AND (reported_trip IS NULL OR reported_trip >= 0) AND ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)))");
        }
    }

    public function down(): void
    {
        foreach (['event_revisions', 'expenses', 'fueling_roads', 'fuelings', 'vehicle_events', 'vehicle_energy_streams', 'vehicles', 'expense_types', 'fuel_sorts', 'repetition_intervals', 'road_types', 'driving_styles', 'tire_types', 'fueling_types', 'currencies'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
