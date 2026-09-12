<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    try {
        DB::connection()->getPdo();
        $database = DB::connection()->getDatabaseName();
    } catch (\Throwable $e) {
        $database = null;
    }

    return [
        'app' => config('app.name'),
        'database' => $database,
        'time' => now()->toDateTimeString(),
    ];
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
