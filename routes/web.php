<?php

use App\Http\Controllers\CcmController;
use App\Http\Controllers\ConsumptionController;
use App\Http\Controllers\GarageController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\RecurringExpenseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::get('/ready', function () {
    try {
        DB::select('SELECT 1');
        if (DB::table('migrations')->whereIn('migration', ['2026_10_20_000002_seed_catalogs', '2026_10_21_000001_create_import_previews', '2026_10_21_000002_add_vehicle_garage', '2026_10_21_000003_complete_csv_imports', '2026_10_22_000001_add_consumption_history_break', '2026_10_22_000002_create_recurring_expense_plans'])->count() !== 6) {
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
        Route::get('/vehicles/{vehicle}/imports', [ImportController::class, 'index']);
        Route::post('/vehicles/{vehicle}/imports', [ImportController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/vehicles/{vehicle}/imports/{batch}', [ImportController::class, 'show']);
        Route::post('/vehicles/{vehicle}/imports/{batch}/review', [ImportController::class, 'review']);
        Route::patch('/vehicles/{vehicle}/imports/{batch}/rows/{row}/duplicate', [ImportController::class, 'decide']);
        Route::post('/vehicles/{vehicle}/imports/{batch}/confirm', [ImportController::class, 'confirm']);
        Route::get('/vehicles/{vehicle}/imports/{batch}/reversal', [ImportController::class, 'reversal']);
        Route::post('/vehicles/{vehicle}/imports/{batch}/revert', [ImportController::class, 'revert']);
        Route::get('/vehicles/{vehicle}/imports/{batch}/rows', [ImportController::class, 'rows']);
        Route::patch('/vehicles/{vehicle}/imports/{batch}/rows/{row}', [ImportController::class, 'skip']);
        Route::delete('/vehicles/{vehicle}/imports/{batch}', [ImportController::class, 'cancel']);
        Route::get('/catalogs', [CcmController::class, 'catalogs']);
        Route::get('/vehicles', [CcmController::class, 'vehicles']);
        Route::post('/vehicles', [CcmController::class, 'createVehicle']);
        Route::get('/vehicles/{vehicle}', [GarageController::class, 'show']);
        Route::put('/vehicles/{vehicle}', [GarageController::class, 'update']);
        Route::patch('/vehicles/{vehicle}/archive', [GarageController::class, 'archive']);
        Route::get('/vehicles/{vehicle}/avatar', [GarageController::class, 'avatar']);
        Route::post('/vehicles/{vehicle}/avatar', [GarageController::class, 'uploadAvatar'])->middleware('throttle:10,1');
        Route::delete('/vehicles/{vehicle}/avatar', [GarageController::class, 'deleteAvatar']);
        Route::get('/vehicles/{vehicle}/streams', [CcmController::class, 'streams']);
        Route::get('/vehicles/{vehicle}/plans', [RecurringExpenseController::class, 'index']);
        Route::post('/vehicles/{vehicle}/plans', [RecurringExpenseController::class, 'store']);
        Route::put('/vehicles/{vehicle}/plans/{plan}', [RecurringExpenseController::class, 'update']);
        Route::get('/vehicles/{vehicle}/consumption', [ConsumptionController::class, 'show']);
        Route::get('/vehicles/{vehicle}/summary', [CcmController::class, 'summary']);
        Route::get('/vehicles/{vehicle}/events', [CcmController::class, 'events']);
        Route::post('/vehicles/{vehicle}/events', [CcmController::class, 'createEvent']);
        Route::get('/vehicles/{vehicle}/events/{event}', [CcmController::class, 'detail']);
        Route::put('/vehicles/{vehicle}/events/{event}', [CcmController::class, 'updateEvent']);
    });
});
Route::get('/{path?}', fn () => view('app'))->where('path', '^(?!api/).*');
