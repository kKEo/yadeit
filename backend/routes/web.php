<?php

use App\Http\Controllers\DocsController;
use Illuminate\Support\Facades\Route;

// The backend serves the JSON API only; the interface lives in ../frontend.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
]));

// Documentation as Markdown for agents and scripts; /llms.txt is the index.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/llms.txt', [DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/{page}.md', [DocsController::class, 'show'])->where('page', '[a-z]+')->name('docs.show');
});
