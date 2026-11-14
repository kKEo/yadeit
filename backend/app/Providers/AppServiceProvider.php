<?php

namespace App\Providers;

use App\Ai\AiProvider;
use App\Ai\AnthropicProvider;
use App\Models\WorkItem;
use App\Support\CurrentWorkspace;
use App\Support\UnknownFields;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request or job, so a workspace never leaks between them.
        $this->app->scoped(CurrentWorkspace::class);

        // Tests replace this binding with a fake, so no request reaches a model provider.
        $this->app->bind(AiProvider::class, AnthropicProvider::class);
    }

    public function boot(): void
    {
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('public-feedback', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->currentAccessToken()?->id ?: $request->ip()));
        // Each access token has its own allowance, so one busy agent cannot starve another.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by(
            $request->bearerToken() !== null && $request->user()?->currentAccessToken()?->id
                ? 'token:'.$request->user()->currentAccessToken()->id
                : ($request->user()?->id ?: $request->ip())
        ));

        // Validation also refuses fields the endpoint does not know (see UnknownFields).
        Request::macro('validate', function (array $rules, ...$params) {
            /** @var Request $this */
            UnknownFields::reject($this, $rules);

            return validator($this->all(), $rules, ...$params)->validate();
        });

        // Work items are addressed by key (WEB-143) inside the current workspace.
        Route::bind('item', fn (string $key) => WorkItem::findByKey($key)
            ?? throw new NotFoundHttpException('Work item not found.'));

        $this->pointEmailLinksAtTheFrontend();

        // Shown in the generated OpenAPI file: scripts and agents send a bearer token.
        // The realtime channel sign-in is framework plumbing, not part of the documented API.
        Scramble::configure()->routes(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/v1')
            && ! str_contains($route->uri(), 'broadcasting/auth'));

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }

    /**
     * Emails link to the web app, which then calls the API. Verification
     * links carry a signature over the relative API path, so they stay valid
     * whatever host the API is reached through.
     */
    private function pointEmailLinksAtTheFrontend(): void
    {
        $frontend = fn (string $path) => rtrim((string) config('app.frontend_url'), '/').$path;

        ResetPassword::createUrlUsing(fn (object $user, string $token) => $frontend(
            '/reset-password?'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()])
        ));

        VerifyEmail::createUrlUsing(function (object $user) use ($frontend) {
            $signed = URL::temporarySignedRoute(
                'auth.verification.verify',
                now()->addMinutes(60),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
                absolute: false,
            );

            parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

            return $frontend('/verify-email?'.http_build_query([
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
                ...$query,
            ]));
        });
    }
}
