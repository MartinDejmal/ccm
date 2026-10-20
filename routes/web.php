<?php

use App\Http\Controllers\CcmController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::get('/ready', function () {
    try {
        DB::select('SELECT 1');
        if (! DB::table('migrations')->where('migration', '2026_10_20_000002_seed_catalogs')->exists()) {
            return response()->json(['ready' => false], 503);
        }

        return ['ready' => true];
    } catch (Throwable) {
        return response()->json(['ready' => false], 503);
    }
});
Route::prefix('api/v1')->group(function () {
    Route::post('/login', function (Request $r) {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($d)) {
            throw ValidationException::withMessages(['email' => 'Nesprávné přihlašovací údaje.']);
        }
        $r->session()->regenerate();

        return ['user' => $r->user()];
    })->middleware('throttle:5,1');
    Route::middleware('auth')->group(function () {
        Route::get('/me', fn (Request $r) => $r->user());
        Route::post('/logout', function (Request $r) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return response()->noContent();
        });
        Route::get('/catalogs', [CcmController::class, 'catalogs']);
        Route::get('/vehicles', [CcmController::class, 'vehicles']);
        Route::post('/vehicles', [CcmController::class, 'createVehicle']);
        Route::get('/vehicles/{vehicle}/streams', [CcmController::class, 'streams']);
        Route::get('/vehicles/{vehicle}/summary', [CcmController::class, 'summary']);
        Route::get('/vehicles/{vehicle}/events', [CcmController::class, 'events']);
        Route::post('/vehicles/{vehicle}/events', [CcmController::class, 'createEvent']);
        Route::get('/vehicles/{vehicle}/events/{event}', [CcmController::class, 'detail']);
        Route::put('/vehicles/{vehicle}/events/{event}', [CcmController::class, 'updateEvent']);
    });
});
Route::get('/{path?}', fn () => view('app'))->where('path', '^(?!api/).*');
