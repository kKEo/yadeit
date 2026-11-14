<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EmailVerificationController extends Controller
{
    /**
     * Confirms an address from the emailed link. The link is signed, so it
     * works without being signed in (for example when opened on a phone).
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        if (! $request->hasValidRelativeSignature() || ! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new AccessDeniedHttpException('This verification link is invalid or has expired.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return response()->json(['message' => 'Your email address is confirmed.']);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'A new confirmation link has been sent.']);
    }
}
