<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ResourceScopeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class CustomerPhotoController extends Controller
{
    /**
     * Stream the customer's profile photo if authorized by scope.
     */
    public function show(
        Request $request,
        string $customer,
        ResourceScopeService $resourceScopeService,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();

        $customerProfile = $resourceScopeService->forCustomers($viewer)
            ->where('customer_id', $customer)
            ->first();

        if (! $customerProfile || ! $customerProfile->photo_path) {
            abort(404, 'Record unavailable.');
        }

        $disk = Storage::disk()->exists($customerProfile->photo_path)
            ? Storage::disk()
            : (Storage::disk('public')->exists($customerProfile->photo_path) ? Storage::disk('public') : null);

        if ($disk === null) {
            abort(404, 'Record unavailable.');
        }

        $mimeType = $disk->mimeType($customerProfile->photo_path) ?: 'application/octet-stream';
        $content = $disk->get($customerProfile->photo_path);

        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
