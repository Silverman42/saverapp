<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\PayoutProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FinancialReleaseEvidenceService
{
    public const CAPABILITIES = ['collection_cash', 'withdrawal_cash', 'plan_creation', 'collections', 'payout_execution', 'reversal_posting', 'statement_pdf', 'report_exports', 'manual_charges', 'fee_refunds', 'cash_disbursements', 'retention_restore', 'collection_transfer', 'collection_pos', 'collection_other', 'timezone', 'withdrawal_transfer', 'customer_registration', 'transactional_email', 'emergency_recovery'];

    public const ROLES = ['finance_mapping', 'delegated_permissions', 'retention_key_custody', 'operations', 'acceptance', 'enablement'];

    /** Ledger accounts only the non-cash collection capabilities depend on. */
    public const NONCASH_LEDGER_ACCOUNTS = ['business_bank_ngn', 'payment_clearing_ngn'];

    public function dependencyHash(): string
    {
        return AuditProjection::digest([
            'schema' => 1,
            'migrations' => DB::table('migrations')->orderBy('migration')->pluck('migration')->all(),
            'mappings' => LedgerAccount::query()->orderBy('code')->get(['code', 'account_class', 'normal_balance', 'currency', 'mapping_status', 'version'])->toArray(),
            'methods' => DB::table('cash_method_versions')->orderBy('id')->get()->toArray(),
            'collection_methods' => Schema::hasTable('collection_method_versions') ? DB::table('collection_method_versions')->orderBy('id')->get()->toArray() : [],
            'implementation' => config('app.financial_release_revision'),
        ]);
    }

    /** @param array{capability: string, owner_role: string, version: int, state: string, dependency_hash: string, valid_until: string, evidence: string} $data */
    public function record(User $actor, array $data): int
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $data): int {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::BusinessSettingsManage), 403);
            if (! in_array($data['capability'], self::CAPABILITIES, true) || ! in_array($data['owner_role'], self::ROLES, true)
                || ! in_array($data['state'], ['accepted', 'blocked', 'revoked'], true) || $data['version'] < 1 || trim($data['evidence']) === ''
                || ! hash_equals($this->dependencyHash(), $data['dependency_hash']) || now()->parse($data['valid_until'])->isPast()) {
                throw new ConflictHttpException('Evidence must identify a current release, accountable owner and future validity boundary.');
            }
            $data['valid_until'] = CarbonImmutable::parse($data['valid_until'])->utc()->toDateTimeString();
            $hash = AuditProjection::digest($data);
            $existing = DB::table('financial_release_evidence')->where('capability', $data['capability'])->where('owner_role', $data['owner_role'])->where('version', $data['version'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->evidence_hash, $hash)) {
                    throw new ConflictHttpException('Owner evidence versions are immutable.');
                }

                return $existing->id;
            }
            $latest = DB::table('financial_release_evidence')->where('capability', $data['capability'])->where('owner_role', $data['owner_role'])->max('version');
            if ($data['version'] !== (int) $latest + 1) {
                throw new ConflictHttpException('Record the next owner evidence version.');
            }

            return DB::table('financial_release_evidence')->insertGetId([
                ...array_diff_key($data, ['evidence' => true]), 'evidence_hash' => $hash,
                'evidence' => Crypt::encryptString($data['evidence']), 'recorded_by_user_id' => $actor->id, 'created_at' => now(),
            ]);
        }, attempts: 3);
    }

    /**
     * Ledger account codes that are not yet mapped, optionally including the non-cash collection accounts.
     *
     * @return list<string>
     */
    public function unmappedLedgerAccounts(bool $includeNoncash = false): array
    {
        return LedgerAccount::query()->where('mapping_status', '!=', 'mapped')
            ->when(! $includeNoncash, fn ($query) => $query->whereNotIn('code', self::NONCASH_LEDGER_ACCOUNTS))
            ->orderBy('code')->get()->map(fn (LedgerAccount $account): string => $account->code->value)->all();
    }

    /**
     * Mark the given ledger accounts as mapped on behalf of an authorized actor.
     *
     * @param  list<string>  $codes
     */
    public function mapLedgerAccounts(User $actor, array $codes): int
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $codes): int {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::BusinessSettingsManage), 403);
            $accounts = LedgerAccount::query()->whereIn('code', $codes)->where('mapping_status', '!=', 'mapped')->lockForUpdate()->get();
            $accounts->each(fn (LedgerAccount $account) => $account->update(['mapping_status' => 'mapped']));

            return $accounts->count();
        }, attempts: 3);
    }

    /** Whether the latest evidence for a capability and owner role is accepted, intact, unexpired and bound to the current release. */
    public function hasCurrentEvidence(string $capability, string $role): bool
    {
        $latest = DB::table('financial_release_evidence')->where('capability', $capability)->where('owner_role', $role)->orderByDesc('version')->first();

        return $latest !== null && $this->isCurrent($latest, $this->dependencyHash());
    }

    public function nextVersion(string $capability, string $role): int
    {
        return (int) DB::table('financial_release_evidence')->where('capability', $capability)->where('owner_role', $role)->max('version') + 1;
    }

    /** @return array{state: string, owner: string, blocker: string, version: int} */
    public function check(string $capability): array
    {
        return $this->checks()[$capability];
    }

    /** @return array<string, array{state: string, owner: string, blocker: string, version: int}> */
    public function checks(): array
    {
        $rows = Schema::hasTable('financial_release_evidence') ? DB::table('financial_release_evidence')->orderBy('version')->get()->groupBy('capability') : collect();
        $hash = $this->dependencyHash();
        $mappingUnavailable = LedgerAccount::query()->whereNotIn('code', self::NONCASH_LEDGER_ACCOUNTS)->where('mapping_status', '!=', 'mapped')->exists() || blank(config('app.financial_release_revision'));
        $methodUnavailable = false;
        try {
            app(CashMethodCatalogue::class)->version('withdrawal');
        } catch (ConflictHttpException) {
            $methodUnavailable = true;
        }
        $checks = [];
        foreach (self::CAPABILITIES as $capability) {
            $checks[$capability] = $this->evaluate($rows->get($capability, collect())->keyBy('owner_role')->all(), $hash, $mappingUnavailable, $methodUnavailable, $this->methodBlockers($capability));
        }

        return $checks;
    }

    /**
     * Method-specific prerequisites: custody, scanning and switch for non-cash collections, and a configured provider for bank payouts.
     *
     * @return list<string>
     */
    private function methodBlockers(string $capability): array
    {
        if ($capability === 'withdrawal_transfer') {
            $bankAvailable = config('withdrawals.bank_enabled') === true && config('withdrawals.bank_certified') === true
                && app(PayoutProvider::class)->key() !== 'unavailable';

            return $bankAvailable ? [] : ['enabled and certified bank payout provider'];
        }
        $required = match ($capability) {
            'collection_transfer' => ['business_bank_ngn'],
            'collection_pos' => ['payment_clearing_ngn', 'business_bank_ngn'],
            'collection_other' => [],
            default => null,
        };
        if ($required === null) {
            return [];
        }
        $blockers = [];
        $mapped = LedgerAccount::query()->whereIn('code', $required)->where('mapping_status', 'mapped')->count();
        if ($mapped !== count($required)) {
            $blockers[] = 'verified '.implode(' and ', $required).' mapping';
        }
        if (config('collections.noncash_enabled') !== true) {
            $blockers[] = 'non-cash collection switch';
        }
        if (! app(CollectionEvidenceScanner::class)->isConfigured()) {
            $blockers[] = 'evidence scanner';
        }

        return $blockers;
    }

    private function hasValidEvidence(\stdClass $row): bool
    {
        try {
            $evidence = Crypt::decryptString($row->evidence);
        } catch (DecryptException) {
            return false;
        }

        return trim($evidence) !== '' && hash_equals($row->evidence_hash, AuditProjection::digest([
            'capability' => $row->capability, 'owner_role' => $row->owner_role, 'version' => (int) $row->version,
            'state' => $row->state, 'dependency_hash' => $row->dependency_hash,
            'valid_until' => CarbonImmutable::parse($row->valid_until)->utc()->toDateTimeString(), 'evidence' => $evidence,
        ]));
    }

    private function isCurrent(\stdClass $row, string $hash): bool
    {
        return $row->state === 'accepted' && $this->hasValidEvidence($row) && hash_equals($hash, $row->dependency_hash) && ! now()->parse($row->valid_until)->isPast();
    }

    /** @param array<string, \stdClass> $rows
     * @param  list<string>  $additionalBlockers
     * @return array{state: string, owner: string, blocker: string, version: int}
     */
    private function evaluate(array $rows, string $hash, bool $mappingUnavailable, bool $methodUnavailable, array $additionalBlockers = []): array
    {
        $missing = [];
        $version = 1;
        foreach (self::ROLES as $role) {
            $evidence = $rows[$role] ?? null;
            if ($evidence === null || ! $this->isCurrent($evidence, $hash)) {
                $missing[] = $role;
            } else {
                $version = max($version, $evidence->version);
            }
        }
        if ($mappingUnavailable) {
            $missing[] = 'versioned implementation and verified mappings';
        }
        if ($methodUnavailable) {
            $missing[] = 'accepted immutable cash method';
        }
        array_push($missing, ...$additionalBlockers);

        return ['state' => $missing === [] ? 'Ready to enable' : 'Unavailable', 'owner' => 'Versioned financial release owners',
            'blocker' => $missing === [] ? '' : 'Current owner evidence required: '.implode(', ', $missing).'.', 'version' => $version];
    }
}
