<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmergencyRecoveryService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmergencyRecoveryController extends Controller
{
    /**
     * Show the final-Admin emergency recovery form.
     */
    public function create(): Response
    {
        return Inertia::render('auth/EmergencyRecovery');
    }

    /**
     * Accept the seeded Admin email and offline key without revealing whether they matched.
     */
    public function store(Request $request, EmergencyRecoveryService $service): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'key' => ['required', 'string', 'max:64'],
        ]);

        $service->start($validated['email'], $validated['key'], (string) $request->ip());

        Toast::success('Check your email', 'If those details are valid, a single-use recovery link was sent to the seeded Administrator email.');

        return redirect()->route('login');
    }

    /**
     * Issue and display the replacement key exactly once, without storing it anywhere first.
     */
    public function replacement(Request $request, EmergencyRecoveryService $service): Response|RedirectResponse
    {
        if ($request->session()->pull('auth.emergency_key_pending') !== true) {
            return redirect()->route('two-factor.enrolment');
        }

        return Inertia::render('auth/EmergencyKeyIssued', [
            'recovery_key' => $service->issue($request->user(), 'replacement_after_recovery'),
        ]);
    }
}
