<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\AuthenticationLock;
use App\Models\User;
use App\Services\AuthenticationAbuseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LockoutController extends Controller
{
    /**
     * Display a listing of authentication locks and abuse records.
     */
    public function index(Request $request): Response
    {
        $currentAdmin = $request->user();

        $locks = AuthenticationLock::with(['user', 'unlockedBy'])
            ->latest('locked_at')
            ->paginate(15)
            ->through(function (AuthenticationLock $lock) use ($currentAdmin) {
                $user = $lock->user;
                $isActive = $lock->isActive();
                $canUnlock = $user !== null
                    && $isActive
                    && $user->id !== $currentAdmin->id
                    && ! ($user->user_type === UserType::Admin && $user->isFinalActiveAdmin());

                return [
                    'id' => $lock->id,
                    'user_id' => $lock->user_id,
                    'user_name' => $user?->name,
                    'user_type' => $user?->user_type?->value,
                    'email' => $user?->email ?? $lock->email_normalized,
                    'lock_category' => $lock->lock_category,
                    'reason' => $lock->reason,
                    'failed_attempts_count' => $lock->failed_attempts_count,
                    'masked_ip' => $lock->maskedIp(),
                    'device_context' => $lock->deviceContext(),
                    'locked_at' => $lock->locked_at?->toIso8601String(),
                    'locked_until' => $lock->locked_until?->toIso8601String(),
                    'requires_review' => $lock->requires_review,
                    'is_active' => $isActive,
                    'is_expired' => $lock->isExpired(),
                    'unlocked_at' => $lock->unlocked_at?->toIso8601String(),
                    'unlocked_by' => $lock->unlockedBy?->name,
                    'unlock_reason' => $lock->unlock_reason,
                    'can_unlock' => $canUnlock,
                ];
            });

        return Inertia::render('admin/Lockouts', [
            'locks' => $locks,
        ]);
    }

    /**
     * Manually clear a temporary authentication lock for a verified user (AUTH-061).
     */
    public function unlock(Request $request, User $user, AuthenticationAbuseService $abuseService): RedirectResponse
    {
        $request->validate([
            'category' => ['nullable', 'string', 'in:password,mfa,recovery_code'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $category = $request->input('category');
        $reason = $request->input('reason', 'Manually unlocked by administrator after identity verification');

        $abuseService->manualUnlock($user, $request->user(), $category, $reason);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account restriction cleared successfully.'),
        ]);

        return back();
    }
}
