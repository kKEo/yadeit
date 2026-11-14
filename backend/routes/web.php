<?php

use Illuminate\Support\Facades\Route;

// The backend serves the JSON API only; the interface lives in ../frontend.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
]));
