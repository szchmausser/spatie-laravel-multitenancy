<?php

use App\Http\Controllers\Api\DeviceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Public and device-authenticated endpoints for the Android notification
| capture app.
|
*/

Route::post('/device/register', [DeviceController::class, 'register']);

// Health check endpoint for Android / external services
Route::get('/health', function () {
    return response()->json(['status' => 'ok'], 200);
});

Route::middleware('device.auth')->prefix('device')->group(function () {
    Route::get('/test', function () {
        return response()->json([
            'status' => 'ok',
            'message' => 'Device token validated successfully.',
        ]);
    });

    Route::post('/notifications', [DeviceController::class, 'storeNotification']);
    Route::post('/heartbeat', [DeviceController::class, 'heartbeat']);
});
