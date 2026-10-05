<?php

namespace App\Http\Controllers;

use App\Services\BusinessLogoService;
use App\Services\BusinessSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class BusinessLogoController extends Controller
{
    /**
     * Process and store a logo upload; publication still goes through the reviewed draft flow.
     */
    public function store(Request $request, BusinessSettings $settings, BusinessLogoService $logos): JsonResponse
    {
        $settings->authorize($request->user(), true);
        $request->validate(['logo' => ['required', 'file']]);

        return response()->json(['reference' => $logos->store($request->file('logo'), $request->user())])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Stream a stored logo version to authenticated users.
     */
    public function show(string $reference, BusinessLogoService $logos): Response
    {
        abort_unless($logos->exists($reference), 404, 'Record unavailable.');

        return response(Storage::disk(BusinessLogoService::DISK)->get($logos->path($reference)), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
