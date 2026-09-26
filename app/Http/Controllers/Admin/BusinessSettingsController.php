<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BusinessSettingsRequest;
use App\Services\BusinessSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingsController extends Controller
{
    public function index(Request $request, BusinessSettings $settings): Response
    {
        $settings->authorize($request->user());
        Inertia::encryptHistory();

        return Inertia::render('admin/business-settings/Index', [
            'settings' => fn (): array => $settings->workspace($request->user()),
            'scope' => fn (): string => $settings->scope($request->user()),
        ]);
    }

    public function store(BusinessSettingsRequest $request, BusinessSettings $settings): RedirectResponse
    {
        $settings->saveDraft($request->user(), $request->validated('patch'), $request->integer('base_version'), $request->validated('operation_id'));

        return back();
    }

    public function update(BusinessSettingsRequest $request, int $draft, BusinessSettings $settings): RedirectResponse
    {
        $settings->saveDraft($request->user(), $request->validated('patch'), $request->integer('base_version'), $request->validated('operation_id'), $draft, $request->integer('revision'));

        return back();
    }

    public function preview(BusinessSettingsRequest $request, int $draft, BusinessSettings $settings): RedirectResponse
    {
        $settings->preview($request->user(), $draft, $request->integer('revision'), $request->validated('effective_at'));

        return back();
    }

    public function publish(BusinessSettingsRequest $request, int $draft, BusinessSettings $settings): RedirectResponse
    {
        $settings->publish($request->user(), $draft, $request->integer('revision'), $request->validated('preview_reference'),
            $request->validated('reason'), $request->validated('operation_id'), $request);

        return back();
    }

    public function cancel(BusinessSettingsRequest $request, int $configuration, BusinessSettings $settings): RedirectResponse
    {
        $settings->cancel($request->user(), $configuration, $request->validated('reason'), $request->validated('operation_id'), $request);

        return back();
    }

    public function discard(BusinessSettingsRequest $request, int $draft, BusinessSettings $settings): RedirectResponse
    {
        $settings->discard($request->user(), $draft, $request->integer('revision'), $request->validated('operation_id'));

        return back();
    }

    public function rollback(BusinessSettingsRequest $request, int $configuration, BusinessSettings $settings): RedirectResponse
    {
        $settings->rollbackDraft($request->user(), $configuration, $request->validated('operation_id'));

        return back();
    }

    public function operation(Request $request, string $operation, BusinessSettings $settings): JsonResponse
    {
        return response()->json($settings->result($request->user(), $operation))->header('Cache-Control', 'no-store');
    }
}
