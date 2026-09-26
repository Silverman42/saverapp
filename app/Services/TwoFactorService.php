<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AuthenticatorState;
use App\Jobs\ExpirePendingTwoFactorSetup;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Notifications\Auth\AuthenticatorEnrolledNotification;
use App\Notifications\Auth\AuthenticatorReplacedNotification;
use App\Notifications\Auth\AuthenticatorReplacementCancelledNotification;
use App\Notifications\Auth\AuthenticatorReplacementStartedNotification;
use App\Notifications\Auth\RecoveryCodesRegeneratedNotification;
use App\Notifications\Auth\RecoveryCodeUsedNotification;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    /**
     * Create a new two-factor service instance.
     */
    public function __construct(
        protected Google2FA $engine,
        protected StatefulGuard $guard,
    ) {
        // Enforce maximum +-1 period window (30s before, current, 30s after)
        $this->engine->setWindow(1);
    }

    /**
     * Generate a new base32 secret key.
     */
    public function generateSecretKey(int $secretLength = 16): string
    {
        return $this->engine->generateSecretKey($secretLength);
    }

    /**
     * Get the OTPAuth QR code URL for the given user email and secret.
     */
    public function qrCodeUrl(string $email, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            (string) config('app.name'),
            $email,
            $secret
        );
    }

    /**
     * Get the SVG representation of the QR code.
     */
    public function qrCodeSvg(string $email, string $secret): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72))),
                new SvgImageBackEnd
            )
        ))->writeString($this->qrCodeUrl($email, $secret));

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /**
     * Generate raw recovery codes.
     *
     * @return array<int, string>
     */
    public function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::random(10).'-'.Str::random(10);
        }

        return $codes;
    }

    /**
     * Verify a TOTP code against the user's active or pending secret with atomic replay prevention.
     */
    public function verifyTotp(User $user, string $code, bool $usePending = false): bool
    {
        $code = trim($code);

        if ($code === '' || strlen($code) !== 6 || ! ctype_digit($code)) {
            return false;
        }

        $encryptedSecret = $usePending
            ? $user->two_factor_pending_secret
            : $user->two_factor_secret;

        if (! $encryptedSecret) {
            return false;
        }

        $secret = Fortify::currentEncrypter()->decrypt($encryptedSecret);

        $lastTimestep = $usePending
            ? $user->two_factor_pending_last_used_timestep
            : $user->two_factor_last_used_timestep;

        $timestamp = $this->engine->verifyKeyNewer($secret, $code, $lastTimestep);

        if ($timestamp !== false) {
            $acceptedTimestep = ($timestamp === true) ? $this->engine->getTimestamp() : (int) $timestamp;

            if ($usePending) {
                $user->two_factor_pending_last_used_timestep = $acceptedTimestep;
            } else {
                $user->two_factor_last_used_timestep = $acceptedTimestep;
            }

            $user->save();

            return true;
        }

        return false;
    }

    /**
     * Verify and consume an emergency recovery code atomically.
     */
    public function verifyAndConsumeRecoveryCode(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $result = (function () use ($user, $code) {
                $code = trim($code);

                if ($code === '') {
                    return false;
                }

                $codeHash = hash('sha256', $code);

                return DB::transaction(function () use ($user, $codeHash): bool {
                    /** @var UserRecoveryCode|null $recoveryCode */
                    $recoveryCode = $user->recoveryCodes()
                        ->where('code_hash', $codeHash)
                        ->whereNull('consumed_at')
                        ->lockForUpdate()
                        ->first();

                    if (! $recoveryCode) {
                        return false;
                    }

                    $recoveryCode->consume();

                    $remainingCount = $user->recoveryCodes()->whereNull('consumed_at')->count();

                    $user->notify((new RecoveryCodeUsedNotification($remainingCount))->afterCommit());

                    return true;
                });

            })();
            if ($result !== false) {
                AuditEvent::record('auth.recovery_codes_used', User::class, $user->id, null,
                    ['changed_fields' => ['verifyAndConsumeRecoveryCode']], $user, ['executor' => self::class]);
            }

            return $result;
        });
    }

    /**
     * Start the mandatory or initial authenticator enrolment flow.
     *
     * @return array{secret: string, qr_code: string}
     */
    public function startEnrolment(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $result = (function () use ($user) {
                $secret = $this->generateSecretKey();
                $expiresAt = Carbon::now()->addMinutes(10);

                $user->forceFill([
                    'two_factor_pending_secret' => Fortify::currentEncrypter()->encrypt($secret),
                    'two_factor_pending_purpose' => 'enrolment',
                    'two_factor_pending_expires_at' => $expiresAt,
                    'two_factor_pending_last_used_timestep' => null,
                    'authenticator_state' => AuthenticatorState::PendingConfirmation,
                ])->save();

                ExpirePendingTwoFactorSetup::dispatch($user->id, $expiresAt->toISOString())
                    ->delay($expiresAt);

                return [
                    'secret' => $secret,
                    'qr_code' => $this->qrCodeSvg($user->email, $secret),
                ];

            })();
            AuditEvent::record('auth.mfa_changed', User::class, $user->id, null,
                ['changed_fields' => ['startEnrolment']], $user, ['executor' => self::class]);

            return $result;
        });
    }

    /**
     * Confirm authenticator enrolment with a valid 6-digit TOTP code.
     *
     * @return array<int, string> Plain recovery codes to display once
     */
    public function confirmEnrolment(User $user, string $code): array
    {
        return DB::transaction(function () use ($user, $code) {
            $result = (function () use ($user, $code) {
                if (! $user->two_factor_pending_secret || ! $user->two_factor_pending_expires_at) {
                    throw ValidationException::withMessages([
                        'code' => [__('No pending authenticator setup was found. Please restart enrolment.')],
                    ]);
                }

                if ($user->two_factor_pending_expires_at->isPast()) {
                    $user->forceFill([
                        'two_factor_pending_secret' => null,
                        'two_factor_pending_purpose' => null,
                        'two_factor_pending_expires_at' => null,
                        'two_factor_pending_last_used_timestep' => null,
                        'authenticator_state' => AuthenticatorState::NotConfigured,
                    ])->save();

                    throw ValidationException::withMessages([
                        'code' => [__('The authenticator enrolment session has expired. Please restart enrolment.')],
                    ]);
                }

                if (! $this->verifyTotp($user, $code, usePending: true)) {
                    throw ValidationException::withMessages([
                        'code' => [__('The provided two-factor authentication code was invalid or replayed.')],
                    ]);
                }

                $plainCodes = $this->generateRecoveryCodes(10);

                DB::transaction(function () use ($user, $plainCodes): void {
                    $user->recoveryCodes()->delete();

                    foreach ($plainCodes as $plainCode) {
                        UserRecoveryCode::create([
                            'user_id' => $user->id,
                            'code_hash' => hash('sha256', $plainCode),
                            'consumed_at' => null,
                        ]);
                    }

                    $user->forceFill([
                        'two_factor_secret' => $user->two_factor_pending_secret,
                        'two_factor_confirmed_at' => Carbon::now(),
                        'two_factor_last_used_timestep' => $user->two_factor_pending_last_used_timestep,
                        'two_factor_pending_secret' => null,
                        'two_factor_pending_purpose' => null,
                        'two_factor_pending_expires_at' => null,
                        'two_factor_pending_last_used_timestep' => null,
                        'authenticator_state' => AuthenticatorState::Active,
                        'recovery_codes_acknowledged_at' => null,
                    ])->save();
                });

                $user->notify((new AuthenticatorEnrolledNotification)->afterCommit());

                return $plainCodes;

            })();
            AuditEvent::record('auth.mfa_changed', User::class, $user->id, null,
                ['changed_fields' => ['confirmEnrolment']], $user, ['executor' => self::class]);

            return $result;
        });
    }

    /**
     * Acknowledge that recovery codes have been saved.
     */
    public function acknowledgeRecoveryCodes(User $user): void
    {
        DB::transaction(function () use ($user) {
            (function () use ($user) {
                $user->recovery_codes_acknowledged_at = Carbon::now();

                if ($user->account_state === AccountState::MfaSetupRequired) {
                    $user->account_state = AccountState::Active;
                }

                $user->save();

            })();
            AuditEvent::record('auth.mfa_changed', User::class, $user->id, null,
                ['changed_fields' => ['acknowledgeRecoveryCodes']], $user, ['executor' => self::class]);

        });
    }

    /**
     * Start the authenticator replacement flow after verifying current password and current TOTP.
     *
     * @return array{secret: string, qr_code: string}
     */
    public function startReplacement(User $user, string $currentPassword, string $currentTotp): array
    {
        return DB::transaction(function () use ($user, $currentPassword, $currentTotp) {
            $result = (function () use ($user, $currentPassword, $currentTotp) {
                $provider = $this->guard->getProvider();

                if (! $provider->validateCredentials($user, ['password' => $currentPassword])) {
                    throw ValidationException::withMessages([
                        'current_password' => [__('The provided password does not match our records.')],
                    ]);
                }

                if (! $this->verifyTotp($user, $currentTotp, usePending: false)) {
                    throw ValidationException::withMessages([
                        'current_code' => [__('The current authenticator code was invalid or replayed.')],
                    ]);
                }

                $secret = $this->generateSecretKey();
                $expiresAt = Carbon::now()->addMinutes(10);

                $user->forceFill([
                    'two_factor_pending_secret' => Fortify::currentEncrypter()->encrypt($secret),
                    'two_factor_pending_purpose' => 'replacement',
                    'two_factor_pending_expires_at' => $expiresAt,
                    'two_factor_pending_last_used_timestep' => null,
                    'authenticator_state' => AuthenticatorState::ReplacementPending,
                ])->save();

                $user->notify((new AuthenticatorReplacementStartedNotification)->afterCommit());

                ExpirePendingTwoFactorSetup::dispatch($user->id, $expiresAt->toISOString())
                    ->delay($expiresAt);

                return [
                    'secret' => $secret,
                    'qr_code' => $this->qrCodeSvg($user->email, $secret),
                ];

            })();
            AuditEvent::record('auth.mfa_changed', User::class, $user->id, null,
                ['changed_fields' => ['startReplacement']], $user, ['executor' => self::class]);

            return $result;
        });
    }

    /**
     * Confirm authenticator replacement with code from the NEW authenticator.
     *
     * @return array<int, string> Plain new recovery codes to display once
     */
    public function confirmReplacement(User $user, string $newCode, ?string $currentSessionId = null): array
    {
        return DB::transaction(function () use ($user, $newCode, $currentSessionId) {
            $result = (function () use ($user, $newCode, $currentSessionId) {
                if (! $user->two_factor_pending_secret || ! $user->two_factor_pending_expires_at) {
                    throw ValidationException::withMessages([
                        'code' => [__('No pending replacement was found. Please restart the replacement process.')],
                    ]);
                }

                if ($user->two_factor_pending_expires_at->isPast()) {
                    $user->forceFill([
                        'two_factor_pending_secret' => null,
                        'two_factor_pending_purpose' => null,
                        'two_factor_pending_expires_at' => null,
                        'two_factor_pending_last_used_timestep' => null,
                        'authenticator_state' => AuthenticatorState::Active,
                    ])->save();

                    throw ValidationException::withMessages([
                        'code' => [__('The replacement session has expired. Your current authenticator remains active.')],
                    ]);
                }

                if (! $this->verifyTotp($user, $newCode, usePending: true)) {
                    throw ValidationException::withMessages([
                        'code' => [__('The provided code from the new authenticator was invalid or replayed.')],
                    ]);
                }

                $plainCodes = $this->generateRecoveryCodes(10);

                DB::transaction(function () use ($user, $plainCodes, $currentSessionId): void {
                    // Delete all existing recovery codes
                    $user->recoveryCodes()->delete();

                    // Store new hashed recovery codes
                    foreach ($plainCodes as $plainCode) {
                        UserRecoveryCode::create([
                            'user_id' => $user->id,
                            'code_hash' => hash('sha256', $plainCode),
                            'consumed_at' => null,
                        ]);
                    }

                    // Atomically swap secrets and update state
                    $user->forceFill([
                        'two_factor_secret' => $user->two_factor_pending_secret,
                        'two_factor_confirmed_at' => Carbon::now(),
                        'two_factor_last_used_timestep' => $user->two_factor_pending_last_used_timestep,
                        'two_factor_pending_secret' => null,
                        'two_factor_pending_purpose' => null,
                        'two_factor_pending_expires_at' => null,
                        'two_factor_pending_last_used_timestep' => null,
                        'authenticator_state' => AuthenticatorState::Active,
                        'recovery_codes_acknowledged_at' => Carbon::now(),
                    ])->save();

                    // Rotate remember token
                    $user->setRememberToken(Str::random(60));
                    $user->save();

                    // Revoke every other session and trusted devices in the database
                    $sessionTable = config('session.table', 'sessions');
                    DB::table($sessionTable)
                        ->where('user_id', $user->id)
                        ->when($currentSessionId, fn ($q) => $q->where('id', '!=', $currentSessionId))
                        ->delete();

                    $user->revokeAllTrustedDevices();
                });

                $user->notify((new AuthenticatorReplacedNotification)->afterCommit());

                return $plainCodes;

            })();
            AuditEvent::record('auth.mfa_changed', User::class, $user->id, null,
                ['changed_fields' => ['confirmReplacement']], $user, ['executor' => self::class]);

            return $result;
        });
    }

    /**
     * Cancel an unconfirmed pending replacement or setup.
     */
    public function cancelPendingSetupOrReplacement(User $user): void
    {
        DB::transaction(function () use ($user) {
            (function () use ($user) {
                $wasReplacement = $user->authenticator_state === AuthenticatorState::ReplacementPending;

                $user->forceFill([
                    'two_factor_pending_secret' => null,
                    'two_factor_pending_purpose' => null,
                    'two_factor_pending_expires_at' => null,
                    'two_factor_pending_last_used_timestep' => null,
                    'authenticator_state' => $user->hasEnabledTwoFactorAuthentication()
                        ? AuthenticatorState::Active
                        : AuthenticatorState::NotConfigured,
                ])->save();

                if ($wasReplacement) {
                    $user->notify((new AuthenticatorReplacementCancelledNotification)->afterCommit());
                }

            })();
            AuditEvent::record('auth.mfa_changed', User::class, $user->id, null,
                ['changed_fields' => ['cancelPendingSetupOrReplacement']], $user, ['executor' => self::class]);

        });
    }

    /**
     * Regenerate 10 new recovery codes after fresh password and TOTP verification.
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user, string $password, string $totp): array
    {
        return DB::transaction(function () use ($user, $password, $totp) {
            $result = (function () use ($user, $password, $totp) {
                $provider = $this->guard->getProvider();

                if (! $provider->validateCredentials($user, ['password' => $password])) {
                    throw ValidationException::withMessages([
                        'password' => [__('The provided password does not match our records.')],
                    ]);
                }

                if (! $this->verifyTotp($user, $totp, usePending: false)) {
                    throw ValidationException::withMessages([
                        'code' => [__('The provided two-factor authentication code was invalid or replayed.')],
                    ]);
                }

                $plainCodes = $this->generateRecoveryCodes(10);

                DB::transaction(function () use ($user, $plainCodes): void {
                    $user->recoveryCodes()->delete();

                    foreach ($plainCodes as $plainCode) {
                        UserRecoveryCode::create([
                            'user_id' => $user->id,
                            'code_hash' => hash('sha256', $plainCode),
                            'consumed_at' => null,
                        ]);
                    }

                    $user->recovery_codes_acknowledged_at = Carbon::now();
                    $user->save();
                });

                $user->notify((new RecoveryCodesRegeneratedNotification)->afterCommit());

                return $plainCodes;

            })();
            AuditEvent::record('auth.recovery_codes_regenerated', User::class, $user->id, null,
                ['changed_fields' => ['regenerateRecoveryCodes']], $user, ['executor' => self::class]);

            return $result;
        });
    }
}
