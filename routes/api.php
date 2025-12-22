<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\TimezoneController;
use App\Services\CsvImportService;
use App\Services\ExportService;
use App\Services\ScheduleService;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware(['api', 'auth.jwt'])->group(function () {
    Route::get('/calendars', [CalendarController::class, 'index']);
    Route::post('/calendars', [CalendarController::class, 'store']);
    Route::get('/calendars/{id}', [CalendarController::class, 'show']);
    Route::put('/calendars/{id}', [CalendarController::class, 'update']);
    Route::delete('/calendars/{id}', [CalendarController::class, 'destroy']);

    // Events routes
    Route::prefix('/calendars/{calendarId}/events')->group(function () {
        Route::get('/', [EventController::class, 'index']);
        Route::post('/', [EventController::class, 'store']);
        Route::get('/{id}', [EventController::class, 'show']);
        Route::put('/{id}', [EventController::class, 'update']);
        Route::delete('/{id}', [EventController::class, 'destroy']);
    });

    // Tasks routes
    Route::prefix('/calendars/{calendarId}/tasks')->group(function () {
        Route::get('/', [TaskController::class, 'index']);
        Route::post('/', [TaskController::class, 'store']);
        Route::get('/{id}', [TaskController::class, 'show']);
        Route::put('/{id}', [TaskController::class, 'update']);
        Route::delete('/{id}', [TaskController::class, 'destroy']);
    });

    Route::get('/schedule', [ScheduleService::class, 'schedule']);
    Route::post('/CSVInput', [CsvImportController::class, 'readFromCSV']);
    Route::get('/export', [ExportService::class, 'exportEntries']);
});

// Authentication routes
Route::group(['prefix' => 'auth'], function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

Route::get('/timezones', [TimezoneController::class, 'getTimezones']);
Route::options('/api/CSVInput', function () {
    return response()->json(['status' => 'OK']);
});
