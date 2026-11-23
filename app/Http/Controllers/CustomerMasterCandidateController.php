<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\CustomerMasterProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerMasterCandidateController extends Controller
{
    /**
     * Search the acting Agent's master profiles for the master account selector.
     */
    public function __invoke(Request $request, CustomerMasterProfileService $masterProfileService): JsonResponse
    {
        /** @var User $agent */
        $agent = $request->user();

        abort_unless($agent->user_type === UserType::Agent, 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'exclude' => ['nullable', 'string', 'max:32'],
        ]);

        $candidates = $masterProfileService->candidatesFor($agent, $validated['search'] ?? null, $validated['exclude'] ?? null);

        return response()->json([
            'data' => $candidates->map(fn (CustomerProfile $profile): array => [
                'id' => $profile->customer_id,
                'name' => $profile->user->name,
                'phone' => $profile->phone,
            ])->values(),
        ]);
    }
}
