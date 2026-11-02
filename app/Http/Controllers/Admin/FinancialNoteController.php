<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Enums\FinancialNoteType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FinancialNoteRequest;
use App\Models\BusinessProfile;
use App\Models\FinancialNote;
use App\Services\AuthorizationService;
use App\Services\FinancialNoteService;
use App\Support\MoneyFormatter;
use App\Support\Toast;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FinancialNoteController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): Response
    {
        if (! $authorization->allows($request->user(), AdminPermission::FinancialNotesManage)) {
            throw new AuthorizationException;
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(FinancialNoteType::class)],
            'category' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in([FinancialNote::STATUS_ACTIVE, FinancialNote::STATUS_ARCHIVED])],
        ]);
        $filters['status'] ??= FinancialNote::STATUS_ACTIVE;

        $businessId = BusinessProfile::current()->id;
        $filtered = $this->filteredQuery($businessId, $filters);

        $totals = (clone $filtered)->toBase()
            ->selectRaw('type, SUM(amount_kobo) as total_kobo')
            ->groupBy('type')
            ->pluck('total_kobo', 'type');
        $creditKobo = (int) ($totals[FinancialNoteType::Credit->value] ?? 0);
        $debitKobo = (int) ($totals[FinancialNoteType::Debit->value] ?? 0);
        $netKobo = $creditKobo - $debitKobo;

        $notes = $filtered->with('updatedBy:id,name')
            ->orderByDesc('note_date')->orderByDesc('id')
            ->paginate(25)->withQueryString();
        $notes->through(static fn (FinancialNote $note): array => [
            'reference' => $note->reference,
            'type' => $note->type->value,
            'type_label' => $note->type->label(),
            'title' => $note->title,
            'amount_ngn' => MoneyFormatter::decimal($note->amount_kobo),
            'amount_display' => MoneyFormatter::formatNaira($note->amount_kobo),
            'note_date' => $note->note_date->toDateString(),
            'category' => $note->category,
            'body' => $note->body,
            'status' => $note->status,
            'version' => $note->version,
            'updated_by' => $note->updatedBy?->name,
            'updated_at' => $note->updated_at?->toIso8601String(),
        ]);

        return Inertia::render('admin/financial-notes/Index', [
            'notes' => $notes,
            'filters' => $filters,
            'categories' => FinancialNote::query()->where('business_profile_id', $businessId)
                ->whereNotNull('category')->distinct()->orderBy('category')->limit(100)->pluck('category'),
            'summary' => [
                'credit' => MoneyFormatter::formatNaira($creditKobo),
                'debit' => MoneyFormatter::formatNaira($debitKobo),
                'net' => ($netKobo < 0 ? '-' : '').MoneyFormatter::formatNaira(abs($netKobo)),
                'net_is_negative' => $netKobo < 0,
            ],
        ]);
    }

    public function store(FinancialNoteRequest $request, FinancialNoteService $notes): RedirectResponse
    {
        $notes->create($request->user(), $request->noteValues());

        Toast::success('Note added', 'The financial note was saved.');

        return redirect()->route('admin.financial-notes.index');
    }

    public function update(FinancialNoteRequest $request, FinancialNote $note, FinancialNoteService $notes): RedirectResponse
    {
        return $this->guardConflict(function () use ($request, $note, $notes): void {
            $notes->update($request->user(), $note, (int) $request->validated('version'), $request->noteValues());
            Toast::success('Note updated', 'Your changes were saved.');
        });
    }

    public function archive(Request $request, FinancialNote $note, FinancialNoteService $notes): RedirectResponse
    {
        $version = $this->validatedVersion($request);

        return $this->guardConflict(function () use ($request, $note, $notes, $version): void {
            $notes->archive($request->user(), $note, $version);
            Toast::success('Note archived', 'You can restore it from the archived view.');
        });
    }

    public function restore(Request $request, FinancialNote $note, FinancialNoteService $notes): RedirectResponse
    {
        $version = $this->validatedVersion($request);

        return $this->guardConflict(function () use ($request, $note, $notes, $version): void {
            $notes->restore($request->user(), $note, $version);
            Toast::success('Note restored', 'The note is active again.');
        });
    }

    /**
     * @param  array{search?: ?string, type?: ?string, category?: ?string, from?: ?string, to?: ?string, status: string}  $filters
     * @return Builder<FinancialNote>
     */
    private function filteredQuery(int $businessId, array $filters): Builder
    {
        return FinancialNote::query()
            ->where('business_profile_id', $businessId)
            ->where('status', $filters['status'])
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('note_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('note_date', '<=', $to))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.addcslashes(trim($search), '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner->where('title', 'like', $term)->orWhere('category', 'like', $term));
            });
    }

    private function validatedVersion(Request $request): int
    {
        if (! app(AuthorizationService::class)->allows($request->user(), AdminPermission::FinancialNotesManage)) {
            throw new AuthorizationException;
        }

        return (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
    }

    /**
     * @param  Closure(): void  $action
     */
    private function guardConflict(Closure $action): RedirectResponse
    {
        try {
            $action();
        } catch (ConflictHttpException $exception) {
            Toast::error('Note not changed', $exception->getMessage());
        }

        return redirect()->back(fallback: route('admin.financial-notes.index'));
    }
}
