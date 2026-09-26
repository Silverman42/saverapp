<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Models\BusinessProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

class NotificationInbox
{
    public function __construct(private NotificationPipeline $pipeline, private AuthorizationService $authorization) {}

    public function enabled(User $user): bool
    {
        return (bool) config('notifications.enabled') && $user->account_state === AccountState::Active;
    }

    public function scopeFingerprint(User $user): string
    {
        $assignments = DB::table('customer_assignments as a')->join('agent_profiles as ap', 'ap.id', '=', 'a.agent_profile_id')
            ->join('customer_profiles as cp', 'cp.id', '=', 'a.customer_profile_id')->where('a.is_current', 1)
            ->where(function (Builder $scope) use ($user): void {
                $scope->where('ap.user_id', $user->id)->orWhere('cp.user_id', $user->id);
            })->orderBy('a.id')->get(['a.id', 'a.customer_profile_id', 'a.agent_profile_id', 'ap.operational_status', 'cp.operational_status']);

        return hash('sha256', json_encode([
            $user->id, $user->user_type->value, $user->account_state->value, $user->permission_version,
            $user->getRoleNames()->all(), $user->hasConfirmedTwoFactor(), $this->authorization->effectivePermissionCodes($user),
            $assignments->all(), DB::table('customer_name_corrections as cc')->join('customer_profiles as cp', 'cp.id', '=', 'cc.customer_profile_id')->where('cp.user_id', $user->id)->orderBy('cc.id')->get(['cc.id', 'cc.status', 'cc.expires_at'])->all(), BusinessProfile::current()->version, NotificationCatalogue::VERSION,
        ], JSON_THROW_ON_ERROR));
    }

    private function visible(User $user): Builder
    {
        abort_unless($this->enabled($user), 404);

        return $this->pipeline->recipientScope($user)->join('notifications as n', 'n.id', '=', 'i.notification_id')
            ->where('n.notifiable_type', $user->getMorphClass())->where('n.notifiable_id', $user->id)
            ->leftJoin('customer_name_corrections as cc', 'cc.id', '=', 'i.action_correction_id')
            ->where('i.status', 'delivered')->where('i.expires_at', '>', now());
    }

    /** @return array{status: string, scope: string, unread_count: int, as_of: string} */
    public function sync(User $user): array
    {
        return ['status' => 'current', 'scope' => $this->scopeFingerprint($user),
            'unread_count' => $this->visible($user)->whereNull('n.read_at')->count(), 'as_of' => now()->toIso8601String()];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function read(User $user, array $filters): array
    {
        return DB::transaction(function () use ($user, $filters): array {
            $scope = $this->scopeFingerprint($user);
            $normalized = $filters;
            unset($normalized['cursor']);
            ksort($normalized);
            $binding = hash('sha256', json_encode([$user->id, $scope, $normalized, NotificationCatalogue::VERSION], JSON_THROW_ON_ERROR));
            $cursor = isset($filters['cursor']) ? $this->decode($filters['cursor'], $binding) : null;
            $cutoff = $cursor['cutoff'] ?? now()->toIso8601String();
            $query = $this->visible($user)->where('i.created_at', '<=', CarbonImmutable::parse($cutoff));
            if (($filters['read'] ?? 'all') === 'unread') {
                $query->whereNull('n.read_at');
            }
            if (isset($filters['category'])) {
                $query->where('i.category', $filters['category']);
            }
            if (isset($filters['action_required'])) {
                if (filter_var($filters['action_required'], FILTER_VALIDATE_BOOLEAN)) {
                    $query->where('cc.status', 'pending')->where('cc.expires_at', '>', now());
                } else {
                    $query->where(fn (Builder $action) => $action->whereNull('cc.id')->orWhere('cc.status', '!=', 'pending')->orWhere('cc.expires_at', '<=', now()));
                }
            }
            if (isset($filters['from'])) {
                $timezone = BusinessProfile::current()->timezone;
                $query->where('i.effective_at', '>=', CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc())
                    ->where('i.effective_at', '<', CarbonImmutable::parse($filters['to'], $timezone)->addDay()->startOfDay()->utc());
            }

            if (($filters['status'] ?? 'current') === 'expired') {
                $query->where(function (Builder $expired): void {
                    $expired->where('cc.status', 'expired')->orWhere(fn (Builder $pending) => $pending->where('cc.status', 'pending')->where('cc.expires_at', '<=', now()));
                });
            } elseif (($filters['status'] ?? 'current') === 'superseded') {
                $query->whereIn('cc.status', ['replaced', 'invalidated']);
            } else {
                $query->where(fn (Builder $current) => $current->whereNull('cc.id')->orWhereIn('cc.status', ['accepted', 'rejected', 'cancelled'])
                    ->orWhere(fn (Builder $pending) => $pending->where('cc.status', 'pending')->where('cc.expires_at', '>', now())));
            }

            if (filled($filters['search'] ?? null)) {
                $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
                $query->where(function (Builder $searchQuery) use ($search): void {
                    foreach (['i.title', 'i.summary', 'i.reference'] as $index => $column) {
                        if ($index === 0) {
                            $searchQuery->whereRaw($column." LIKE ? ESCAPE '!'", [$search]);
                        } else {
                            $searchQuery->orWhereRaw($column." LIKE ? ESCAPE '!'", [$search]);
                        }
                    }
                });
            }
            if ($cursor !== null) {
                $query->where(function (Builder $after) use ($cursor): void {
                    $after->where('i.effective_at', '<', $cursor['time'])->orWhere(function (Builder $tie) use ($cursor): void {
                        $tie->where('i.effective_at', $cursor['time'])->where('i.notification_id', '<', $cursor['id']);
                    });
                });
            }
            $size = (int) ($filters['page_size'] ?? 25);
            $rows = $query->orderByDesc('i.effective_at')->orderByDesc('i.notification_id')->limit($size + 1)
                ->get(['i.*', 'n.read_at', 'n.read_version', 'cc.status as correction_status', 'cc.expires_at as action_expires_at']);
            $hasMore = $rows->count() > $size;
            $rows = $rows->take($size);
            $last = $rows->last();
            $pageToken = Crypt::encryptString(json_encode(['binding' => $scope, 'user' => $user->id,
                'expires' => now()->addMinutes(15)->timestamp,
                'items' => $rows->map(fn (stdClass $row): array => ['id' => $row->notification_id, 'version' => (int) $row->read_version])->all()], JSON_THROW_ON_ERROR));

            return ['status' => 'current', 'scope' => $scope, 'as_of' => $cutoff,
                'items' => $rows->map(fn (stdClass $row): array => $this->serialize($row))->values()->all(),
                'unread_count' => $this->visible($user)->where('i.created_at', '<=', CarbonImmutable::parse($cutoff))->whereNull('n.read_at')->count(),
                'next_cursor' => $hasMore && $last !== null ? Crypt::encryptString(json_encode(['binding' => $binding,
                    'cutoff' => $cutoff, 'time' => $last->effective_at, 'id' => $last->notification_id], JSON_THROW_ON_ERROR)) : null,
                'page_token' => $pageToken];
        });
    }

    /** @return array<string, mixed> */
    public function detail(User $user, string $id): array
    {
        $row = $this->visible($user)->where('i.notification_id', $id)->first(['i.*', 'n.read_at', 'n.read_version', 'cc.status as correction_status', 'cc.expires_at as action_expires_at']);
        abort_if($row === null, 404);

        return $this->serialize($row);
    }

    /** @return array{id: string, read: bool, version: int} */
    public function mark(User $user, string $id, bool $read, int $version): array
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($user, $id, $read, $version): array {
            $row = $this->visible($user)->where('i.notification_id', $id)->lockForUpdate()->first(['n.id', 'n.read_at', 'n.read_version']);
            abort_if($row === null, 404);
            if (($row->read_at !== null) === $read) {
                return ['id' => $id, 'read' => $read, 'version' => (int) $row->read_version];
            }
            abort_if((int) $row->read_version !== $version, 409, 'Read state changed. Refresh before retrying.');
            DB::table('notifications')->where('id', $id)->update(['read_at' => $read ? now() : null, 'read_version' => $version + 1, 'updated_at' => now()]);

            return ['id' => $id, 'read' => $read, 'version' => $version + 1];
        });
    }

    public function markPage(User $user, string $token): void
    {
        $page = $this->decode($token, $this->scopeFingerprint($user));
        if (($page['user'] ?? null) !== $user->id || ($page['expires'] ?? 0) < now()->timestamp
            || ! is_array($page['items'] ?? null) || count($page['items']) > 100) {
            throw ValidationException::withMessages(['page_token' => 'This page changed. Refresh before retrying.']);
        }
        app(PlatformGuard::class)->transaction('mutation', function () use ($user, $page): void {
            foreach ($page['items'] as $item) {
                $this->mark($user, $item['id'], true, $item['version']);
            }
        });
    }

    public function destination(User $user, string $id): string
    {
        $row = $this->visible($user)->where('i.notification_id', $id)->first(['i.destination']);
        abort_if($row === null || $row->destination === null, 404);
        $destination = json_decode($row->destination, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(in_array($destination['route'] ?? '', ['customers.show', 'agents.show', 'plans.show', 'collections.show', 'withdrawals.show', 'reversals.show', 'admin.security.show'], true), 404);

        return route($destination['route'], $destination['parameters'], false);
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return ['id' => $row->notification_id, 'title' => $row->title, 'summary' => $row->summary, 'reference' => $row->reference,
            'category' => $row->category, 'importance' => $row->importance, 'mandatory' => (bool) $row->mandatory,
            'action_required' => $row->correction_status === 'pending' && CarbonImmutable::parse($row->action_expires_at)->isFuture(),
            'visibility' => in_array($row->correction_status, ['replaced', 'invalidated'], true) ? 'superseded' : ($row->correction_status === 'expired' || ($row->correction_status === 'pending' && CarbonImmutable::parse($row->action_expires_at)->isPast()) ? 'expired' : 'current'),
            'effective_at' => CarbonImmutable::parse($row->effective_at, 'UTC')->toIso8601String(),
            'read_at' => $row->read_at === null ? null : CarbonImmutable::parse($row->read_at, 'UTC')->toIso8601String(),
            'read_version' => (int) $row->read_version, 'has_destination' => $row->destination !== null];
    }

    /** @return array<string, mixed> */
    private function decode(string $token, string $binding): array
    {
        try {
            $value = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($value) || ($value['binding'] ?? null) !== $binding) {
                throw new \RuntimeException;
            }

            return $value;
        } catch (Throwable) {
            throw ValidationException::withMessages(['cursor' => 'This notification view changed. Refresh to continue.']);
        }
    }
}
