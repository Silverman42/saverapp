<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\BusinessConfigurationVersion;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only canonical invariant checks run before financial writes resume, against the live database or an isolated restore.
 * A failed live run blocks financial operations until a newer run passes; nothing here repairs or rewrites source data.
 */
class PlatformIntegrity
{
    /** @var array<string, string> check code => protected financial domain */
    public const CHECKS = [
        'ledger_balance' => 'ledger',
        'ledger_accounts' => 'ledger',
        'ledger_sources' => 'ledger',
        'collection_postings' => 'collections',
        'collection_amounts' => 'collections',
        'fee_postings' => 'fees',
        'reservations' => 'reservations',
        'audit_content' => 'audit',
        'configuration' => 'configuration',
        'projection_watermarks' => 'work',
        'recovery_leases' => 'work',
        'identity' => 'identity',
    ];

    public function __construct(private BusinessSettingsCatalogue $catalogue) {}

    /**
     * @return array{run_reference: string, scope: string, status: string, failed_domains: list<string>, results: array<string, array{domain: string, status: string, failures: int}>, digest: string}
     */
    public function verify(string $operator, ?string $connection = null): array
    {
        $db = DB::connection($connection);
        $scope = $connection === null ? 'live' : 'restored';
        $results = [];
        foreach (self::CHECKS as $code => $domain) {
            try {
                $failures = $this->{Str::camel($code)}($db);
                $results[$code] = ['domain' => $domain, 'status' => $failures === 0 ? 'passed' : 'failed', 'failures' => $failures];
            } catch (Throwable) {
                $results[$code] = ['domain' => $domain, 'status' => 'unverified', 'failures' => 0];
            }
        }
        $failedDomains = array_values(array_unique(array_map(fn (array $result): string => $result['domain'],
            array_filter($results, fn (array $result): bool => $result['status'] !== 'passed'))));
        $run = ['run_reference' => (string) Str::uuid(), 'scope' => $scope, 'status' => $failedDomains === [] ? 'passed' : 'failed',
            'failed_domains' => $failedDomains, 'results' => $results, 'digest' => AuditProjection::digest(['results' => $results])];
        DB::transaction(function () use ($run, $operator): void {
            DB::table('platform_integrity_runs')->insert(['run_reference' => $run['run_reference'], 'scope' => $run['scope'], 'status' => $run['status'],
                'failed_domains' => json_encode($run['failed_domains'], JSON_THROW_ON_ERROR), 'results' => json_encode($run['results'], JSON_THROW_ON_ERROR),
                'digest' => $run['digest'], 'operator' => mb_substr($operator, 0, 100), 'created_at' => now()]);
            AuditEvent::record('platform.integrity_verified', 'platform_integrity_run', null, $run['run_reference'],
                ['scope' => $run['scope'], 'status' => $run['status'], 'failed_domains' => $run['failed_domains'], 'digest' => $run['digest']],
                context: ['executor' => self::class, 'operation_id' => 'integrity:'.$run['run_reference'], 'severity' => $run['status'] === 'passed' ? 'Informational' : 'Critical',
                    'outcome' => $run['status'] === 'passed' ? 'Succeeded' : 'Failed']);
        });

        return $run;
    }

    /**
     * The latest live run's failed domains; empty when no live run has failed since the last pass.
     *
     * @return list<string>
     */
    public function blockedDomains(): array
    {
        $latest = DB::table('platform_integrity_runs')->where('scope', 'live')->orderByDesc('id')->first(['status', 'failed_domains']);

        return $latest === null || $latest->status === 'passed' ? [] : json_decode($latest->failed_domains, true, flags: JSON_THROW_ON_ERROR);
    }

    private function ledgerBalance(Connection $db): int
    {
        $unbalanced = $db->query()->fromSub($db->table('ledger_entries')->select('ledger_posting_group_id')->groupBy('ledger_posting_group_id')
            ->havingRaw("SUM(CASE WHEN side = 'debit' THEN amount_kobo ELSE 0 END) <> SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE 0 END)"), 'unbalanced')->count();
        $empty = $db->table('ledger_posting_groups as groups')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('ledger_entries')
            ->whereColumn('ledger_entries.ledger_posting_group_id', 'groups.id'))->count();

        return $unbalanced + $empty + $db->table('ledger_entries')->where('amount_kobo', '<=', 0)->count();
    }

    private function ledgerAccounts(Connection $db): int
    {
        return $db->table('ledger_entries as entries')->leftJoin('ledger_accounts as accounts', 'accounts.id', '=', 'entries.ledger_account_id')
            ->leftJoin('ledger_posting_groups as groups', 'groups.id', '=', 'entries.ledger_posting_group_id')
            ->where(fn ($query) => $query->whereNull('accounts.id')->orWhereNull('groups.id')->orWhereColumn('accounts.currency', '!=', 'groups.currency'))->count();
    }

    private function ledgerSources(Connection $db): int
    {
        return $db->table('ledger_posting_groups')->where(fn ($query) => $query->whereNull('source_type')->orWhereNull('source_id')
            ->orWhereNull('idempotency_key')->orWhereNull('payload_hash'))->count();
    }

    private function collectionPostings(Connection $db): int
    {
        return $db->table('collection_receipts as receipts')->whereNotNull('receipts.savings_posting_group_id')
            ->leftJoin('ledger_posting_groups as groups', 'groups.id', '=', 'receipts.savings_posting_group_id')->whereNull('groups.id')->count();
    }

    private function collectionAmounts(Connection $db): int
    {
        return $db->table('collection_receipts')->where(fn ($query) => $query->whereRaw('tender_amount_kobo <> savings_amount_kobo + fee_amount_kobo')
            ->orWhere('tender_amount_kobo', '<=', 0)->orWhere('savings_amount_kobo', '<', 0)->orWhere('fee_amount_kobo', '<', 0))->count();
    }

    private function feePostings(Connection $db): int
    {
        $orphaned = $db->table('fee_obligation_entries as entries')->whereNotNull('entries.ledger_posting_reference')
            ->leftJoin('ledger_posting_groups as groups', 'groups.posting_reference', '=', 'entries.ledger_posting_reference')->whereNull('groups.id')->count();
        $detached = $db->table('fee_obligation_entries as entries')->leftJoin('fee_obligations as obligations', 'obligations.id', '=', 'entries.fee_obligation_id')
            ->where(fn ($query) => $query->whereNull('obligations.id')->orWhereColumn('obligations.currency', '!=', 'entries.currency'))->count();

        return $orphaned + $detached + $db->table('fee_obligations')->where('amount_kobo', '<', 0)->count();
    }

    private function reservations(Connection $db): int
    {
        return $db->table('withdrawal_reservations as reservations')->leftJoin('customer_profiles as customers', 'customers.id', '=', 'reservations.customer_profile_id')
            ->where(fn ($query) => $query->whereNull('customers.id')->orWhere('reservations.gross_amount_kobo', '<=', 0))->count();
    }

    private function auditContent(Connection $db): int
    {
        $failures = 0;
        $db->table('canonical_audit_events')->select(['id', 'schema_version', 'content', 'content_hash'])->orderBy('id')
            ->chunkById(500, function ($events) use (&$failures): void {
                foreach ($events as $event) {
                    $content = json_decode((string) $event->content, true);
                    if ((int) $event->schema_version !== 1 || ! is_array($content) || ! hash_equals((string) $event->content_hash, AuditProjection::digest($content))) {
                        $failures++;
                    }
                }
            });

        return $failures;
    }

    private function configuration(Connection $db): int
    {
        $profiles = $db->table('business_profiles')->get(['id', 'effective_configuration_id']);
        if ($profiles->count() !== 1) {
            return max(1, $profiles->count());
        }
        $effective = $profiles->first()->effective_configuration_id;
        if ($effective === null) {
            return 0;
        }
        $version = (new BusinessConfigurationVersion)->setConnection($db->getName())->newQuery()->whereKey($effective)->first();

        return $version !== null && is_array($version->values) && hash_equals($version->values_hash, $this->catalogue->hash($version->values)) ? 0 : 1;
    }

    private function projectionWatermarks(Connection $db): int
    {
        $ledger = $db->table('ledger_projection_state')->where('id', 1)->value('ledger_group_watermark');
        $audit = $db->table('audit_projection_state')->where('id', 1)->value('watermark');

        return (int) ($ledger !== null && (int) $ledger > (int) $db->table('ledger_posting_groups')->max('id'))
            + (int) ($audit !== null && (int) $audit > (int) $db->table('canonical_audit_events')->max('id'));
    }

    private function recoveryLeases(Connection $db): int
    {
        return $db->table('platform_recovery_work')->where('state', 'running')->whereNull('lease_owner')->count();
    }

    private function identity(Connection $db): int
    {
        $duplicates = $db->query()->fromSub($db->table('users')->select('email_normalized')->whereNotNull('email_normalized')
            ->groupBy('email_normalized')->havingRaw('COUNT(*) > 1'), 'duplicates')->count();
        $orphanedCustomers = $db->table('customer_profiles as customers')->whereNotNull('customers.user_id')
            ->leftJoin('users', 'users.id', '=', 'customers.user_id')->where(fn ($query) => $query->whereNull('users.id')->orWhere('users.user_type', '!=', 'customer'))->count();

        return $duplicates + $orphanedCustomers;
    }
}
