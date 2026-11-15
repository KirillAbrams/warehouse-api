<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    /**
     * Products
     */
    Route::apiResource('products', ProductController::class)
        ->only(['index', 'show']);

    /**
     * Reservations
     */
    Route::controller(ReservationController::class)->group(function (): void {
        Route::apiResource('reservations', ReservationController::class)
            ->only(['store', 'show']);

        Route::post('reservations/{reservation}/confirm', 'confirm')->name('reservations.confirm');
        Route::post('reservations/{reservation}/cancel', 'cancel')->name('reservations.cancel');
    });
});
