<?php

use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Notification bell (PLAN_PHASE_F1.md §3, §6)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class]);
});

function notifActingUser(): array
{
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $employee->update(['user_uuid' => $user->uuid]);
    $user->assignRole('ROLE_EMPLOYEE');
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token, $employee->fresh()];
}

it('only ever lists the authenticated employee\'s own notifications', function () {
    [, $tokenA, $employeeA] = notifActingUser();
    [, , $employeeB] = notifActingUser();

    Notification::create([
        'uuid' => (string) Str::uuid(), 'recipient_employee_uuid' => $employeeA->uuid,
        'type' => 'DOC_APPROVED', 'title_key' => 'dashboard:notifications.title.DOC_APPROVED',
        'params' => ['docNumber' => 'HJ-2026/0001'], 'resource_type' => 'document',
        'resource_uuid' => (string) Str::uuid(), 'is_read' => false,
    ]);
    Notification::create([
        'uuid' => (string) Str::uuid(), 'recipient_employee_uuid' => $employeeB->uuid,
        'type' => 'DOC_APPROVED', 'title_key' => 'dashboard:notifications.title.DOC_APPROVED',
        'params' => ['docNumber' => 'HJ-2026/0002'], 'resource_type' => 'document',
        'resource_uuid' => (string) Str::uuid(), 'is_read' => false,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/me/notifications');
    $response->assertOk();
    expect($response->json())->toHaveCount(1);
    expect($response->json('0.recipientEmployeeUuid'))->toBe($employeeA->uuid);
    expect($response->json('0.titleKey'))->toBe('dashboard:notifications.title.DOC_APPROVED');
});

it('marks a single notification read and marks all read', function () {
    [, $token, $employee] = notifActingUser();

    $n1 = Notification::create([
        'uuid' => (string) Str::uuid(), 'recipient_employee_uuid' => $employee->uuid,
        'type' => 'DOC_APPROVED', 'title_key' => 'dashboard:notifications.title.DOC_APPROVED',
        'params' => [], 'resource_type' => 'document', 'resource_uuid' => (string) Str::uuid(), 'is_read' => false,
    ]);
    $n2 = Notification::create([
        'uuid' => (string) Str::uuid(), 'recipient_employee_uuid' => $employee->uuid,
        'type' => 'DOC_CLOSED', 'title_key' => 'dashboard:notifications.title.DOC_CLOSED',
        'params' => [], 'resource_type' => 'document', 'resource_uuid' => (string) Str::uuid(), 'is_read' => false,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/notifications/{$n1->uuid}/read")
        ->assertOk();
    expect(Notification::where('uuid', $n1->uuid)->value('is_read'))->toBeTrue();
    expect(Notification::where('uuid', $n2->uuid)->value('is_read'))->toBeFalse();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/me/notifications/read-all')
        ->assertOk();
    expect(Notification::where('uuid', $n2->uuid)->value('is_read'))->toBeTrue();
});

it('requires authentication for the notification bell', function () {
    $this->getJson('/api/v1/me/notifications')->assertStatus(401);
});
