<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BusinessSettingsRequest;
use App\Services\BusinessSettings;
use App\Support\Toast;
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

        Toast::success('Draft saved', 'Your settings change is saved as a draft.');

        return to_route('admin.business-settings.index');
    }

    public function update(BusinessSettingsRequest $request, int $draft, BusinessSettings $settings): RedirectResponse
    {
        $settings->saveDraft($request->user(), $request->validated('patch'), $request->integer('base_version'), $request->validated('operation_id'), $draft, $request->integer('revision'));

        Toast::success('Draft saved', 'Your settings change is saved as a draft.');

        return to_route('admin.business-settings.index');
    }

    public function preview(BusinessSettingsRequest $request, int $draft, BusinessSettings $settings): RedirectResponse
    {
        $settings->preview($request->user(), $draft, $request->integer('revision'), $request->validated('effective_at'));

        Toast::success('Preview ready', 'Review the impact before you publish.');

        return to_route('admin.business-settings.index');
    }

    public function publish(BusinessSettingsRequest $request, int $draft, BusinessSettings $settings): RedirectResponse
    {
        $settings->publish($request->user(), $draft, $request->integer('revision'), $request->validated('preview_reference'),
            $request->validated('reason'), $request->validated('operation_id'), $request);

        Toast::success('Settings published', 'The new business settings are scheduled to take effect.');

        return to_route('admin.business-settings.index');
    }

    public function cancel(BusinessSettingsRequest $request, int $configuration, BusinessSettings $settings): RedirectResponse
    {
        $settings->cancel($request->user(), $configuration, $request->validated('reason'), $request->validated('operation_id'), $request);

        Toast::success('Version cancelled', 'The scheduled settings version will not take effect.');

        return to_route('admin.business-settings.index');
    }

    public function discard(BusinessSettingsRequest $request, int $draft, BusinessSettings $settings): RedirectResponse
    {
        $settings->discard($request->user(), $draft, $request->integer('revision'), $request->validated('operation_id'));

        Toast::success('Draft discarded', 'The settings draft was removed.');

        return to_route('admin.business-settings.index');
    }

    public function rollback(BusinessSettingsRequest $request, int $configuration, BusinessSettings $settings): RedirectResponse
    {
        $settings->rollbackDraft($request->user(), $configuration, $request->validated('operation_id'));

        Toast::success('Rollback drafted', 'A draft restoring the earlier settings is ready to review.');

        return to_route('admin.business-settings.index');
    }

    public function operation(Request $request, string $operation, BusinessSettings $settings): JsonResponse
    {
        return response()->json($settings->result($request->user(), $operation))->header('Cache-Control', 'no-store');
    }
}
