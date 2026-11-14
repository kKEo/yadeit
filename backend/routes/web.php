<?php

use App\Http\Controllers\DocsController;
use App\Http\Controllers\OAuthConsentController;
use App\Http\Middleware\RequireStripeSignature;
use App\Support\Health;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController;

// The backend serves the JSON API only; the interface lives in ../frontend.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
]));

// For uptime monitors: 200 while nothing is failing, 503 otherwise. Needs the HEALTH_TOKEN as a bearer token.
Route::get('/health', function (Request $request) {
    $token = (string) config('health.token');
    abort_if($token === '', 404);
    abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

    $report = Health::report();

    return response()->json($report, $report['status'] === 'failing' ? 503 : 200);
})->withoutMiddleware('web')->middleware('throttle:60,1')->name('health');

// The consent screen's "Connect" button: remembers the chosen agent, then lets Passport finish.
Route::post('/oauth/connect', [OAuthConsentController::class, 'approve'])->middleware(['auth', 'throttle:30,1'])->name('oauth.connect');

// Stripe reports payments and cancellations here. Closed until a signing secret is configured.
Route::post(config('cashier.path', 'stripe').'/webhook', [WebhookController::class, 'handleWebhook'])
    ->middleware(RequireStripeSignature::class)->name('cashier.webhook');

// Documentation as Markdown for agents and scripts; /llms.txt is the index.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/llms.txt', [DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/{page}.md', [DocsController::class, 'show'])->where('page', '[a-z]+')->name('docs.show');
});
