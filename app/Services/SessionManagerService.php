<?php

namespace App\Services;

use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\Auth\AdminConcurrentDeviceRevokedNotification;
use App\Notifications\Auth\SessionRevokedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SessionManagerService
{
    public function __construct(
        protected AgentTrustedDeviceService $trustedDeviceService,
    ) {}

    /**
     * Get all active, unexpired database sessions for the specified user.
     *
     * @return Collection<int, array{
     *     id: string,
     *     device_name: string,
     *     masked_ip: string,
     *     first_sign_in_at: string,
     *     last_active_at: string,
     *     last_active_timestamp: int,
     *     is_current_device: bool
     * }>
     */
    public function getActiveSessions(User $user, ?string $currentSessionId = null): Collection
    {
        $table = config('session.table', 'sessions');
        $inactivityCutoff = Carbon::now()->subSeconds($user->inactivityTimeoutSeconds())->timestamp;
        $lifetimeCutoff = Carbon::now()->subSeconds($user->maximumSessionLifetimeSeconds())->timestamp;

        // Clean up expired sessions for this user
        DB::table($table)
            ->where('user_id', $user->id)
            ->where(function ($query) use ($inactivityCutoff, $lifetimeCutoff) {
                $query->where('last_activity', '<', $inactivityCutoff)
                    ->orWhere(function ($q) use ($lifetimeCutoff) {
                        $q->whereNotNull('created_at')
                            ->where('created_at', '<', $lifetimeCutoff);
                    });
            })
            ->delete();

        // Retrieve remaining active sessions
        $rows = DB::table($table)
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get();

        return $rows->map(function ($row) use ($currentSessionId) {
            $firstSignIn = $row->created_at ? Carbon::createFromTimestamp($row->created_at) : Carbon::createFromTimestamp($row->last_activity);
            $lastActive = Carbon::createFromTimestamp($row->last_activity);

            return [
                'id' => strval($row->id),
                'device_name' => $this->trustedDeviceService->resolveDeviceName($row->user_agent),
                'masked_ip' => $this->maskIpAddress($row->ip_address),
                'first_sign_in_at' => $firstSignIn->toIso8601String(),
                'last_active_at' => $lastActive->toIso8601String(),
                'last_active_timestamp' => $lastActive->getTimestamp(),
                'is_current_device' => $row->id === $currentSessionId,
            ];
        });
    }

    /**
     * Check if user has reached their maximum concurrent device limit.
     * Returns the collection of active sessions if limit is reached, or null if within limits.
     *
     * @return Collection<int, array{id: string, device_name: string, masked_ip: string, first_sign_in_at: string, last_active_at: string, last_active_timestamp: int, is_current_device: bool}>|null
     */
    public function checkDeviceLimits(User $user, ?string $currentSessionId = null): ?Collection
    {
        $activeSessions = $this->getActiveSessions($user, $currentSessionId);
        $limit = $user->maxConcurrentDevices();

        if ($activeSessions->count() >= $limit) {
            return $activeSessions;
        }

        return null;
    }

    /**
     * Evict a selected session and send appropriate security notification.
     */
    public function evictSession(User $user, string $sessionIdToEvict, ?Request $request = null): bool
    {
        return DB::transaction(function () use ($user, $sessionIdToEvict, $request) {
            $result = (function () use ($user, $sessionIdToEvict, $request) {
                $table = config('session.table', 'sessions');

                $sessionRow = DB::table($table)
                    ->where('user_id', $user->id)
                    ->where('id', $sessionIdToEvict)
                    ->first();

                if (! $sessionRow) {
                    return false;
                }

                $revokedDeviceName = $this->trustedDeviceService->resolveDeviceName($sessionRow->user_agent);
                $maskedIp = $this->maskIpAddress($sessionRow->ip_address);

                DB::table($table)->where('id', $sessionIdToEvict)->delete();

                $newDeviceName = $request ? $this->trustedDeviceService->resolveDeviceName($request->userAgent()) : 'New Device';

                if ($user->user_type === UserType::Admin) {
                    $user->notify((new AdminConcurrentDeviceRevokedNotification(
                        revokedDeviceName: $revokedDeviceName,
                        newDeviceName: $newDeviceName,
                        maskedIpAddress: $maskedIp,
                    ))->afterCommit());
                } else {
                    $user->notify((new SessionRevokedNotification(
                        reason: 'Signed out to allow sign-in on another device',
                        deviceInfo: $revokedDeviceName,
                    ))->afterCommit());
                }

                return true;

            })();
            if ($result !== false) {
                AuditEvent::record('auth.session_revoked', User::class, $user->id, null,
                    ['changed_fields' => ['evictSession']], $user, ['executor' => self::class]);
            }

            return $result;
        });
    }

    /**
     * Revoke an individual session belonging to the user.
     */
    public function revokeSession(User $user, string $sessionId): bool
    {
        return DB::transaction(function () use ($user, $sessionId) {
            $result = (function () use ($user, $sessionId) {
                $table = config('session.table', 'sessions');

                $sessionRow = DB::table($table)
                    ->where('user_id', $user->id)
                    ->where('id', $sessionId)
                    ->first();

                if (! $sessionRow) {
                    return false;
                }

                $deviceName = $this->trustedDeviceService->resolveDeviceName($sessionRow->user_agent);

                $deleted = DB::table($table)
                    ->where('user_id', $user->id)
                    ->where('id', $sessionId)
                    ->delete() > 0;

                if ($deleted) {
                    $user->notify((new SessionRevokedNotification(
                        reason: 'Device signed out by user',
                        deviceInfo: $deviceName,
                    ))->afterCommit());
                }

                return $deleted;

            })();
            if ($result !== false) {
                AuditEvent::record('auth.session_revoked', User::class, $user->id, null,
                    ['changed_fields' => ['revokeSession']], $user, ['executor' => self::class]);
            }

            return $result;
        });
    }

    /**
     * Revoke all other active sessions for this user except current.
     */
    public function revokeOtherSessions(User $user, string $currentSessionId): int
    {
        return DB::transaction(function () use ($user, $currentSessionId) {
            $result = (function () use ($user, $currentSessionId) {
                $count = $user->revokeAllSessions(exceptSessionId: $currentSessionId);

                if ($count > 0) {
                    $user->notify((new SessionRevokedNotification(
                        reason: 'All other active sessions signed out',
                    ))->afterCommit());
                }

                return $count;

            })();
            if ($result > 0) {
                AuditEvent::record('auth.session_revoked', User::class, $user->id, null,
                    ['changed_fields' => ['revokeOtherSessions']], $user, ['executor' => self::class]);
            }

            return $result;
        });
    }

    /**
     * Revoke all sessions and all trusted devices for a user.
     */
    public function revokeAllSessionsAndTrustedDevices(User $user): void
    {
        DB::transaction(function () use ($user) {
            (function () use ($user) {
                $user->revokeAllSessions();
                $user->revokeAllTrustedDevices();

                $user->notify((new SessionRevokedNotification(
                    reason: 'All sessions and trusted devices signed out everywhere',
                ))->afterCommit());

            })();
            AuditEvent::record('auth.session_revoked', User::class, $user->id, null,
                ['changed_fields' => ['revokeAllSessionsAndTrustedDevices']], $user, ['executor' => self::class]);

        });
    }

    /**
     * Mask an IP address to prevent exposing full network identifiers.
     */
    public function maskIpAddress(?string $ip): string
    {
        if (! $ip) {
            return 'Unknown location';
        }

        // Handle IPv4 (e.g. 192.168.1.100 -> 192.168.***.***)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return "{$parts[0]}.{$parts[1]}.***.***";
            }
        }

        // Handle IPv6 (e.g. 2001:0db8:85a3:: -> 2001:db8:****:****)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            if (count($parts) >= 2) {
                return "{$parts[0]}:{$parts[1]}:****:****";
            }
        }

        return '***.***';
    }
}
