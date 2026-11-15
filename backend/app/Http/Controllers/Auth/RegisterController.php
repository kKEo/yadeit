<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Simulator\Simulator;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'locale' => ['sometimes', Rule::in(User::LOCALES)],
        ]);

        $user = new User($data);

        // Addresses of the Traffic lab belong to its runs: only a request signed by a run may take one.
        if (Simulator::isSimEmail($data['email'])) {
            $sim = Simulator::context($request) ?? throw ValidationException::withMessages(['email' => ['This address cannot be used.']]);
            $user->sim_run_id = $sim->runId;
        }

        $user->save();

        // Sends the verification email.
        event(new Registered($user));

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
