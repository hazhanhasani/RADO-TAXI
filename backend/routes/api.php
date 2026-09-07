<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/otp/request', fn () => response()->json(['status' => 'not_implemented'], 501));
    Route::post('/auth/otp/verify', fn () => response()->json(['status' => 'not_implemented'], 501));

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/fare/estimate', fn () => response()->json(['status' => 'not_implemented'], 501));
        Route::post('/trips', fn () => response()->json(['status' => 'not_implemented'], 501));
        Route::get('/trips/{trip}', fn () => response()->json(['status' => 'not_implemented'], 501));
        Route::post('/trips/{trip}/cancel', fn () => response()->json(['status' => 'not_implemented'], 501));

        Route::post('/driver/presence', fn () => response()->json(['status' => 'not_implemented'], 501));
        Route::post('/driver/location', fn () => response()->json(['status' => 'not_implemented'], 501));
        Route::post('/driver/offers/{offer}/accept', fn () => response()->json(['status' => 'not_implemented'], 501));
        Route::post('/driver/offers/{offer}/reject', fn () => response()->json(['status' => 'not_implemented'], 501));
        Route::post('/driver/trips/{trip}/arrived', fn () => response()->json(['status' => 'not_implemented'], 501));
        Route::post('/driver/trips/{trip}/start', fn () => response()->json(['status' => 'not_implemented'], 501));
        Route::post('/driver/trips/{trip}/complete', fn () => response()->json(['status' => 'not_implemented'], 501));
    });
});
