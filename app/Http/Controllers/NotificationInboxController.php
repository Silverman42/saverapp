<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationInboxRequest;
use App\Models\BusinessProfile;
use App\Services\NotificationInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationInboxController extends Controller
{
    public function index(NotificationInboxRequest $request, NotificationInbox $inbox): Response
    {
        $filters = array_filter($request->validated(), static fn (mixed $value): bool => $value !== null && $value !== '');

        $filters += ['read' => 'all', 'status' => 'current', 'page_size' => 25];

        return Inertia::render('notifications/Index', ['inbox' => $inbox->read($request->user(), $filters),
            'filters' => $filters, 'timezone' => BusinessProfile::current()->timezone]);
    }

    public function show(string $notification, Request $request, NotificationInbox $inbox): Response
    {
        return Inertia::render('notifications/Show', ['notification' => $inbox->detail($request->user(), $notification),
            'sync' => $inbox->sync($request->user()), 'timezone' => BusinessProfile::current()->timezone]);
    }

    public function sync(Request $request, NotificationInbox $inbox): JsonResponse
    {
        return response()->json($inbox->sync($request->user()));
    }

    public function update(string $notification, Request $request, NotificationInbox $inbox): JsonResponse
    {
        $values = $request->validate(['read' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);

        return response()->json($inbox->mark($request->user(), $notification, (bool) $values['read'], (int) $values['version']));
    }

    public function pageRead(Request $request, NotificationInbox $inbox): JsonResponse
    {
        $values = $request->validate(['page_token' => ['required', 'string', 'max:32768']]);
        $inbox->markPage($request->user(), $values['page_token']);

        return response()->json($inbox->sync($request->user()));
    }

    public function open(string $notification, Request $request, NotificationInbox $inbox): RedirectResponse
    {
        return redirect()->to($inbox->destination($request->user(), $notification));
    }
}
