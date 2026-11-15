<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['service' => 'Warehouse reservations API', 'version' => 'v1']);
});
