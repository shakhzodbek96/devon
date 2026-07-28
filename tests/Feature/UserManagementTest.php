<?php

use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
});

function actingUser(string $role): array
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token];
}

it('forbids ROLE_EMPLOYEE from creating a user (403)', function () {
    [, $token] = actingUser('ROLE_EMPLOYEE');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/users', [
            'email' => 'new@devon.uz',
            'fullName' => 'New Person',
            'roles' => ['ROLE_EMPLOYEE'],
        ])
        ->assertStatus(403);
});

it('forbids ROLE_EMPLOYEE from listing users (403)', function () {
    [, $token] = actingUser('ROLE_EMPLOYEE');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/users')
        ->assertStatus(403);
});

it('allows ROLE_SUPER_ADMIN to create a user (201) and generates a tempPassword when none supplied', function () {
    [, $token] = actingUser('ROLE_SUPER_ADMIN');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/users', [
            'email' => 'new@devon.uz',
            'fullName' => 'New Person',
            'roles' => ['ROLE_HR_OPERATOR'],
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['user' => ['uuid', 'email', 'fullName', 'roles', 'mustChangePassword'], 'tempPassword']);

    expect($response->json('user.mustChangePassword'))->toBeTrue()
        ->and($response->json('tempPassword'))->toBeString()->and(strlen($response->json('tempPassword')))->toBeGreaterThanOrEqual(10);

    $this->assertDatabaseHas('users', ['email' => 'new@devon.uz', 'must_change_password' => true]);
});

it('allows ROLE_HR_ADMIN to create a user (201)', function () {
    [, $token] = actingUser('ROLE_HR_ADMIN');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/users', [
            'email' => 'hrcreated@devon.uz',
            'fullName' => 'HR Created',
            'roles' => ['ROLE_EMPLOYEE'],
            'password' => 'ExplicitPassw0rd!',
        ])
        ->assertStatus(201)
        ->assertJsonMissing(['tempPassword']); // explicit password given — nothing to reveal
});

it('rejects a duplicate email with 422', function () {
    [, $token] = actingUser('ROLE_SUPER_ADMIN');
    User::factory()->create(['email' => 'dup@devon.uz']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/users', [
            'email' => 'dup@devon.uz',
            'fullName' => 'Dup',
            'roles' => ['ROLE_EMPLOYEE'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('updates a user role and status via PATCH', function () {
    [, $token] = actingUser('ROLE_SUPER_ADMIN');
    $target = User::factory()->create();
    $target->assignRole('ROLE_EMPLOYEE');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/users/{$target->uuid}", [
            'roles' => ['ROLE_AUDITOR'],
            'status' => 'SUSPENDED',
        ]);

    $response->assertOk()
        ->assertJsonPath('roles', ['ROLE_AUDITOR'])
        ->assertJsonPath('status', 'SUSPENDED');
});

it('resets a user password and forces a change on next login', function () {
    [, $token] = actingUser('ROLE_SUPER_ADMIN');
    $target = User::factory()->create(['password' => 'Original123!']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/users/{$target->uuid}/reset-password");

    $response->assertOk()->assertJsonStructure(['tempPassword']);
    expect($target->fresh()->must_change_password)->toBeTrue();
});
