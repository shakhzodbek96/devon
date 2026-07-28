<?php

use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
});

it('logs in with valid credentials and returns a token + SessionUser-shaped user', function () {
    $user = User::factory()->create([
        'email' => 'admin@devon.uz',
        'password' => 'Demo2026!',
    ]);
    $user->assignRole('ROLE_SUPER_ADMIN');

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@devon.uz',
        'password' => 'Demo2026!',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'token',
            'user' => ['uuid', 'email', 'fullName', 'roles', 'mustChangePassword'],
        ])
        ->assertJsonPath('user.email', 'admin@devon.uz')
        ->assertJsonPath('user.roles', ['ROLE_SUPER_ADMIN']);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

it('rejects an invalid password with 401', function () {
    User::factory()->create([
        'email' => 'admin@devon.uz',
        'password' => 'Demo2026!',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@devon.uz',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401)->assertJsonStructure(['message']);
});

it('rejects an unknown email with 401', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@devon.uz',
        'password' => 'whatever123',
    ]);

    $response->assertStatus(401);
});

it('rejects a suspended user with 401', function () {
    User::factory()->create([
        'email' => 'suspended@devon.uz',
        'password' => 'Demo2026!',
        'status' => 'SUSPENDED',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'suspended@devon.uz',
        'password' => 'Demo2026!',
    ]);

    $response->assertStatus(401);
});
