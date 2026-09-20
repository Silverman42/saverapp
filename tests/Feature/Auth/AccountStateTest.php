<?php

use App\Enums\AccountState;
use App\Models\User;
use App\Rules\UniqueNormalizedEmail;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia;

test('default factory produces an active user with normalized email', function () {
    $user = User::factory()->create([
        'email' => 'Customer@Example.Com',
    ]);

    expect($user->account_state)->toBe(AccountState::Active);
    expect($user->account_state->value)->toBe('active');
    expect($user->email)->toBe('Customer@Example.Com');
    expect($user->email_normalized)->toBe('customer@example.com');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => 'Customer@Example.Com',
        'email_normalized' => 'customer@example.com',
        'account_state' => 'active',
    ]);
});

test('factory states persist and cast correctly for all account states', function () {
    $invited = User::factory()->invited()->create();
    $mfaSetup = User::factory()->mfaSetupRequired()->create();
    $active = User::factory()->active()->create();
    $suspended = User::factory()->suspended()->create();
    $deactivated = User::factory()->deactivated()->create();

    expect($invited->refresh()->account_state)->toBe(AccountState::Invited);
    expect($mfaSetup->refresh()->account_state)->toBe(AccountState::MfaSetupRequired);
    expect($active->refresh()->account_state)->toBe(AccountState::Active);
    expect($suspended->refresh()->account_state)->toBe(AccountState::Suspended);
    expect($deactivated->refresh()->account_state)->toBe(AccountState::Deactivated);

    $this->assertDatabaseHas('users', ['id' => $invited->id, 'account_state' => 'invited']);
    $this->assertDatabaseHas('users', ['id' => $mfaSetup->id, 'account_state' => 'mfa_setup_required']);
    $this->assertDatabaseHas('users', ['id' => $active->id, 'account_state' => 'active']);
    $this->assertDatabaseHas('users', ['id' => $suspended->id, 'account_state' => 'suspended']);
    $this->assertDatabaseHas('users', ['id' => $deactivated->id, 'account_state' => 'deactivated']);
});

test('email normalization preserves display casing, trims whitespace, and preserves plus-addresses and dots', function () {
    $user = User::factory()->create([
        'email' => '  Jane.Doe+Savings@Example.COM  ',
    ]);

    $refreshed = $user->refresh();

    expect($refreshed->email)->toBe('  Jane.Doe+Savings@Example.COM  ');
    expect($refreshed->email_normalized)->toBe('jane.doe+savings@example.com');
});

test('duplicate normalized email throws database unique constraint violation', function () {
    User::factory()->create([
        'email' => 'member@example.com',
    ]);

    expect(fn () => User::factory()->create([
        'email' => 'Member@Example.COM',
    ]))->toThrow(Exception::class);
});

test('user query scope finds records by normalized email regardless of query casing', function () {
    $user = User::factory()->create([
        'email' => 'John.Doe@Example.com',
    ]);

    $found = User::findByNormalizedEmail('JOHN.DOE@EXAMPLE.COM');
    expect($found)->not->toBeNull();
    expect($found->id)->toBe($user->id);

    $foundScoped = User::whereNormalizedEmail('  john.doe@example.com  ')->first();
    expect($foundScoped)->not->toBeNull();
    expect($foundScoped->id)->toBe($user->id);
});

test('unique normalized email validation rule prevents duplicate email under different casings', function () {
    $existing = User::factory()->create([
        'email' => 'agent@example.com',
    ]);

    $validatorFails = Validator::make([
        'email' => 'AGENT@EXAMPLE.COM',
    ], [
        'email' => [new UniqueNormalizedEmail],
    ]);
    expect($validatorFails->fails())->toBeTrue();

    $validatorPassesSameUser = Validator::make([
        'email' => 'AGENT@EXAMPLE.COM',
    ], [
        'email' => [new UniqueNormalizedEmail($existing->id)],
    ]);
    expect($validatorPassesSameUser->passes())->toBeTrue();

    $validatorPassesNewEmail = Validator::make([
        'email' => 'different@example.com',
    ], [
        'email' => [new UniqueNormalizedEmail],
    ]);
    expect($validatorPassesNewEmail->passes())->toBeTrue();
});

test('temporary lock isolates authentication restriction and preserves underlying account state', function () {
    $user = User::factory()->active()->create();

    expect($user->account_state)->toBe(AccountState::Active);
    expect($user->isTemporarilyLocked())->toBeFalse();
    expect($user->effectiveAccountState())->toBe(AccountState::Active);
    expect($user->canSignIn('password'))->toBeTrue();

    // Lock the account temporarily
    $user->lockTemporarily(15, 'password', 'Excessive failed password attempts');

    $refreshed = $user->refresh();
    expect($refreshed->account_state)->toBe(AccountState::Active);
    expect($refreshed->isTemporarilyLocked())->toBeTrue();
    expect($refreshed->isTemporarilyLocked('password'))->toBeTrue();
    expect($refreshed->isTemporarilyLocked('mfa'))->toBeFalse();
    expect($refreshed->effectiveAccountState())->toBe(AccountState::TemporarilyLocked);
    expect($refreshed->canSignIn('password'))->toBeFalse();
    expect($refreshed->lock_category)->toBe('password');
    expect($refreshed->lock_reason)->toBe('Excessive failed password attempts');

    // Automatic expiration after 16 minutes
    $this->travel(16)->minutes();

    expect($refreshed->isTemporarilyLocked())->toBeFalse();
    expect($refreshed->effectiveAccountState())->toBe(AccountState::Active);
    expect($refreshed->canSignIn('password'))->toBeTrue();

    // Manual unlock
    $this->travelBack();
    $refreshed->lockTemporarily(15, 'password', 'Another lock');
    expect($refreshed->isTemporarilyLocked())->toBeTrue();

    $refreshed->unlock();
    expect($refreshed->isTemporarilyLocked())->toBeFalse();
    expect($refreshed->locked_until)->toBeNull();
    expect($refreshed->lock_category)->toBeNull();
    expect($refreshed->lock_reason)->toBeNull();
});

test('account states determine sign-in eligibility', function () {
    $active = User::factory()->active()->create();
    $invited = User::factory()->invited()->create();
    $suspended = User::factory()->suspended()->create();
    $deactivated = User::factory()->deactivated()->create();
    $mfaSetup = User::factory()->mfaSetupRequired()->create();

    expect($active->canSignIn())->toBeTrue();
    expect($invited->canSignIn())->toBeFalse();
    expect($suspended->canSignIn())->toBeFalse();
    expect($deactivated->canSignIn())->toBeFalse();
    expect($mfaSetup->canSignIn())->toBeFalse();
    expect($mfaSetup->account_state->allowsSetupOnly())->toBeTrue();
});

test('system prevents suspension or deactivation of the final active admin', function () {
    $admin = User::factory()->admin()->active()->create();

    expect($admin->isFinalActiveAdmin())->toBeTrue();

    expect(function () use ($admin) {
        $admin->account_state = AccountState::Suspended;
        $admin->save();
    })->toThrow(RuntimeException::class, 'Cannot suspend or deactivate the final active Administrator.');

    expect(function () use ($admin) {
        $admin->account_state = AccountState::Deactivated;
        $admin->save();
    })->toThrow(RuntimeException::class, 'Cannot suspend or deactivate the final active Administrator.');

    // When another active Admin exists, suspension is permitted
    $secondAdmin = User::factory()->admin()->active()->create();
    expect($admin->isFinalActiveAdmin())->toBeFalse();

    $admin->account_state = AccountState::Suspended;
    $admin->save();
    expect($admin->refresh()->account_state)->toBe(AccountState::Suspended);
});

test('system prevents deletion of the final active admin', function () {
    $admin = User::factory()->admin()->active()->create();

    expect(function () use ($admin) {
        $admin->delete();
    })->toThrow(RuntimeException::class, 'Cannot delete the final active Administrator.');

    $secondAdmin = User::factory()->admin()->active()->create();
    expect($admin->delete())->toBeTrue();
});

test('system prevents deletion of users with historical attribution', function () {
    $user = User::factory()->active()->create();
    $user->forceHasHistoricalAttribution = true;

    expect(function () use ($user) {
        $user->delete();
    })->toThrow(RuntimeException::class, 'Cannot delete user with historical attribution; deactivate the account instead.');
});

test('authenticated inertia response serializes account_state and email_normalized', function () {
    $user = User::factory()->create([
        'email' => 'InertiaUser@Example.COM',
        'account_state' => AccountState::Active,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.id', $user->id)
        ->where('auth.user.account_state', 'active')
        ->where('auth.user.email_normalized', 'inertiauser@example.com')
    );
});

test('migration aborts if unclassified users exist in database', function () {
    $migration = require database_path('migrations/2026_09_20_143000_add_account_state_and_normalized_email_to_users_table.php');

    User::factory()->create();

    expect(fn () => $migration->up())->toThrow(
        RuntimeException::class,
        'Cannot add required account_state and email_normalized columns to users table: existing users found with unclassified account states.'
    );
});
