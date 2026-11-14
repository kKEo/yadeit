<?php

namespace App\Providers;

use App\Ai\AiProvider;
use App\Ai\AnthropicProvider;
use App\Http\Controllers\OAuthConsentController;
use App\Models\PlanSubscription;
use App\Models\PlanSubscriptionItem;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\UnknownFields;
use Carbon\CarbonInterval;
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
use Laravel\Cashier\Cashier;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request or job, so a workspace never leaks between them.
        $this->app->scoped(CurrentWorkspace::class);

        // Tests replace this binding with a fake, so no request reaches a model provider.
        $this->app->bind(AiProvider::class, AnthropicProvider::class);

        // The Stripe webhook is registered in routes/web.php, behind a check that a signing secret is set.
        Cashier::ignoreRoutes();

        // Apps connect with the authorization code flow only.
        Passport::$deviceCodeGrantEnabled = false;
    }

    public function boot(): void
    {
        // The workspace pays, not the person.
        Cashier::useCustomerModel(Workspace::class);
        Cashier::useSubscriptionModel(PlanSubscription::class);
        Cashier::useSubscriptionItemModel(PlanSubscriptionItem::class);

        // Per caller, and per account whoever calls: guessing one password from many addresses is slowed down too.
        RateLimiter::for('auth', fn (Request $request) => array_filter([
            Limit::perMinute(10)->by($request->ip()),
            is_string($request->input('email')) ? Limit::perMinute(20)->by('email:'.sha1(mb_strtolower($request->input('email')))) : null,
        ]));
        // The consent screen of an app connecting over OAuth: the person picks which of their agents it works as.
        Passport::authorizationView(function (array $parameters) {
            $user = $parameters['user'];
            $agents = OAuthConsentController::agentsOf($user)->get();
            $polish = $user->locale === 'pl';

            return response()->view('oauth.authorize', [
                ...$parameters,
                'agents' => $agents,
                'workspaces' => Workspace::query()->whereIn('id', $agents->pluck('workspace_id'))->pluck('name', 'id'),
                'redirectHost' => (string) (parse_url((string) $parameters['request']->query('redirect_uri'), PHP_URL_HOST) ?: $parameters['request']->query('redirect_uri')),
                'appUrl' => rtrim((string) config('app.frontend_url'), '/'),
                'locale' => $polish ? 'pl' : 'en',
                't' => $polish ? [
                    'title' => 'Połącz aplikację', 'asks' => 'prosi o dostęp do Yadeit przez MCP.', 'returns' => 'Po zatwierdzeniu wrócisz do:',
                    'choose' => 'Jako który agent ma działać?', 'means' => 'Aplikacja będzie działać jako wybrany agent: zobaczy i zmieni to, co on może, a zakończenie zadania zawsze zatwierdza człowiek. Połączenie odłączysz w każdej chwili na stronie Agenci.',
                    'none' => 'Nie masz jeszcze agenta. Utwórz go w aplikacji (Agenci), a potem połącz ponownie.', 'open' => 'Otwórz Yadeit',
                    'allow' => 'Połącz', 'deny' => 'Odmów', 'signedIn' => 'Zalogowano jako',
                ] : [
                    'title' => 'Connect an app', 'asks' => 'asks for access to Yadeit over MCP.', 'returns' => 'After you allow it, you return to:',
                    'choose' => 'Which agent should it work as?', 'means' => 'The app will work as the agent you choose: it sees and changes what that agent can, and a person always approves "done". You can disconnect it at any time on the Agents page.',
                    'none' => 'You have no agent yet. Create one in the app (Agents), then connect again.', 'open' => 'Open Yadeit',
                    'allow' => 'Connect', 'deny' => 'Deny', 'signedIn' => 'Signed in as',
                ],
            ]);
        });
        // An app's access lasts a day and renews itself; unused, a connection ends after a month.
        Passport::tokensExpireIn(CarbonInterval::day());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));

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
