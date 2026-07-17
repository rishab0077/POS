<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PrintStationController;

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

Route::middleware(['throttle:print-station', 'print.service', 'print.station'])->prefix('print-station')->group(function () {
    Route::post('/heartbeat', [PrintStationController::class, 'heartbeat']);
    Route::get('/jobs', [PrintStationController::class, 'jobs']);
    Route::post('/jobs/{printJob}/printed', [PrintStationController::class, 'printed']);
    Route::post('/jobs/{printJob}/failed', [PrintStationController::class, 'failed']);
});
