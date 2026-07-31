<?php

use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class]);
});

function positionsActingToken(string $role): string
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->createToken('test')->plainTextToken;
}

it('lets an admin create a position with a generated id and audits it', function () {
    $token = positionsActingToken('ROLE_SUPER_ADMIN');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/positions', [
            'nameUz' => 'Bosh muhandis',
            'allowedUnitTypes' => ['DIVISION', 'SECTION'],
        ])
        ->assertCreated()
        ->assertJson([
            'nameUz' => 'Bosh muhandis',
            'allowedUnitTypes' => ['DIVISION', 'SECTION'],
        ]);

    $id = $response->json('id');
    expect($id)->toStartWith('POS-');
    $this->assertDatabaseHas('positions', ['id' => $id, 'name_uz' => 'Bosh muhandis']);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'CREATE',
        'resource_type' => 'position',
        'resource_uuid' => $id,
    ]);
});

it('rejects a duplicate name (case-insensitive) with a 422 code', function () {
    $token = positionsActingToken('ROLE_SUPER_ADMIN');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/positions', ['nameUz' => '  direktor ', 'allowedUnitTypes' => ['DEPARTMENT']])
        ->assertStatus(422)
        ->assertJson(['code' => 'duplicate-name']);
});

it('updates a position name and unit types', function () {
    $token = positionsActingToken('ROLE_SUPER_ADMIN');
    $created = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/positions', ['nameUz' => 'Kotib', 'allowedUnitTypes' => ['OTHER']])
        ->json();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/positions/{$created['id']}", [
            'nameUz' => 'Bosh kotib',
            'allowedUnitTypes' => ['DIVISION'],
        ])
        ->assertOk()
        ->assertJson(['nameUz' => 'Bosh kotib', 'allowedUnitTypes' => ['DIVISION']]);
});

it('deletes an unused position', function () {
    $token = positionsActingToken('ROLE_SUPER_ADMIN');
    $created = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/positions', ['nameUz' => 'Vaqtinchalik', 'allowedUnitTypes' => ['OTHER']])
        ->json();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/positions/{$created['id']}")
        ->assertNoContent();

    $this->assertDatabaseMissing('positions', ['id' => $created['id']]);
});

it('refuses to delete a position referenced by an employee (in-use)', function () {
    $token = positionsActingToken('ROLE_SUPER_ADMIN');
    Employee::factory()->create(['position_id' => 'POS-SPECIALIST']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/v1/positions/POS-SPECIALIST')
        ->assertStatus(422)
        ->assertJson(['code' => 'in-use']);

    $this->assertDatabaseHas('positions', ['id' => 'POS-SPECIALIST']);
});

it('forbids a non-admin from creating a position', function () {
    $token = positionsActingToken('ROLE_EMPLOYEE');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/positions', ['nameUz' => 'Yangi', 'allowedUnitTypes' => ['OTHER']])
        ->assertStatus(403);

    // ...but can still read the catalogue.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/positions')
        ->assertOk();
});
