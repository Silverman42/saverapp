<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Http\Requests\ReportRequest;
use App\Models\BusinessProfile;
use App\Models\FinancialArtifact;
use App\Services\AuthorizationService;
use App\Services\FinancialArtifactService;
use App\Services\ResourceScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class FinancialArtifactController extends Controller
{
    public function statement(string $customer, Request $request, ResourceScopeService $scope, FinancialArtifactService $service): RedirectResponse
    {
        $this->rejectUnknown($request, ['operation_reference', 'preview_fingerprint', 'supersedes_reference', 'from', 'to', 'confirmed']);
        $data = $request->validate(['operation_reference' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'preview_fingerprint' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'supersedes_reference' => ['nullable', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $timezone = BusinessProfile::current()->timezone;
        abort_if(CarbonImmutable::parse($data['from'], $timezone)->diffInDays(CarbonImmutable::parse($data['to'], $timezone)) > 365
            || $data['to'] > now($timezone)->toDateString(), 422, 'Choose at most 366 dates ending no later than today.');
        $profile = $scope->forCustomers($request->user())->where('customer_id', $customer)->firstOrFail();
        $supersedes = empty($data['supersedes_reference']) ? null : FinancialArtifact::query()->where('artifact_reference', $data['supersedes_reference'])->firstOrFail();
        $artifact = $service->issueStatement($request->user(), $profile, $data['operation_reference'], $data['from'], $data['to'], $data['preview_fingerprint'], $supersedes);

        return redirect()->route('financial-artifacts.show', $artifact);
    }

    public function export(string $report, ReportRequest $request, FinancialArtifactService $service): RedirectResponse
    {
        $this->rejectUnknown($request, [...array_keys($request->rules()), 'operation_reference', 'format', 'confirmed']);
        $data = $request->validate(['operation_reference' => ['required', 'uuid'], 'format' => ['required', 'in:csv,pdf'], 'confirmed' => ['required', 'accepted']]);
        $artifact = $service->exportReport($request->user(), $data['operation_reference'], $report, $data['format'], $request->filters(BusinessProfile::current()->timezone));

        return redirect()->route('financial-artifacts.show', $artifact);
    }

    public function show(FinancialArtifact $artifact, Request $request, FinancialArtifactService $service): Response
    {
        $service->authorize($request->user(), $artifact);

        return Inertia::render('ledger/Artifact', ['artifact' => [
            ...$artifact->only(['artifact_reference', 'kind', 'format', 'status', 'issued_at', 'expires_at', 'failure_code']),
            'manifest' => $artifact->manifest,
            'held' => $artifact->held,
            'can_cancel' => $artifact->requester_user_id === $request->user()->id,
            'can_retry' => $artifact->requester_user_id === $request->user()->id && $artifact->status === 'failed' && ! $artifact->expires_at?->isPast(),
            'can_hold' => $artifact->kind === 'report' && $artifact->status !== 'expired' && app(AuthorizationService::class)->allows($request->user(), AdminPermission::SecurityOperationsManage),
            'superseded_by' => FinancialArtifact::query()->where('supersedes_artifact_id', $artifact->id)->value('artifact_reference'),
            'download_url' => $artifact->status === 'ready' && ! $artifact->expires_at?->isPast()
                ? URL::temporarySignedRoute('financial-artifacts.download', now()->addMinutes(15), ['artifact' => $artifact, 'viewer' => $request->user()->id]) : null,
        ]]);
    }

    public function download(FinancialArtifact $artifact, Request $request, FinancialArtifactService $service): HttpResponse
    {
        abort_unless($request->hasValidSignature() && (string) $request->query('viewer') === (string) $request->user()->id, 404);
        $bytes = $service->download($request->user(), $artifact);

        return response($bytes, 200, ['Content-Type' => $artifact->format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$artifact->kind.'-'.$artifact->artifact_reference.'.'.$artifact->format.'"',
            'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function cancel(FinancialArtifact $artifact, Request $request, FinancialArtifactService $service): RedirectResponse
    {
        $this->rejectUnknown($request, ['confirmed']);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $service->cancel($request->user(), $artifact);

        return redirect()->route('financial-artifacts.show', $artifact);
    }

    public function retry(FinancialArtifact $artifact, Request $request, FinancialArtifactService $service): RedirectResponse
    {
        $this->rejectUnknown($request, ['confirmed']);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $service->retry($request->user(), $artifact);

        return redirect()->route('financial-artifacts.show', $artifact);
    }

    public function hold(FinancialArtifact $artifact, Request $request, FinancialArtifactService $service): RedirectResponse
    {
        $this->rejectUnknown($request, ['held', 'reason', 'confirmed']);
        $data = $request->validate(['held' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:500'], 'confirmed' => ['required', 'accepted']]);
        $service->setHold($request->user(), $artifact, (bool) $data['held'], $data['reason'], $request);

        return redirect()->route('financial-artifacts.show', $artifact);
    }

    /** @param list<string> $allowed */
    private function rejectUnknown(Request $request, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($request->except('_token')), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([$unexpected[0] => ['Unknown financial document field.']]);
        }
    }
}
