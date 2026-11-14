<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| All routes here are served under /api/v1 (see bootstrap/app.php).
*/

Route::get('/', fn () => [
    'name' => config('app.name'),
    'api_version' => 'v1',
])->name('api.root');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', fn (Request $request) => ['data' => $request->user()])->name('api.me');
});
