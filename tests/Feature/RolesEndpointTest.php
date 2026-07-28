<?php

use App\Models\User;
use Database\Seeders\RolesSeeder;

it('returns all 7 Devon roles with code + nameUz', function () {
    $this->seed(RolesSeeder::class);

    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/roles');

    $response->assertOk()->assertJsonCount(7);

    $codes = collect($response->json())->pluck('code')->sort()->values()->all();
    expect($codes)->toBe([
        'ROLE_AUDITOR',
        'ROLE_DEVONXONA',
        'ROLE_EMPLOYEE',
        'ROLE_HR_ADMIN',
        'ROLE_HR_OPERATOR',
        'ROLE_SUPER_ADMIN',
        'ROLE_UNIT_HEAD',
    ]);

    $response->assertJsonStructure(['*' => ['code', 'nameUz']]);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/roles')->assertStatus(401);
});
