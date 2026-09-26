<?php

use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('default factory produces a customer user', function () {
    $user = User::factory()->create();

    expect($user->user_type)->toBe(UserType::Customer);
    expect($user->user_type->value)->toBe('customer');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'user_type' => 'customer',
    ]);
});

test('factory states persist and cast correctly for customer, agent, and admin', function () {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();
    $admin = User::factory()->admin()->create();

    expect($customer->refresh()->user_type)->toBe(UserType::Customer);
    expect($agent->refresh()->user_type)->toBe(UserType::Agent);
    expect($admin->refresh()->user_type)->toBe(UserType::Admin);

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'user_type' => 'customer']);
    $this->assertDatabaseHas('users', ['id' => $agent->id, 'user_type' => 'agent']);
    $this->assertDatabaseHas('users', ['id' => $admin->id, 'user_type' => 'admin']);
});

test('authenticated inertia response serializes user_type as backed string value', function () {
    $user = User::factory()->customer()->create();

    $response = $this->actingAs($user)->get(route('customer.dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.id', $user->id)
        ->where('auth.user.user_type', 'customer')
    );
});

test('authenticated inertia response serializes agent and admin user types', function () {
    $agent = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->create(['user_id' => $agent->id]);
    $this->actingAs($agent)
        ->get(route('agent.dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.user_type', 'agent')
        );

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.user_type', 'admin')
        );
});

test('migration aborts if unclassified users exist in database', function () {
    $migration = require database_path('migrations/2026_09_20_100328_add_user_type_to_users_table.php');

    User::factory()->create();

    expect(fn () => $migration->up())->toThrow(
        RuntimeException::class,
        'Cannot add required user_type column to users table: existing users found with unclassified roles.'
    );
});
