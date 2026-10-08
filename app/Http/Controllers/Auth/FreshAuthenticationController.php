<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Services\FreshAuthenticationService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FreshAuthenticationController extends Controller
{
    /**
     * Display the fresh authentication step-up view.
     */
    public function show(Request $request, FreshAuthenticationService $freshService): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user && $freshService->isFresh($user, $request)) {
            return redirect()->intended(route('dashboard'));
        }

        $requiresTwoFactor = $user && in_array($user->user_type, [UserType::Agent, UserType::Admin], true);

        return Inertia::render('auth/FreshAuthentication', [
            'requiresTwoFactor' => $requiresTwoFactor,
            'userType' => $user?->user_type?->value,
            'email' => $user?->email,
        ]);
    }

    /**
     * Confirm fresh credentials and advance session freshness.
     */
    public function store(Request $request, FreshAuthenticationService $freshService): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $rules = [
            'password' => ['required', 'string'],
        ];

        if (in_array($user->user_type, [UserType::Agent, UserType::Admin], true)) {
            $rules['code'] = ['required', 'string', 'size:6'];
        }

        $validated = $request->validate($rules);

        $freshService->confirm(
            $user,
            $request,
            $validated['password'],
            $validated['code'] ?? null,
        );

        Toast::success('Identity confirmed', 'You can continue with your action.');

        return redirect()->intended(route('dashboard'));
    }
}
