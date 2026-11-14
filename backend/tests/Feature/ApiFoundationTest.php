<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

it('describes the API at its root', function () {
    $this->getJson('/api/v1')
        ->assertOk()
        ->assertJson(['api_version' => 'v1']);
});

it('rejects anonymous requests with the uniform error body', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertExactJson(['error' => [
            'code' => 'unauthenticated',
            'message' => 'Authentication is required.',
        ]]);
});

it('returns the signed-in user without secrets', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonMissingPath('data.password');
});

it('accepts a personal access token as a bearer token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('cli')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('answers unknown API paths with a JSON 404', function () {
    $this->getJson('/api/v1/nope')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('reports validation problems per field', function () {
    Route::post('/api/v1/_test/validate', fn () => request()->validate(['title' => 'required']));

    $this->postJson('/api/v1/_test/validate', [])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields' => ['title']]]]);
});

it('hides internal details of unexpected errors', function () {
    config(['app.debug' => false]);
    Route::get('/api/v1/_test/boom', fn () => throw new RuntimeException('secret detail'));

    $this->getJson('/api/v1/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error')
        ->assertDontSee('secret detail');
});
