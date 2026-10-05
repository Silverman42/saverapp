<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\StaffRecovery;
use App\Models\User;
use App\Services\StaffRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StaffRecoveryController extends Controller
{
    public function __construct(protected StaffRecoveryService $recoveries) {}

    /**
     * List Agent and Admin recoveries the viewer may manage.
     */
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $items = StaffRecovery::query()->with(['user', 'requestedBy', 'approvals'])->latest('id')->limit(100)->get()
            ->filter(fn (StaffRecovery $recovery): bool => $this->recoveries->canManage($viewer, $recovery->user))
            ->map(fn (StaffRecovery $recovery): array => [
                'reference' => $recovery->reference,
                'user' => ['id' => $recovery->user->id, 'name' => $recovery->user->name, 'type' => $recovery->user->user_type->value],
                'requested_by' => $recovery->requestedBy->name,
                'state' => $recovery->state,
                'version' => $recovery->version,
                'approvals' => $recovery->approvals->count(),
                'required_approvals' => $recovery->required_approvals,
                'created_at' => $recovery->created_at?->toIso8601String(),
                'request_expires_at' => $recovery->request_expires_at->toIso8601String(),
                'activation_expires_at' => $recovery->activation_expires_at?->toIso8601String(),
                'can_decide' => $recovery->state === 'awaiting_approval' && $recovery->requested_by_user_id !== $viewer->id
                    && ! $recovery->approvals->contains('approver_user_id', $viewer->id),
                'can_cancel' => $recovery->state === 'awaiting_approval',
                'can_reissue' => in_array($recovery->state, ['awaiting_activation', 'activation_expired'], true),
            ])->values();

        return Inertia::render('admin/staff-recoveries/Index', ['recoveries' => $items]);
    }

    /**
     * Show the recovery request form for an Agent or Admin.
     */
    public function create(Request $request, User $user): Response
    {
        $this->authorizeTarget($request, $user);

        return Inertia::render('admin/staff-recoveries/Create', [
            'target' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'type' => $user->user_type->value],
            'required_approvals' => $this->recoveries->requiredApprovals($user, $request->user()),
        ]);
    }

    /**
     * Open a recovery request after out-of-band identity verification.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user);
        try {
            $this->recoveries->request($request->user(), $user, $request->only(['email', 'procedure_reference', 'notes', 'verified_at', 'identity_verified']));
        } catch (ConflictHttpException $exception) {
            return back()->withErrors(['recovery' => $exception->getMessage()]);
        }

        return $this->done('Recovery requested. It now needs approval from a different Administrator.');
    }

    /**
     * Approve, reject, cancel or reissue a recovery.
     */
    public function decide(Request $request, StaffRecovery $recovery, string $action): RedirectResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);
        $version = (int) $validated['version'];
        $reason = trim($validated['reason']);

        try {
            $result = $this->runDecision($request, $recovery, $action, $version, $reason);
        } catch (ConflictHttpException $exception) {
            return back()->withErrors(['recovery' => $exception->getMessage()]);
        }

        return $this->done(match (true) {
            $action === 'approve' && $result->state === 'awaiting_approval' => 'Approval recorded. Another Administrator must also approve.',
            $action === 'approve', $action === 'reissue' => 'Recovery approved. A single-use activation link was sent.',
            default => 'Recovery '.($action === 'reject' ? 'rejected.' : 'cancelled.'),
        });
    }

    private function runDecision(Request $request, StaffRecovery $recovery, string $action, int $version, string $reason): StaffRecovery
    {
        return match ($action) {
            'approve' => $this->recoveries->approve($request->user(), $recovery, $version, $reason),
            'reject' => $this->recoveries->reject($request->user(), $recovery, $version, $reason),
            'cancel' => $this->recoveries->cancel($request->user(), $recovery, $version, $reason),
            'reissue' => $this->recoveries->reissue($request->user(), $recovery, $version, $reason),
            default => abort(404),
        };
    }

    private function authorizeTarget(Request $request, User $user): void
    {
        abort_unless(in_array($user->user_type, [UserType::Agent, UserType::Admin], true), 404);
        abort_unless($this->recoveries->canManage($request->user(), $user), 403);
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('admin.staff-recoveries.index');
    }
}
