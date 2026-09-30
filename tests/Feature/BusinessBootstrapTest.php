<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Models\BusinessProfile;
use App\Models\PermissionGrantHistory;
use App\Models\User;
use App\Services\BusinessBootstrap;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

test('trusted first admin starts with explicit seed grants and mandatory MFA setup', function () {
    $admin = app(BusinessBootstrap::class)->provision('Reviewed Business', 'First Admin', 'FIRST@example.org', 'private-first-password');
    expect($admin->account_state)->toBe(AccountState::MfaSetupRequired)->and($admin->email_normalized)->toBe('first@example.org');
    expect(Hash::check('private-first-password', $admin->password))->toBeTrue();
    expect($admin->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe(collect(AdminPermission::bootstrapValues())->sort()->values()->all());
    expect(PermissionGrantHistory::query()->where('source', 'system_seed')->count())->toBe(count(AdminPermission::bootstrapValues()));
    expect($admin->hasDirectPermission(AdminPermission::CashExecute))->toBeFalse();
    expect(BusinessProfile::current()->display_name)->toBe('Reviewed Business');
    expect(BusinessProfile::current()->getAttribute('effective_configuration_id'))->not->toBeNull();
    $this->post(route('login'), ['email' => 'first@example.org', 'password' => 'private-first-password'])->assertRedirect(route('two-factor.enrolment'));
});
test('bootstrap refuses an existing installation without replacing its users or permissions', function () {
    $existing = User::factory()->admin()->withTwoFactor()->create();
    expect(fn () => app(BusinessBootstrap::class)->provision('Other', 'Other Admin', 'other@example.org', 'private-first-password'))->toThrow(ConflictHttpException::class);
    $this->assertDatabaseCount('users', 1);
    expect($existing->fresh()->getDirectPermissions())->toHaveCount(0);
    expect(BusinessProfile::current()->display_name)->toBe('SaverApp');
});
test('bootstrap rejects invalid private credentials before any user or configuration commits', function () {
    expect(fn () => app(BusinessBootstrap::class)->provision('Reviewed Business', 'First Admin', 'valid@example.org', 'short'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('business_configuration_versions', 0);
});
test('non interactive bootstrap has no default password', function () {
    $this->artisan('business:bootstrap', ['--display-name' => 'Reviewed', '--admin-name' => 'First', '--admin-email' => 'first@example.org', '--no-interaction' => true])->assertFailed();
    $this->assertDatabaseCount('users', 0);
});
