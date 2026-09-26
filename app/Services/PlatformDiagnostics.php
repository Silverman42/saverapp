<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PlatformDiagnostics
{
    public function __construct(private PlatformState $state, private BusinessSettingsReadiness $readiness) {}

    public function heartbeat(string $component): void
    {
        if (! in_array($component, ['scheduler', 'worker'], true)) {
            throw new \InvalidArgumentException('Unknown platform heartbeat.');
        }
        try {
            DB::table('platform_heartbeats')->updateOrInsert(['component' => $component], ['observed_at' => now()->utc()]);
        } catch (Throwable) {
            // Diagnostics never authorize work or hide a domain failure.
        }
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $checks = [];
        $checks['database'] = $this->inspect(function (): array {
            DB::select('SELECT 1');

            return ['state' => 'Ready'];
        });
        $checks['schema'] = $this->inspect(function (): array {
            $tables = ['platform_state', 'platform_transitions', 'platform_operations', 'platform_heartbeats', 'canonical_audit_events',
                'audit_projection_work', 'audit_projection_state', 'ledger_posting_groups', 'ledger_projection_state',
                'notification_inbox_intents', 'business_configuration_work', 'business_configuration_versions', 'jobs', 'failed_jobs'];
            $missing = array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));

            return ['state' => $missing === [] ? 'Ready' : 'Unavailable', 'missing' => $missing];
        });
        foreach (['scheduler', 'worker'] as $component) {
            $checks[$component] = $this->inspect(function () use ($component): array {
                $observed = DB::table('platform_heartbeats')->where('component', $component)->value('observed_at');
                if ($observed === null) {
                    return ['state' => 'Unknown', 'observed_at' => null];
                }
                $time = CarbonImmutable::parse($observed)->utc();
                $age = (int) $time->diffInSeconds(now()->utc(), false);

                return ['state' => $age < 0 ? 'Unverified' : ($age > 300 ? 'Stale' : 'Ready'), 'observed_at' => $time->toIso8601String(), 'age_seconds' => max(0, $age)];
            });
        }
        $checks['pending_work'] = $this->inspect(function (): array {
            $audit = DB::table('audit_projection_work')->where('status', 'pending');
            $notifications = DB::table('notification_inbox_intents')->where('status', 'pending');
            $configuration = DB::table('business_configuration_work')->whereIn('status', ['scheduled', 'propagation_pending', 'blocked']);
            $jobs = DB::table('jobs');
            $queueOldest = $jobs->min('created_at');
            $oldest = collect([$audit->min('updated_at'), $notifications->min('created_at'), $configuration->min('updated_at'),
                $queueOldest === null ? null : CarbonImmutable::createFromTimestampUTC((int) $queueOldest)->toDateTimeString()])->filter()->min();

            return ['state' => 'Observed', 'audit_pending' => $audit->count(), 'notification_pending' => $notifications->count(),
                'configuration_pending' => $configuration->count(), 'queued_jobs' => $jobs->count(),
                'oldest_age_seconds' => $oldest === null ? null : max(0, (int) CarbonImmutable::parse($oldest)->diffInSeconds(now(), false)),
                'failed_jobs' => DB::table('failed_jobs')->count()];
        });
        $checks['projections'] = $this->inspect(function (): array {
            $audit = DB::table('audit_projection_state')->where('id', 1)->first();
            $ledger = DB::table('ledger_projection_state')->where('id', 1)->first();

            return ['state' => $audit === null || $ledger === null ? 'Unknown' : 'Observed',
                'audit_status' => $audit->status ?? 'unknown', 'ledger_status' => $ledger->status ?? 'unknown',
                'audit_lag_events' => $audit === null ? null : DB::table('canonical_audit_events')->where('id', '>', $audit->watermark)->count(),
                'ledger_lag_groups' => $ledger === null ? null : DB::table('ledger_posting_groups')->where('id', '>', $ledger->ledger_group_watermark)->count()];
        });
        foreach (['provider', 'backup', 'key_custody', 'clock_synchronization', 'restore', 'external_fencing'] as $dependency) {
            $checks[$dependency] = ['state' => 'Unverified'];
        }

        return ['catalogue_version' => PlatformCatalogue::VERSION, 'platform' => $this->state->publicStatus(), 'checks' => $checks,
            'owner_readiness' => $this->readiness->checks(), 'production_certified' => false];
    }

    /** @param callable(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function inspect(callable $callback): array
    {
        try {
            return $callback();
        } catch (Throwable) {
            return ['state' => 'Unavailable'];
        }
    }

    public function record(string $kind, string $serviceClass, int $started, string $outcome, ?string $correlation = null): void
    {
        try {
            Log::info('platform.operation', ['kind' => $kind, 'service_class' => $serviceClass,
                'duration_ms' => max(0, (hrtime(true) - $started) / 1_000_000),
                'outcome' => $outcome, 'correlation_reference' => $correlation ?? Context::get('correlation_reference') ?? (string) Str::uuid()]);
        } catch (Throwable) {
            // Telemetry is separate from canonical audit and domain durability.
        }
    }
}
