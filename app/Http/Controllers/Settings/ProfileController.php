<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Support\Toast;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $profileEditUrl = match ($user->user_type) {
            UserType::Customer => $user->customerProfile?->customer_id ? route('customers.edit', $user->customerProfile->customer_id) : null,
            UserType::Agent => $user->agentProfile?->agent_id ? route('agents.edit', $user->agentProfile->agent_id) : null,
            default => null,
        };

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'profileEditUrl' => $profileEditUrl,
        ]);
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Toast::success('Account deleted', 'Your account was deleted.');

        return redirect('/');
    }
}
