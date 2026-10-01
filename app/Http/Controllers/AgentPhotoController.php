<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ResourceScopeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class AgentPhotoController extends Controller
{
    /**
     * Stream the agent's profile photo if authorized by scope.
     */
    public function show(
        Request $request,
        string $agent,
        ResourceScopeService $resourceScopeService,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();

        $agentProfile = $resourceScopeService->forAgents($viewer)
            ->where('agent_id', $agent)
            ->first();

        if (! $agentProfile || ! $agentProfile->profile_photo_path) {
            abort(404, 'Record unavailable.');
        }

        $disk = Storage::disk('local')->exists($agentProfile->profile_photo_path)
            ? 'local'
            : (Storage::disk('public')->exists($agentProfile->profile_photo_path) ? 'public' : null);

        if ($disk === null) {
            abort(404, 'Record unavailable.');
        }

        $mimeType = Storage::disk($disk)->mimeType($agentProfile->profile_photo_path) ?: 'application/octet-stream';
        $content = Storage::disk($disk)->get($agentProfile->profile_photo_path);

        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
