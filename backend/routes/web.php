<?php

use App\Http\Controllers\DocsController;
use App\Http\Middleware\RequireStripeSignature;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController;

// The backend serves the JSON API only; the interface lives in ../frontend.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
]));

// Stripe reports payments and cancellations here. Closed until a signing secret is configured.
Route::post(config('cashier.path', 'stripe').'/webhook', [WebhookController::class, 'handleWebhook'])
    ->middleware(RequireStripeSignature::class)->name('cashier.webhook');

// Documentation as Markdown for agents and scripts; /llms.txt is the index.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/llms.txt', [DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/{page}.md', [DocsController::class, 'show'])->where('page', '[a-z]+')->name('docs.show');
});
