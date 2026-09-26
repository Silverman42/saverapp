<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditSearchRequest;
use App\Services\AuditWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(AuditSearchRequest $request, AuditWorkspace $audit): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('admin/audit/Index', ['audit' => fn (): array => $audit->search($request->user(), $request->validated()),
            'filters' => $request->validated(), 'scope' => fn (): string => $audit->scope($request->user())]);
    }

    public function show(Request $request, string $event, AuditWorkspace $audit): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('admin/audit/Show', ['event' => fn (): array => $audit->detail($request->user(), $event),
            'scope' => fn (): string => $audit->scope($request->user())]);
    }
}
