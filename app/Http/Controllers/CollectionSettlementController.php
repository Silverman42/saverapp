<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCollectionSettlementRequest;
use App\Models\AuditEvent;
use App\Models\CollectionBatch;
use App\Services\CollectionEvidenceFiles;
use App\Services\CollectionSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionSettlementController extends Controller
{
    public function show(CollectionBatch $batch, Request $request, string $reference, CollectionSettlementService $settlements): JsonResponse
    {
        $settlements->authorize($request->user());
        $record = DB::table('collection_settlements')->where('collection_batch_id', $batch->id)->where('settlement_reference', $reference)->first();
        abort_if($record === null, 404);
        $settlements->assertPosted($record);

        return response()->json(['settlement_reference' => $record->settlement_reference, 'status' => 'posted',
            'amount_kobo' => (int) $record->amount_kobo, 'settled_date' => $record->settled_date]);
    }

    public function store(CollectionBatch $batch, StoreCollectionSettlementRequest $request, CollectionSettlementService $settlements): JsonResponse
    {
        $files = $request->file('files', []);
        abort_unless(is_array($files), 422);
        try {
            $reference = $settlements->record($request->user(), $batch, $request->safe()->except('files'), array_values($files));
        } catch (ConflictHttpException $exception) {
            $exists = DB::table('collection_settlements')->where('settlement_reference', $request->validated('settlement_reference'))->exists();

            return response()->json(['status' => $exists ? 'reference_in_use' : 'rejected', 'message' => $exception->getMessage()], 409);
        }

        return response()->json(['settlement_reference' => $reference], 201);
    }

    public function link(Request $request, string $reference, int $file): JsonResponse
    {
        $this->file($request, $reference, $file);

        return response()->json(['url' => URL::temporarySignedRoute('collection-settlements.files.download', now()->addMinutes(2), compact('reference', 'file'))]);
    }

    public function download(Request $request, string $reference, int $file, CollectionEvidenceFiles $files): StreamedResponse
    {
        $record = $this->file($request, $reference, $file);
        $bytes = $files->fileBytes($record);
        AuditEvent::record('collection.settlement_downloaded', 'collection_settlement', $record->collection_settlement_id, $reference, ['file_count' => 1], $request->user());
        $extension = match ($record->mime_type) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => 'pdf',
        };

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'settlement-evidence-'.$file.'.'.$extension, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function file(Request $request, string $reference, int $file): \stdClass
    {
        app(CollectionSettlementService::class)->authorize($request->user());
        $record = DB::table('collection_settlement_files as files')->join('collection_settlements as settlements', 'settlements.id', '=', 'files.collection_settlement_id')
            ->where('settlements.settlement_reference', $reference)->where('files.id', $file)->first(['files.*']);
        abort_if($record === null, 404);

        return $record;
    }
}
