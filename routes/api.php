<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\Auth\GoogleAuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\ScheduledTaskController;
use App\Http\Controllers\TimezoneController;
use App\Services\ExportService;
use App\Services\ScheduleService;
use Illuminate\Support\Facades\Route;

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

Route::middleware(['api', 'auth.jwt'])->group(static function (): void {
    Route::get('/calendars', [CalendarController::class, 'index']);
    Route::post('/calendars', [CalendarController::class, 'store']);
    Route::get('/calendars/{id}', [CalendarController::class, 'show']);
    Route::put('/calendars/{id}', [CalendarController::class, 'update']);
    Route::delete('/calendars/{id}', [CalendarController::class, 'destroy']);

    // Events routes
    Route::prefix('/calendars/{calendarId}/events')->group(static function (): void {
        Route::get('/', [EventController::class, 'index']);
        Route::post('/', [EventController::class, 'store']);
        Route::get('/{id}', [EventController::class, 'show']);
        Route::put('/{id}', [EventController::class, 'update']);
        Route::delete('/{id}', [EventController::class, 'destroy']);
    });

    // Tasks routes
    Route::prefix('/calendars/{calendarId}/tasks')->group(static function (): void {
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
Route::prefix('/calendars/{calendarId}/scheduled-tasks')->group(function () {
    Route::get('/', [ScheduledTaskController::class, 'getAll']);
    Route::get('/by-upload', [ScheduledTaskController::class, 'getByUploadId']);
    Route::get('/by-date-range', [ScheduledTaskController::class, 'getByDateRange']);
});

// Authentication routes
Route::group(['prefix' => 'auth'], static function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/google', [GoogleAuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);
});

Route::get('/timezones', [TimezoneController::class, 'getTimezones']);
Route::options('/api/CSVInput', static fn() => response()->json(['status' => 'OK']));
