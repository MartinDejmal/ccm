<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Services\VehicleAvatar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class GarageController extends Controller
{
    private function vehicle(Request $r, string $id): Vehicle
    {
        return Vehicle::where('owner_id', $r->user()->id)->findOrFail($id);
    }

    public function show(Request $r, string $vehicle)
    {
        return $this->vehicle($r, $vehicle);
    }

    public static function audit(Vehicle $v, Request $r, string $action, ?array $before): void
    {
        DB::table('vehicle_revisions')->insert([
            'vehicle_id' => $v->id, 'actor_id' => $r->user()->id, 'action' => $action,
            'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_snapshot' => json_encode($v->toArray(), JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    private function locked(Request $r, string $id, int $revision): Vehicle
    {
        $v = Vehicle::where('owner_id', $r->user()->id)->lockForUpdate()->findOrFail($id);
        abort_if($v->revision !== $revision, 409, 'Vozidlo bylo mezitím změněno. Načtěte jeho aktuální údaje.');

        return $v;
    }

    public function update(Request $r, string $vehicle)
    {
        $this->vehicle($r, $vehicle);
        $d = $r->validate([
            'revision' => 'required|integer|min:1', 'name' => 'required|string|max:160',
            'make' => 'nullable|string|max:100', 'model' => 'nullable|string|max:100',
            'registration_plate' => 'nullable|string|max:32', 'vin' => 'nullable|string|max:32',
            'acquired_on' => 'nullable|date_format:Y-m-d',
            'initial_odometer' => 'nullable|decimal:0,3|min:0|max:99999999999.999',
            'distance_unit' => ['required', Rule::in(['km', 'mi'])], 'currency_code' => 'required|exists:currencies,code',
        ]);

        return DB::transaction(function () use ($r, $vehicle, $d) {
            $v = $this->locked($r, $vehicle, (int) $d['revision']);
            if ($d['distance_unit'] !== $v->distance_unit && (DB::table('vehicle_events')->where('vehicle_id', $v->id)->exists() || DB::table('import_batches')->where('vehicle_id', $v->id)->exists())) {
                throw ValidationException::withMessages(['distance_unit' => 'Jednotku nelze změnit u vozidla s historií nebo CSV náhledy. Převod údajů není podporován.']);
            }
            $before = $v->toArray();
            unset($d['revision']);
            $v->fill($d);
            $v->revision++;
            $v->save();
            self::audit($v, $r, 'update', $before);

            return $v;
        });
    }

    public function archive(Request $r, string $vehicle)
    {
        $this->vehicle($r, $vehicle);
        $d = $r->validate(['revision' => 'required|integer|min:1', 'archived' => 'required|boolean']);

        return DB::transaction(function () use ($r, $vehicle, $d) {
            $v = $this->locked($r, $vehicle, (int) $d['revision']);
            $before = $v->toArray();
            $v->archived_at = $d['archived'] ? now() : null;
            $v->revision++;
            $v->save();
            self::audit($v, $r, $d['archived'] ? 'archive' : 'restore', $before);

            return $v;
        });
    }

    public function avatar(Request $r, string $vehicle)
    {
        $v = $this->vehicle($r, $vehicle);
        abort_unless($v->avatar_path && Storage::disk('local')->exists($v->avatar_path), 404);

        return response()->file(Storage::disk('local')->path($v->avatar_path), [
            'Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ])->setPrivate();
    }

    public function uploadAvatar(Request $r, string $vehicle, VehicleAvatar $avatars)
    {
        $this->vehicle($r, $vehicle);
        $d = $r->validate([
            'revision' => 'required|integer|min:1',
            'avatar' => 'required|file|image|mimes:jpeg,png,webp|max:5120|dimensions:min_width=32,min_height=32,max_width=4096,max_height=4096',
        ]);
        $path = $avatars->store($d['avatar'], $vehicle);
        $old = null;
        try {
            $v = DB::transaction(function () use ($r, $vehicle, $d, $path, &$old) {
                $v = $this->locked($r, $vehicle, (int) $d['revision']);
                $before = $v->toArray();
                $old = $v->avatar_path;
                $v->avatar_path = $path;
                $v->revision++;
                $v->save();
                self::audit($v, $r, 'avatar_upload', $before);

                return $v;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $v;
    }

    public function deleteAvatar(Request $r, string $vehicle)
    {
        $this->vehicle($r, $vehicle);
        $d = $r->validate(['revision' => 'required|integer|min:1']);
        $old = null;
        $v = DB::transaction(function () use ($r, $vehicle, $d, &$old) {
            $v = $this->locked($r, $vehicle, (int) $d['revision']);
            $before = $v->toArray();
            $old = $v->avatar_path;
            $v->avatar_path = null;
            $v->revision++;
            $v->save();
            self::audit($v, $r, 'avatar_delete', $before);

            return $v;
        });
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $v;
    }
}
