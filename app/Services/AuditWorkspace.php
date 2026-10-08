<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class AuditWorkspace
{
    public function __construct(private AuthorizationService $authorization) {}

    public function scope(User $viewer, AdminPermission $permission = AdminPermission::AuditView): string
    {
        $viewer = $viewer->fresh();
        abort_unless(config('audit.enabled') && $this->authorization->allows($viewer, $permission), 403);

        return hash('sha256', json_encode([$viewer->id, $viewer->permission_version, $permission->value], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function search(User $viewer, array $filters): array
    {
        $scope = $this->scope($viewer);
        $timezone = 'UTC';
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc() : CarbonImmutable::now()->utc()->subDay();
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()->utc() : CarbonImmutable::now()->utc();
        if ($from->greaterThan($to) || $from->startOfDay()->diffInDays($to->startOfDay()) + 1 > 366 || $to->greaterThan(CarbonImmutable::now()->utc()->endOfDay())) {
            throw ValidationException::withMessages(['from' => 'Choose an audit range of at most 366 days ending no later than today.']);
        }
        $perPage = (int) ($filters['per_page'] ?? 25);
        $queryFilters = array_map('strval', array_filter(array_diff_key($filters, ['cursor' => true]), fn ($value) => $value !== null && $value !== ''));
        $queryFilters['per_page'] = (string) $perPage;
        ksort($queryFilters);
        $filterHash = hash('sha256', json_encode($queryFilters, JSON_THROW_ON_ERROR));
        $state = DB::table('audit_projection_state')->where('id', 1)->firstOrFail();
        $watermark = (int) $state->watermark;
        $position = null;
        if (isset($filters['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($filters['cursor']), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new ConflictHttpException('The audit cursor is invalid. Start a new search.');
            }
            if (($cursor['scope'] ?? null) !== $scope || ($cursor['filters'] ?? null) !== $filterHash
                || ! is_int($cursor['watermark'] ?? null) || $cursor['watermark'] > (int) $state->watermark || ($cursor['version'] ?? null) !== (int) $state->active_version || ($cursor['expires'] ?? 0) < now()->timestamp) {
                throw new ConflictHttpException('Audit access or projection changed. Start a new search.');
            }
            $watermark = (int) $cursor['watermark'];
            $from = CarbonImmutable::parse($cursor['from']);
            $to = CarbonImmutable::parse($cursor['to']);
            $position = $cursor['position'];
        }
        $query = DB::table('audit_search_documents')->where('index_version', $state->active_version)->where('canonical_event_id', '<=', $watermark)
            ->whereBetween('recorded_at', [$from, $to]);
        foreach (['event_id', 'event_type', 'category', 'outcome', 'severity', 'actor_id', 'actor_type', 'target_type', 'target_reference', 'source_module', 'required_permission', 'correlation_reference', 'retention_class'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        if ($position !== null) {
            $query->where(fn ($q) => $q->where('recorded_at', '<', $position['time'])->orWhere(fn ($tie) => $tie->where('recorded_at', $position['time'])->where('event_id', '<', $position['event_id'])));
        }
        $rows = $query->orderByDesc('recorded_at')->orderByDesc('event_id')->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage);
        $next = null;
        if ($hasMore) {
            $last = $rows->last();
            $next = Crypt::encryptString(json_encode(['scope' => $scope, 'filters' => $filterHash, 'version' => (int) $state->active_version,
                'watermark' => $watermark, 'from' => $from->toISOString(), 'to' => $to->toISOString(),
                'position' => ['time' => $last->recorded_at, 'event_id' => $last->event_id], 'expires' => now()->addMinutes(30)->timestamp], JSON_THROW_ON_ERROR));
        }
        $pending = DB::table('audit_projection_work')->where('status', '!=', 'complete')->count();

        return ['rows' => $rows->map(fn (stdClass $event): array => $this->summary($event))->values()->all(), 'next_cursor' => $next,
            'scope' => $scope, 'health' => ['status' => $pending === 0 ? $state->status : 'partial', 'watermark' => $watermark,
                'version' => (int) $state->active_version, 'pending' => $pending, 'as_of' => $state->updated_at,
                'integrity' => 'Unverified', 'retention' => 'Policy approval pending'], 'range' => ['from' => $from->toISOString(), 'to' => $to->toISOString()]];
    }

    /** @return array<string, mixed> */
    public function detail(User $viewer, string $reference): array
    {
        $scope = $this->scope($viewer);
        $event = DB::table('canonical_audit_events')->where('event_id', $reference)->firstOrFail();
        $content = json_decode($event->content, true, flags: JSON_THROW_ON_ERROR);
        $verified = hash_equals($event->content_hash, AuditProjection::digest($content));
        AuditEvent::record('audit.detail_viewed', 'canonical_audit_event', (int) $event->id, $event->event_id,
            ['event_id' => $event->event_id], $viewer->fresh(), ['required_permission' => AdminPermission::AuditView->value, 'executor' => self::class, 'outcome' => $verified ? 'Succeeded' : 'Failed']);
        if (! $verified) {
            app(AuditProjection::class)->verificationFailure($event->event_id);
            throw new ConflictHttpException('Audit content check failed. Evidence is unavailable.');
        }
        $related = $event->correlation_reference === null ? [] : DB::table('canonical_audit_events')->where('correlation_reference', $event->correlation_reference)
            ->orderBy('id')->limit(25)->get()->map(fn ($row): array => $this->summary($row))->all();

        return ['scope' => $scope, 'summary' => $this->summary($event), 'content' => $content, 'related' => $related,
            'content_check' => 'Matched; cryptographic integrity remains unverified', 'owner_link' => $this->ownerLink($event)];
    }

    /** @return array<string, mixed> */
    private function summary(stdClass $event): array
    {
        return ['event_id' => $event->event_id, 'event_type' => $event->event_type, 'category' => $event->category,
            'severity' => $event->severity, 'outcome' => $event->outcome, 'actor_id' => $event->actor_id,
            'actor_type' => $event->actor_type, 'target_reference' => $event->target_reference,
            'recorded_at' => $event->recorded_at, 'legacy_evidence' => (bool) $event->legacy_evidence];
    }

    private function ownerLink(stdClass $event): ?string
    {
        $route = match ($event->target_type) {
            CustomerProfile::class, 'customer' => 'customers.show',
            AgentProfile::class, 'agent' => 'agents.show',
            ThriftPlan::class => 'plans.show',
            CollectionReceipt::class => 'collections.show',
            WithdrawalRequest::class => 'withdrawals.show',
            ReversalRequest::class => 'reversals.show', default => null,
        };

        return $route !== null && $event->target_reference !== null ? route($route, [$event->target_reference], false) : null;
    }
}
