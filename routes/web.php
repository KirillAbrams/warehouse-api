<?php

/**
 * Короткая открытая страница с названием службы. Нужна для простой проверки, что приложение отвечает.
 */

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['service' => 'Warehouse reservations API', 'version' => 'v1']);
});
