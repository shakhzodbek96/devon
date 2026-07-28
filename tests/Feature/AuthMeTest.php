<?php

use App\Models\User;

it('returns the current user for a valid token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonStructure(['uuid', 'email', 'fullName', 'roles', 'mustChangePassword'])
        ->assertJsonPath('uuid', $user->uuid);

    // Flat SessionUser shape — no Laravel resource "data" envelope.
    expect(array_keys($response->json()))->not->toContain('data');
});

it('rejects the request without a token', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('rejects the request with an invalid token', function () {
    $this->withHeader('Authorization', 'Bearer not-a-real-token')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});
