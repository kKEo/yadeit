<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Cookie-session sign-in for the web app. Scripts and agents do not sign in
 * here; they send a personal access token.
 */
class SessionController extends Controller
{
    public function store(Request $request): UserResource
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        if (! $request->hasSession()) {
            throw new BadRequestHttpException('Session sign-in is only available to the web app. Use a personal access token.');
        }

        $attempt = ['email' => strtolower($credentials['email']), 'password' => $credentials['password']];

        if (! Auth::guard('web')->attempt($attempt, (bool) ($credentials['remember'] ?? false))) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $request->session()->regenerate();

        return new UserResource(Auth::guard('web')->user());
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
