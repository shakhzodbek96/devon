<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('rejects an incorrect current password with 422', function () {
    $user = User::factory()->create(['password' => 'Demo2026!']);
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-current',
            'new_password' => 'NewPassw0rd!23',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('current_password');

    expect(Hash::check('Demo2026!', $user->fresh()->password))->toBeTrue();
});

it('changes the password, flips must_change_password and stamps password_changed_at', function () {
    $user = User::factory()->mustChangePassword()->create(['password' => 'Demo2026!']);
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Demo2026!',
            'new_password' => 'NewPassw0rd!23',
        ]);

    $response->assertStatus(204);

    $fresh = $user->fresh();
    expect($fresh->must_change_password)->toBeFalse()
        ->and($fresh->password_changed_at)->not->toBeNull()
        ->and(Hash::check('NewPassw0rd!23', $fresh->password))->toBeTrue();
});

it('rejects a new password that fails the policy', function () {
    $user = User::factory()->create(['password' => 'Demo2026!']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Demo2026!',
            'new_password' => 'weak',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_password');
});
