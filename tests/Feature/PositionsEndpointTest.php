<?php

use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class]);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/positions')->assertStatus(401);
});

it('returns the seeded position catalogue for any authenticated role', function () {
    $user = User::factory()->create();
    $user->assignRole('ROLE_EMPLOYEE');
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/positions')
        ->assertOk();

    $rows = $response->json();
    expect($rows)->toHaveCount(15);
    expect($rows[0])->toHaveKeys(['id', 'nameUz', 'allowedUnitTypes']);
    expect(collect($rows)->pluck('id'))->toContain('POS-DIR', 'POS-CHANCELLERY');
});
