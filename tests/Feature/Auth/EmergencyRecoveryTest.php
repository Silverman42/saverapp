<?php

use App\Enums\AccountState;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\StaffRecovery;
use App\Models\User;
use App\Notifications\Auth\StaffRecoveryActivationNotification;
use App\Services\AuthorizationRestrictionService;
use App\Services\EmergencyRecoveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Notification::fake();
});

function emergencyFinalAdmin(): array
{
    $admin = User::factory()->admin()->withTwoFactor()->create(['email' => 'founder@example.test']);
    $key = app(EmergencyRecoveryService::class)->issue($admin, 'test');

    return [$admin, $key];
}

function emergencyLinkToken(): string
{
    $url = null;
    Notification::assertSentOnDemand(StaffRecoveryActivationNotification::class, function (StaffRecoveryActivationNotification $notification, array $channels, object $notifiable) use (&$url): bool {
        $url = $notification->activationUrl;

        return $notifiable->routes['mail'] === 'founder@example.test';
    });

    return str($url)->after('#token=')->toString();
}

test('only a hash of the key is stored and the operator command never silently replaces it', function (): void {
    [$admin, $key] = emergencyFinalAdmin();

    $profile = BusinessProfile::current();
    expect($profile->emergency_key_hash)->not->toBeNull()->not->toContain($key)
        ->and($profile->emergency_admin_user_id)->toBe($admin->id)
        ->and($profile->toArray())->not->toHaveKey('emergency_key_hash')
        ->and(DB::table('audit_events')->where('payload', 'like', '%'.$key.'%')->exists())->toBeFalse();

    $this->artisan('business:emergency-key', ['--admin-email' => $admin->email])->assertFailed();
    expect(BusinessProfile::current()->emergency_key_hash)->toBe($profile->emergency_key_hash);
});

test('the final Admin recovers with the key and seeded email, the key is consumed and a replacement is shown once', function (): void {
    [$admin, $key] = emergencyFinalAdmin();
    $oldHash = BusinessProfile::current()->emergency_key_hash;

    $this->post(route('emergency-recovery.store'), ['email' => 'founder@example.test', 'key' => strtolower($key)])
        ->assertRedirect(route('login'));

    $admin->refresh();
    expect(BusinessProfile::current()->emergency_key_hash)->toBeNull()
        ->and($admin->password)->toBeNull()
        ->and($admin->two_factor_secret)->toBeNull()
        ->and($admin->recovery_pending)->toBeTrue();

    $recovery = StaffRecovery::query()->where('user_id', $admin->id)->sole();
    expect($recovery->kind)->toBe('emergency');

    $this->post(route('staff-recovery.activate', $recovery->reference), ['token' => emergencyLinkToken(),
        'password' => 'Str0ng!Founder#2026', 'password_confirmation' => 'Str0ng!Founder#2026'])
        ->assertRedirect(route('emergency-recovery.replacement'));

    $replacement = null;
    $this->get(route('emergency-recovery.replacement'))->assertInertia(function (Assert $page) use (&$replacement): void {
        $page->component('auth/EmergencyKeyIssued');
        $replacement = $page->toArray()['props']['recovery_key'];
    });
    $this->get(route('emergency-recovery.replacement'))->assertRedirect(route('two-factor.enrolment'));

    $profile = BusinessProfile::current();
    expect($replacement)->toBeString()->not->toBe($key)
        ->and($profile->emergency_key_hash)->not->toBeNull()->not->toBe($oldHash)
        ->and($admin->fresh()->account_state)->toBe(AccountState::MfaSetupRequired)
        ->and(app(AuthorizationRestrictionService::class)->getActiveRestrictions($admin->fresh()))->toBeEmpty()
        ->and(DB::table('audit_events')->where('payload', 'like', '%'.$replacement.'%')->exists())->toBeFalse();
});

test('a wrong key, wrong email or another active Admin leaves the account and key untouched', function (): void {
    [$admin, $key] = emergencyFinalAdmin();
    $hash = BusinessProfile::current()->emergency_key_hash;

    $this->post(route('emergency-recovery.store'), ['email' => 'founder@example.test', 'key' => 'WRONG-WRONG-WRONG'])->assertRedirect(route('login'));
    $this->post(route('emergency-recovery.store'), ['email' => 'someone@example.test', 'key' => $key])->assertRedirect(route('login'));
    User::factory()->admin()->withTwoFactor()->create();
    $this->post(route('emergency-recovery.store'), ['email' => 'founder@example.test', 'key' => $key])->assertRedirect(route('login'));

    expect(BusinessProfile::current()->emergency_key_hash)->toBe($hash)
        ->and($admin->fresh()->password)->not->toBeNull()
        ->and(StaffRecovery::query()->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', 'auth.emergency_recovery_failed')->count())->toBe(3);
    Notification::assertNothingSent();
});

test('the bootstrap command issues the key once while provisioning the first Admin', function (): void {
    User::query()->delete();
    $file = tempnam(sys_get_temp_dir(), 'pw');
    file_put_contents($file, 'Str0ng!Bootstrap#Admin2026');
    chmod($file, 0600);

    $this->artisan('business:bootstrap', ['--display-name' => 'Reviewed', '--admin-name' => 'First', '--admin-email' => 'first@example.org',
        '--password-file' => $file, '--no-interaction' => true])
        ->expectsOutputToContain('Business emergency recovery key')
        ->assertSuccessful();
    unlink($file);

    expect(BusinessProfile::current()->emergency_admin_user_id)->toBe(User::query()->where('email', 'first@example.org')->value('id'));
});
