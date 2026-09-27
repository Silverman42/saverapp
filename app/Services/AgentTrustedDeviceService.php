<?php

namespace App\Services;

use App\Enums\UserType;
use App\Models\AgentTrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class AgentTrustedDeviceService
{
    public const COOKIE_NAME = 'agent_trusted_device';

    /**
     * Determine if the request has a valid, unexpired trusted device for this Agent.
     */
    public function hasValidTrustedDevice(User $user, Request $request): bool
    {
        if ($user->user_type !== UserType::Agent) {
            return false;
        }

        $token = $request->cookie(self::COOKIE_NAME);
        if (! $token || ! is_string($token)) {
            return false;
        }

        $tokenHash = AgentTrustedDevice::hashToken($token);

        return AgentTrustedDevice::where('user_id', $user->id)
            ->where('device_token_hash', $tokenHash)
            ->where('trusted_until', '>', Carbon::now())
            ->exists();
    }

    /**
     * Create and store a new 30-day trusted device authorization for an Agent.
     */
    public function createTrustedDevice(User $user, Request $request): SymfonyCookie
    {
        if ($user->user_type !== UserType::Agent) {
            throw new \InvalidArgumentException('Only Agents may configure trusted devices.');
        }

        $rawToken = Str::random(64);
        $tokenHash = AgentTrustedDevice::hashToken($rawToken);
        $trustedUntil = Carbon::now()->addDays(30);

        $deviceName = $this->resolveDeviceName($request->userAgent());

        $created = DB::transaction(function () use ($user, $tokenHash, $deviceName, $request, $trustedUntil): bool {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $locked->canSignIn() || $locked->lifecycle_access_version !== $user->lifecycle_access_version) {
                return false;
            }
            AgentTrustedDevice::create([
                'user_id' => $user->id,
                'device_token_hash' => $tokenHash,
                'device_name' => $deviceName,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'trusted_until' => $trustedUntil,
            ]);

            return true;
        });
        if (! $created) {
            return Cookie::forget(self::COOKIE_NAME);
        }

        return cookie(
            name: self::COOKIE_NAME,
            value: $rawToken,
            minutes: 30 * 24 * 60, // 30 days
            path: '/',
            secure: true,
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /**
     * Revoke all trusted devices for a user and clear the device cookie.
     */
    public function revokeTrustedDevices(User $user): SymfonyCookie
    {
        $user->trustedDevices()->delete();

        return Cookie::forget(self::COOKIE_NAME);
    }

    /**
     * Resolve a human-recognizable device name from user agent.
     */
    public function resolveDeviceName(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown Device';
        }

        $platform = 'Unknown OS';
        if (preg_match('/Macintosh|Mac OS X/i', $userAgent)) {
            $platform = 'macOS';
        } elseif (preg_match('/Windows/i', $userAgent)) {
            $platform = 'Windows';
        } elseif (preg_match('/Linux/i', $userAgent)) {
            $platform = 'Linux';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $userAgent)) {
            $platform = 'iOS';
        } elseif (preg_match('/Android/i', $userAgent)) {
            $platform = 'Android';
        }

        $browser = 'Unknown Browser';
        if (preg_match('/Edg/i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome/i', $userAgent) && ! preg_match('/Edg/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari/i', $userAgent) && ! preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/Firefox/i', $userAgent)) {
            $browser = 'Firefox';
        }

        return "{$browser} on {$platform}";
    }
}
