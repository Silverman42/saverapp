<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Enums\UnlockVerificationMethod;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ManualUnlockRequest;
use App\Models\AuthenticationLock;
use App\Models\User;
use App\Services\AuthenticationAbuseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LockoutController extends Controller
{
    /**
     * Display a listing of authentication locks and abuse records.
     */
    public function index(Request $request): Response
    {
        Gate::authorize(AdminPermission::SecurityOperationsManage->value);

        $currentAdmin = $request->user();

        $locks = AuthenticationLock::with(['user', 'unlockedBy'])
            ->latest('locked_at')
            ->paginate(15)
            ->through(function (AuthenticationLock $lock) use ($currentAdmin) {
                $user = $lock->user;
                $isActive = $lock->isActive();
                $canUnlock = $user !== null
                    && $isActive
                    && $user->id !== $currentAdmin?->id
                    && ! ($user->user_type === UserType::Admin && $user->isFinalActiveAdmin());

                return [
                    'id' => $lock->id,
                    'user_id' => $lock->user_id,
                    'user_name' => $user?->name,
                    'user_type' => $user?->user_type?->value,
                    'email' => $user ? $user->email : $lock->email_normalized,
                    'lock_category' => $lock->lock_category,
                    'reason' => $lock->reason,
                    'failed_attempts_count' => $lock->failed_attempts_count,
                    'masked_ip' => $lock->maskedIp(),
                    'device_context' => $lock->deviceContext(),
                    'locked_at' => $lock->locked_at->toIso8601String(),
                    'locked_until' => $lock->locked_until->toIso8601String(),
                    'requires_review' => $lock->requires_review,
                    'is_active' => $isActive,
                    'is_expired' => $lock->isExpired(),
                    'unlocked_at' => $lock->unlocked_at?->toIso8601String(),
                    'unlocked_by' => $lock->unlockedBy?->name,
                    'unlock_reason' => $lock->unlock_reason,
                    'unlock_verification_method' => $lock->unlock_verification_method?->value,
                    'unlock_verification_method_label' => $lock->unlock_verification_method?->label(),
                    'can_unlock' => $canUnlock,
                ];
            });

        $verificationMethods = collect(UnlockVerificationMethod::cases())->map(fn (UnlockVerificationMethod $m) => [
            'value' => $m->value,
            'label' => $m->label(),
        ])->all();

        return Inertia::render('admin/Lockouts', [
            'locks' => $locks,
            'verification_methods' => $verificationMethods,
        ]);
    }

    /**
     * Manually clear a temporary authentication lock for a verified user (AUTH-061, AUTHZ-019).
     */
    public function unlock(ManualUnlockRequest $request, User $user, AuthenticationAbuseService $abuseService): RedirectResponse
    {
        $category = $request->validated('category');
        $verificationMethod = $request->validated('verification_method');
        $reason = $request->validated('reason');

        $abuseService->manualUnlock($user, $request->user(), $category, $verificationMethod, $reason);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account restriction cleared successfully.'),
        ]);

        return back();
    }
}
