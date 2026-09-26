<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AuthenticatorState;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Support\IdentityNormalizer;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BusinessBootstrap
{
    public function __construct(private RoleSynchronizationService $roles, private BusinessSettings $settings, private BusinessSettingsCatalogue $catalogue) {}

    public function provision(string $displayName, string $name, string $email, #[\SensitiveParameter] string $password): User
    {
        $email = IdentityNormalizer::normalizeEmail($email);
        $data = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:150', 'not_regex:/[\p{C}<>]/u'],
            'email' => ['required', 'email', 'max:254'], 'password' => ['required', PasswordPolicy::ruleForUserType(UserType::Admin)],
        ])->validate();

        return DB::transaction(function () use ($displayName, $data): User {
            $profile = BusinessProfile::query()->lockForUpdate()->sole();
            if (User::query()->exists() || $profile->getAttribute('effective_configuration_id') !== null) {
                throw new ConflictHttpException('Bootstrap is available only before the first user and configuration import. Existing accounts and grants are never replaced.');
            }
            $patch = $this->catalogue->validatePatch(['display_name' => $displayName], $this->catalogue->initialValues($profile));
            $profile->update($patch);
            $admin = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                'user_type' => UserType::Admin, 'account_state' => AccountState::Active, 'permission_version' => 1, 'authenticator_state' => AuthenticatorState::NotConfigured]);
            $this->roles->bootstrapAdmin($admin);
            $admin->update(['account_state' => AccountState::MfaSetupRequired]);
            $this->settings->import();
            AuditEvent::record('business_settings.bootstrap', BusinessProfile::class, $profile->id, $profile->business_id,
                ['version' => $profile->version], null, ['executor' => self::class]);

            return $admin;
        }, attempts: 3);
    }
}
