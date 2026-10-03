<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Models\LedgerAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FinancialReleaseEvidenceService
{
    public const CAPABILITIES = ['collection_cash', 'withdrawal_cash', 'plan_creation', 'collections', 'payout_execution', 'reversal_posting', 'statement_pdf', 'report_exports', 'manual_charges', 'fee_refunds', 'cash_disbursements', 'retention_restore'];

    public const ROLES = ['finance_mapping', 'delegated_permissions', 'retention_key_custody', 'operations', 'acceptance', 'enablement'];

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
        $mappingUnavailable = LedgerAccount::query()->whereNotIn('code', ['business_bank_ngn', 'payment_clearing_ngn'])->where('mapping_status', '!=', 'mapped')->exists() || blank(config('app.financial_release_revision'));
        $methodUnavailable = false;
        try {
            app(CashMethodCatalogue::class)->version('withdrawal');
        } catch (ConflictHttpException) {
            $methodUnavailable = true;
        }
        $checks = [];
        foreach (self::CAPABILITIES as $capability) {
            $checks[$capability] = $this->evaluate($rows->get($capability, collect())->keyBy('owner_role')->all(), $hash, $mappingUnavailable, $methodUnavailable);
        }

        return $checks;
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

    /** @param array<string, \stdClass> $rows
     * @return array{state: string, owner: string, blocker: string, version: int}
     */
    private function evaluate(array $rows, string $hash, bool $mappingUnavailable, bool $methodUnavailable): array
    {
        $missing = [];
        $version = 1;
        foreach (self::ROLES as $role) {
            $evidence = $rows[$role] ?? null;
            if ($evidence === null || $evidence->state !== 'accepted' || ! $this->hasValidEvidence($evidence) || ! hash_equals($hash, $evidence->dependency_hash) || now()->parse($evidence->valid_until)->isPast()) {
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

        return ['state' => $missing === [] ? 'Ready to enable' : 'Unavailable', 'owner' => 'Versioned financial release owners',
            'blocker' => $missing === [] ? '' : 'Current owner evidence required: '.implode(', ', $missing).'.', 'version' => $version];
    }
}
