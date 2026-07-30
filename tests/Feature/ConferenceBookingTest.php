<?php

use App\Models\ConferenceBooking;
use App\Models\ConferenceRoom;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Conference-room bookings — PLAN_conference-room.md §1, §3
|--------------------------------------------------------------------------
|
| Booking + overlap rejection + cancel (owner or conference-rooms.manage)
| + unauthorized cancel rejection. No `can:` route middleware — any
| authenticated employee may book.
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
});

/**
 * @return array{0: User, 1: string, 2: Employee}
 */
function bookingActingUser(string $role = 'ROLE_EMPLOYEE'): array
{
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $employee->update(['user_uuid' => $user->uuid]);
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token, $employee->fresh()];
}

/** Same forgetGuards() workaround as TasksTest.php's asTask(). */
function asBooking(TestCase $test, string $token): TestCase
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', "Bearer {$token}");
}

it('requires a linked employee to list or create bookings', function () {
    $user = User::factory()->create(['employee_uuid' => null]);
    $user->assignRole('ROLE_EMPLOYEE');
    $token = $user->createToken('test')->plainTextToken;

    asBooking($this, $token)->getJson('/api/v1/conference-bookings')->assertStatus(422);
});

it('books a room and writes a CREATE audit entry', function () {
    [$actor, $token, $employee] = bookingActingUser();
    $room = ConferenceRoom::factory()->create();

    $response = asBooking($this, $token)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $room->uuid,
        'purpose' => 'Kvartal yig\'ilishi',
        'date' => now()->addDay()->format('Y-m-d'),
        'startTime' => '09:00',
        'endTime' => '10:00',
    ]);

    $response->assertStatus(201);
    $body = $response->json();
    expect($body['roomUuid'])->toBe($room->uuid)
        ->and($body['bookedBy'])->toBe($employee->uuid)
        ->and($body['status'])->toBe('BOOKED')
        ->and($body['startTime'])->toBe('09:00')
        ->and($body['endTime'])->toBe('10:00');

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CREATE',
        'resource_type' => 'conference-booking',
        'resource_uuid' => $body['uuid'],
    ]);
});

it('rejects booking a non-existent room with code invalid-room', function () {
    [, $token] = bookingActingUser();

    asBooking($this, $token)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => (string) Illuminate\Support\Str::uuid(),
        'purpose' => 'X',
        'date' => now()->addDay()->format('Y-m-d'),
        'startTime' => '09:00',
        'endTime' => '10:00',
    ])->assertStatus(422)->assertJsonPath('code', 'invalid-room');
});

it('rejects booking an archived room with code room-archived', function () {
    [, $token] = bookingActingUser();
    $room = ConferenceRoom::factory()->create(['status' => 'ARCHIVED']);

    asBooking($this, $token)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $room->uuid,
        'purpose' => 'X',
        'date' => now()->addDay()->format('Y-m-d'),
        'startTime' => '09:00',
        'endTime' => '10:00',
    ])->assertStatus(422)->assertJsonPath('code', 'room-archived');
});

it('rejects an inverted time range with code invalid-range', function () {
    [, $token] = bookingActingUser();
    $room = ConferenceRoom::factory()->create();

    asBooking($this, $token)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $room->uuid,
        'purpose' => 'X',
        'date' => now()->addDay()->format('Y-m-d'),
        'startTime' => '10:00',
        'endTime' => '09:00',
    ])->assertStatus(422)->assertJsonPath('code', 'invalid-range');
});

it('rejects an overlapping booking with code time-conflict', function () {
    [, $token] = bookingActingUser();
    $room = ConferenceRoom::factory()->create();
    $date = now()->addDay()->format('Y-m-d');

    asBooking($this, $token)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $room->uuid,
        'purpose' => 'Birinchi',
        'date' => $date,
        'startTime' => '09:00',
        'endTime' => '10:00',
    ])->assertStatus(201);

    // Overlapping ranges — partial overlap, containment, and exact match —
    // all rejected with time-conflict.
    foreach ([['09:30', '10:30'], ['08:30', '09:30'], ['09:15', '09:45'], ['09:00', '10:00']] as [$start, $end]) {
        asBooking($this, $token)->postJson('/api/v1/conference-bookings', [
            'roomUuid' => $room->uuid,
            'purpose' => 'Kesishuvchi',
            'date' => $date,
            'startTime' => $start,
            'endTime' => $end,
        ])->assertStatus(422)->assertJsonPath('code', 'time-conflict');
    }

    // A back-to-back, non-overlapping slot is allowed.
    asBooking($this, $token)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $room->uuid,
        'purpose' => 'Ketma-ket',
        'date' => $date,
        'startTime' => '10:00',
        'endTime' => '11:00',
    ])->assertStatus(201);

    expect(ConferenceBooking::where('room_uuid', $room->uuid)->count())->toBe(2);
});

it('lets the booking owner cancel and audits CANCEL', function () {
    [$actor, $token] = bookingActingUser();
    $room = ConferenceRoom::factory()->create();

    $created = asBooking($this, $token)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $room->uuid,
        'purpose' => 'Bekor qilinadigan',
        'date' => now()->addDay()->format('Y-m-d'),
        'startTime' => '09:00',
        'endTime' => '10:00',
    ])->assertStatus(201)->json();

    $response = asBooking($this, $token)->postJson("/api/v1/conference-bookings/{$created['uuid']}/cancel");
    $response->assertOk()->assertJsonPath('status', 'CANCELLED');

    $this->assertDatabaseHas('conference_bookings', ['uuid' => $created['uuid'], 'status' => 'CANCELLED']);
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CANCEL',
        'resource_type' => 'conference-booking',
        'resource_uuid' => $created['uuid'],
    ]);

    // A cancelled slot frees the room up for the same range.
    asBooking($this, $token)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $room->uuid,
        'purpose' => 'Qayta band qilish',
        'date' => now()->addDay()->format('Y-m-d'),
        'startTime' => '09:00',
        'endTime' => '10:00',
    ])->assertStatus(201);
});

it('lets a conference-rooms.manage holder cancel someone else\'s booking', function () {
    [, $ownerToken] = bookingActingUser();
    $room = ConferenceRoom::factory()->create();

    $created = asBooking($this, $ownerToken)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $room->uuid,
        'purpose' => 'Egasi tomonidan',
        'date' => now()->addDay()->format('Y-m-d'),
        'startTime' => '09:00',
        'endTime' => '10:00',
    ])->assertStatus(201)->json();

    [, $adminToken] = bookingActingUser('ROLE_HR_ADMIN');

    asBooking($this, $adminToken)->postJson("/api/v1/conference-bookings/{$created['uuid']}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'CANCELLED');
});

it('forbids a bystander without conference-rooms.manage from cancelling someone else\'s booking', function () {
    [, $ownerToken] = bookingActingUser();
    $room = ConferenceRoom::factory()->create();

    $created = asBooking($this, $ownerToken)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $room->uuid,
        'purpose' => 'Egasi tomonidan',
        'date' => now()->addDay()->format('Y-m-d'),
        'startTime' => '09:00',
        'endTime' => '10:00',
    ])->assertStatus(201)->json();

    [, $bystanderToken] = bookingActingUser();

    asBooking($this, $bystanderToken)->postJson("/api/v1/conference-bookings/{$created['uuid']}/cancel")
        ->assertStatus(422)
        ->assertJsonPath('code', 'not-authorized');

    $this->assertDatabaseHas('conference_bookings', ['uuid' => $created['uuid'], 'status' => 'BOOKED']);
});

it('filters bookings by roomUuid, date, and box=mine', function () {
    [, $tokenA, $employeeA] = bookingActingUser();
    [, $tokenB] = bookingActingUser();
    $roomOne = ConferenceRoom::factory()->create();
    $roomTwo = ConferenceRoom::factory()->create();
    $date = now()->addDay()->format('Y-m-d');
    $otherDate = now()->addDays(2)->format('Y-m-d');

    asBooking($this, $tokenA)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $roomOne->uuid, 'purpose' => 'A1', 'date' => $date, 'startTime' => '09:00', 'endTime' => '10:00',
    ])->assertStatus(201);
    asBooking($this, $tokenA)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $roomTwo->uuid, 'purpose' => 'A2', 'date' => $otherDate, 'startTime' => '09:00', 'endTime' => '10:00',
    ])->assertStatus(201);
    asBooking($this, $tokenB)->postJson('/api/v1/conference-bookings', [
        'roomUuid' => $roomOne->uuid, 'purpose' => 'B1', 'date' => $date, 'startTime' => '11:00', 'endTime' => '12:00',
    ])->assertStatus(201);

    $byRoom = asBooking($this, $tokenA)->getJson("/api/v1/conference-bookings?roomUuid={$roomOne->uuid}")->json();
    expect($byRoom)->toHaveCount(2);

    $byDate = asBooking($this, $tokenA)->getJson("/api/v1/conference-bookings?date={$date}")->json();
    expect($byDate)->toHaveCount(2);

    $mine = asBooking($this, $tokenA)->getJson('/api/v1/conference-bookings?box=mine')->json();
    expect($mine)->toHaveCount(2);
    foreach ($mine as $booking) {
        expect($booking['bookedBy'])->toBe($employeeA->uuid);
    }
});
