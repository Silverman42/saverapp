<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmailChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmailChangeController extends Controller
{
    public function store(Request $request, EmailChangeService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->assertOnlyFields($request, ['email']);
        $validated = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);
        $service->begin($user, $validated['email']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Confirmation links were sent to your current and proposed email addresses.']);

        return to_route('profile.edit');
    }

    public function showConfirmation(Request $request, int $pendingEmailChange): Response
    {
        return Inertia::render('auth/EmailChangeConfirmation', [
            'pending_id' => $pendingEmailChange,
            'confirmation_recorded' => $request->boolean('confirmed'),
        ]);
    }

    public function confirm(Request $request, int $pendingEmailChange, EmailChangeService $service): RedirectResponse
    {
        $this->assertOnlyFields($request, ['token']);
        $validated = $request->validate(['token' => ['required', 'string', 'min:32', 'max:128']]);
        $completed = $service->confirm($pendingEmailChange, $validated['token']);

        if (! $completed) {
            return to_route('email-change.confirm.show', ['pendingEmailChange' => $pendingEmailChange, 'confirmed' => 1]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('status', 'email-change-complete');

        return to_route('login');
    }

    /** @param array<int, string> $allowed */
    protected function assertOnlyFields(Request $request, array $allowed): void
    {
        $unexpected = array_diff(array_keys($request->except(['_token', '_method'])), $allowed);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(['profile' => ['The request contains fields that cannot be changed here.']]);
        }
    }
}
