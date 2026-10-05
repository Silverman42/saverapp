<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\StaffRecovery;
use App\Services\StaffRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class StaffRecoveryActivationController extends Controller
{
    /**
     * Show the recovery activation form without revealing account details.
     */
    public function show(string $recovery): Response
    {
        return Inertia::render('auth/StaffRecoveryActivation', [
            'reference' => $recovery,
            'business_name' => BusinessProfile::current()->display_name,
        ]);
    }

    /**
     * Complete recovery with the user's own password, then require new MFA enrolment.
     */
    public function activate(Request $request, string $recovery, StaffRecoveryService $service): RedirectResponse
    {
        $user = $service->activate($recovery, $request->only(['token', 'password', 'password_confirmation']));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        if (StaffRecovery::query()->where('reference', $recovery)->value('kind') === 'emergency') {
            $request->session()->put('auth.emergency_key_pending', true);

            return redirect()->route('emergency-recovery.replacement');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Password created. Set up your new authenticator app to finish recovery.']);

        return redirect()->route('two-factor.enrolment');
    }
}
