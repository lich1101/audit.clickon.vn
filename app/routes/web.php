<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'Clickon Audit API',
        'status' => 'ok',
    ]);
});
