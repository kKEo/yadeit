<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

function verificationApiPath(string $frontendUrl): string
{
    parse_str((string) parse_url($frontendUrl, PHP_URL_QUERY), $q);

    return "/api/v1/auth/verify-email/{$q['id']}/{$q['hash']}?expires={$q['expires']}&signature={$q['signature']}";
}

it('registers a user, signs them in and sends a confirmation email', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonMissingPath('data.password');

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect(Hash::check('correct horse battery', $user->password))->toBeTrue();

    Notification::assertSentTo($user, VerifyEmail::class);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
});

it('refuses a duplicate email and a weak or unconfirmed password', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'X', 'email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'nope',
    ])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['email', 'password']]]]);
});

it('confirms the email address through the emailed link', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    $link = null;
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user, &$link) {
        $link = $n->toMail($user)->actionUrl;

        return true;
    });

    expect($link)->toStartWith('http://localhost:5173/verify-email?');

    $this->getJson(verificationApiPath($link))->assertOk();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects a tampered confirmation link', function () {
    $user = User::factory()->unverified()->create();
    $hash = sha1($user->email);

    $this->getJson("/api/v1/auth/verify-email/{$user->id}/{$hash}?expires=9999999999&signature=forged")
        ->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('signs in with a cookie session and signs out again', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 'ADA@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    $this->getJson('/api/v1/me')->assertOk();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->assertGuest('web');
});

it('rejects wrong credentials without saying which part was wrong', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'nope']);
    $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'nope']);

    $wrongPassword->assertUnprocessable();
    expect($unknownEmail->json())->toBe($wrongPassword->json());
    $this->assertGuest('web');
});

it('limits repeated sign-in attempts', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'x']);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'x'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');
});

it('answers a password reset request the same way for known and unknown addresses', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.com'])->assertOk();
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

    expect($unknown->json())->toBe($known->json());
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

it('resets the password with the emailed token and revokes API tokens', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->createToken('old');
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.com']);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user, &$token) {
        $token = $n->token;
        expect($n->toMail($user)->actionUrl)->toStartWith('http://localhost:5173/reset-password?token=');

        return true;
    });

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'email' => 'ada@example.com',
        'password' => 'a brand new secret', 'password_confirmation' => 'a brand new secret',
    ])->assertOk();

    expect(Hash::check('a brand new secret', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    // Sessions on other devices end too.
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'email' => 'ada@example.com',
        'password' => 'another secret 123', 'password_confirmation' => 'another secret 123',
    ])->assertUnprocessable();
});

it('lets a user change their name and language', function () {
    $this->actingAs(User::factory()->create());

    $this->patchJson('/api/v1/me', ['name' => 'New Name', 'locale' => 'pl'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.locale', 'pl');

    $this->patchJson('/api/v1/me', ['locale' => 'xx'])->assertUnprocessable();
});
