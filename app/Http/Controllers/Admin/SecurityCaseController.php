<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SecurityCaseRequest;
use App\Models\AuditEvent;
use App\Models\SecurityCase;
use App\Models\User;
use App\Services\AuditWorkspace;
use App\Services\AuthorizationService;
use App\Services\SecurityCaseService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SecurityCaseController extends Controller
{
    public function index(Request $request, AuditWorkspace $audit, SecurityCaseService $cases): Response
    {
        $scope = $audit->scope($request->user(), AdminPermission::SecurityOperationsManage);
        $filters = $request->validate(['state' => ['nullable', 'in:Open,Investigating,Resolved,ClosedNoAction'], 'severity' => ['nullable', 'in:Informational,Low,Medium,High,Critical'], 'per_page' => ['nullable', 'integer', 'in:25,50,100']]);
        $cases->releaseIneligibleOwners();
        $query = SecurityCase::query();
        foreach (['state', 'severity'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        $rows = $query->orderByRaw("CASE WHEN state IN ('Open', 'Investigating') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE severity WHEN 'Critical' THEN 0 WHEN 'High' THEN 1 WHEN 'Medium' THEN 2 WHEN 'Low' THEN 3 ELSE 4 END")
            ->orderBy('created_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $rows->through(fn (SecurityCase $case): array => $this->summary($case));
        Inertia::encryptHistory();

        return Inertia::render('admin/security/Index', ['cases' => $rows, 'filters' => $filters, 'scope' => fn (): string => $audit->scope($request->user(), AdminPermission::SecurityOperationsManage)]);
    }

    public function show(Request $request, SecurityCase $case, AuditWorkspace $audit, SecurityCaseService $cases, AuthorizationService $authorization): Response
    {
        $audit->scope($request->user(), AdminPermission::SecurityOperationsManage);
        if ($request->header('X-Inertia-Partial-Component') === 'admin/security/Show' && $request->header('X-Inertia-Partial-Data') === 'scope') {
            Inertia::encryptHistory();

            return Inertia::render('admin/security/Show', ['scope' => fn (): string => $audit->scope($request->user(), AdminPermission::SecurityOperationsManage)]);
        }
        $cases->releaseOwner($case);
        $case->refresh();
        AuditEvent::record('security.case_viewed', SecurityCase::class, $case->id, $case->case_reference,
            ['case_reference' => $case->case_reference], $request->user()->fresh(), ['required_permission' => AdminPermission::SecurityOperationsManage->value, 'executor' => self::class]);
        $sourceEvent = DB::table('canonical_audit_events')->where('id', $case->source_event_id)->firstOrFail();
        $sourceContent = json_decode($sourceEvent->content, true, flags: JSON_THROW_ON_ERROR);
        $source = ['event_type' => $sourceEvent->event_type, 'occurred_at' => $sourceEvent->occurred_at,
            'facts' => array_intersect_key($sourceContent['safe_changes'], array_flip(['category', 'lock_id', 'attempt_count', 'projection_version']))];
        $history = DB::table('security_case_transitions')->where('security_case_id', $case->id)->orderBy('version')->get()
            ->map(fn ($row): array => ['version' => $row->version, 'event_type' => $row->event_type, 'actor_id' => $row->actor_id,
                'facts' => json_decode($row->facts, true, flags: JSON_THROW_ON_ERROR), 'note' => $row->note_ciphertext === null ? null : Crypt::decryptString($row->note_ciphertext),
                'evidence_references' => json_decode($row->evidence_references ?? '[]', true, flags: JSON_THROW_ON_ERROR), 'created_at' => $row->created_at])->all();
        $owners = User::query()->where('user_type', 'admin')->where('account_state', 'active')->get()
            ->filter(fn (User $user): bool => $authorization->allows($user, AdminPermission::SecurityOperationsManage))
            ->map(fn (User $user): array => ['id' => $user->id, 'label' => 'Admin #'.$user->id])->values()->all();
        Inertia::encryptHistory();

        return Inertia::render('admin/security/Show', ['case' => $this->summary($case), 'history' => $history, 'owners' => $owners, 'source' => $source,
            'scope' => fn (): string => $audit->scope($request->user(), AdminPermission::SecurityOperationsManage)]);
    }

    public function update(SecurityCaseRequest $request, SecurityCase $case, SecurityCaseService $cases): RedirectResponse
    {
        $cases->change($request->user(), $case, $request->validated());

        Toast::success('Case updated', 'The security case was updated.');

        return back();
    }

    /** @return array<string, mixed> */
    private function summary(SecurityCase $case): array
    {
        return ['case_reference' => $case->case_reference, 'state' => $case->state, 'severity' => $case->severity,
            'version' => $case->version, 'episode' => $case->episode, 'owner_id' => $case->owner_id,
            'affected_account' => $case->affected_user_id === null ? 'Unknown source' : 'Account #'.$case->affected_user_id,
            'created_at' => $case->created_at?->toISOString(), 'updated_at' => $case->updated_at?->toISOString()];
    }
}
