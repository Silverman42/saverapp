<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Http\Requests\ReviewCollectionEvidenceRequest;
use App\Http\Requests\StoreCollectionEvidenceRequest;
use App\Http\Requests\StoreCollectionMethodRequest;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Services\AgentEligibilityService;
use App\Services\AuthorizationService;
use App\Services\CollectionMethodCatalogue;
use App\Services\CollectionPaymentEvidenceService;
use App\Services\ResourceScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CollectionEvidenceController extends Controller
{
    public function manage(Request $request): Response
    {
        abort_unless(app(AuthorizationService::class)->allows($request->user(), AdminPermission::BusinessSettingsManage), 403);

        return Inertia::render('collections/Methods', [
            'methods' => DB::table('collection_method_versions')->orderByDesc('id')->paginate(25, [
                'id', 'method_key', 'version', 'label', 'custody_account_code', 'mapping_version', 'destination_key', 'attachment_required', 'effective_at',
            ])->withQueryString(),
            'latest_versions' => DB::table('collection_method_versions')->select('method_key')->selectRaw('MAX(version) as version')
                ->groupBy('method_key')->pluck('version', 'method_key'),
            'accounts' => DB::table('ledger_accounts')->whereIn('code', ['agent_receivable_ngn', 'business_bank_ngn', 'payment_clearing_ngn'])
                ->whereNull('retired_at')->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
                ->where('currency', 'NGN')->get(['code', 'version', 'mapping_status', 'display_name']),
        ]);
    }

    public function publicationResult(Request $request, string $reference): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->allows($request->user(), AdminPermission::BusinessSettingsManage), 403);
        $method = DB::table('collection_method_versions')->where('publication_reference', $reference)
            ->where('published_by_user_id', $request->user()->id)->first(['id', 'method_key', 'version', 'label']);
        abort_if($method === null, 404);

        return response()->json(['method_version_id' => $method->id, 'method_key' => $method->method_key, 'version' => $method->version, 'label' => $method->label]);
    }

    public function index(Request $request, ResourceScopeService $scope): Response
    {
        abort_unless(app(AgentEligibilityService::class)->canReadAssignedCustomers($request->user())
            || app(AuthorizationService::class)->allows($request->user(), AdminPermission::ReconciliationManage), 403);
        $filters = $request->validate(['status' => ['nullable', 'in:all,pending,verified,rejected,consumed']]);
        $latest = DB::table('collection_evidence_reviews')->selectRaw('collection_payment_evidence_id, MAX(id) AS review_id')
            ->groupBy('collection_payment_evidence_id');
        $query = DB::table('collection_payment_evidence as evidence')
            ->join('customer_profiles as customers', 'customers.id', '=', 'evidence.customer_profile_id')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->join('collection_method_versions as methods', 'methods.id', '=', 'evidence.collection_method_version_id')
            ->leftJoinSub($latest, 'latest', 'latest.collection_payment_evidence_id', '=', 'evidence.id')
            ->leftJoin('collection_evidence_reviews as review', 'review.id', '=', 'latest.review_id')
            ->leftJoin('collection_receipts as receipt', 'receipt.collection_payment_evidence_id', '=', 'evidence.id')
            ->whereIn('evidence.customer_profile_id', $scope->forCustomers($request->user())->select('customer_profiles.id'))
            ->select('evidence.evidence_reference', 'evidence.received_date', 'evidence.amount_kobo',
                'customers.customer_id', 'users.name as customer_name', 'methods.label as method_label')
            ->selectRaw("CASE WHEN receipt.id IS NOT NULL THEN 'consumed' ELSE COALESCE(review.outcome, 'pending') END AS status");
        $status = $filters['status'] ?? 'all';
        if ($status === 'consumed') {
            $query->whereNotNull('receipt.id');
        } elseif ($status !== 'all') {
            $query->whereNull('receipt.id');
            if ($status === 'pending') {
                $query->whereNull('review.id');
            } else {
                $query->where('review.outcome', $status);
            }
        }

        return Inertia::render('collections/evidence/Index', [
            'evidence' => $query->orderByDesc('evidence.id')->paginate(25)->withQueryString(),
            'filters' => ['status' => $status],
        ]);
    }

    public function view(Request $request, string $reference, CollectionPaymentEvidenceService $evidence): Response
    {
        $details = $evidence->details($request->user(), $reference);

        return Inertia::render('collections/evidence/Show', [
            'evidence' => $details,
            'can_check_review' => app(AuthorizationService::class)->allows($request->user(), AdminPermission::ReconciliationManage),
            'can_review' => app(AuthorizationService::class)->allows($request->user(), AdminPermission::ReconciliationManage)
                && ! $details['consumed'] && $details['recorded_by_user_id'] !== $request->user()->id,
            'can_record' => $request->user()->can('recordCollection', CustomerProfile::query()->findOrFail($details['customer_profile_id'])),
        ]);
    }

    public function methods(Request $request): JsonResponse
    {
        abort_unless(app(AgentEligibilityService::class)->canReadAssignedCustomers($request->user()) || app(AuthorizationService::class)->allows($request->user(), AdminPermission::ReconciliationManage)
            || app(AuthorizationService::class)->allows($request->user(), AdminPermission::BusinessSettingsManage), 403);

        return response()->json(['methods' => DB::table('collection_method_versions')->orderByDesc('version')->get([
            'id', 'method_key', 'version', 'label', 'custody_account_code', 'mapping_version', 'destination_key', 'attachment_required',
        ])]);
    }

    public function publish(StoreCollectionMethodRequest $request, CollectionMethodCatalogue $methods): JsonResponse
    {
        return response()->json(['method_version_id' => $methods->publish($request->user(), $request->validated())], 201);
    }

    public function store(StoreCollectionEvidenceRequest $request, CustomerProfile $customer, CollectionPaymentEvidenceService $evidence): JsonResponse
    {
        $data = $request->safe()->except('files');
        $files = $request->file('files', []);
        abort_unless(is_array($files), 422);
        $reference = $evidence->store($request->user(), $customer, $data, array_values($files));

        return response()->json($evidence->details($request->user(), $reference), 201);
    }

    public function show(Request $request, string $reference, CollectionPaymentEvidenceService $evidence): JsonResponse
    {
        return response()->json($evidence->details($request->user(), $reference));
    }

    public function reviewResult(Request $request, string $reference, string $operation, CollectionPaymentEvidenceService $evidence): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->allows($request->user(), AdminPermission::ReconciliationManage), 403);
        $record = $evidence->authorized($request->user(), $reference);
        $review = DB::table('collection_evidence_reviews')->where('collection_payment_evidence_id', $record->id)
            ->where('operation_reference', $operation)->where('reviewed_by_user_id', $request->user()->id)->first();
        abort_if($review === null, 404);

        return response()->json(['status' => 'posted', 'outcome' => $review->outcome, 'version' => (int) $review->version]);
    }

    public function review(ReviewCollectionEvidenceRequest $request, string $reference, CollectionPaymentEvidenceService $evidence): JsonResponse
    {
        $evidence->review($request->user(), $reference, $request->validated());

        return response()->json($evidence->details($request->user(), $reference));
    }

    public function link(Request $request, string $reference, int $file, CollectionPaymentEvidenceService $evidence): JsonResponse
    {
        $evidence->file($request->user(), $reference, $file);

        return response()->json(['url' => URL::temporarySignedRoute('collection-evidence.files.download', now()->addMinutes(2), ['reference' => $reference, 'file' => $file])]);
    }

    public function download(Request $request, string $reference, int $file, CollectionPaymentEvidenceService $evidence): StreamedResponse
    {
        $record = $evidence->file($request->user(), $reference, $file);
        $bytes = $evidence->fileBytes($record);
        AuditEvent::record('collection.evidence_downloaded', 'collection_payment_evidence', $record->collection_payment_evidence_id, $reference, ['file_count' => 1], $request->user());
        $extension = match ($record->mime_type) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => 'pdf',
        };

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'payment-evidence-'.$file.'.'.$extension, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
