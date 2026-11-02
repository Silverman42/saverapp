<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\FinancialNoteType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\FinancialNote;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Records Admin financial notes. Notes are memos only: nothing here touches the ledger, balances or reports.
 */
class FinancialNoteService
{
    private const EDITABLE_FIELDS = ['type', 'title', 'amount_kobo', 'note_date', 'category', 'body'];

    public function __construct(private AuthorizationService $authorization) {}

    /**
     * @param  array{type: string, title: string, amount_kobo: int, note_date: string, category: ?string, body: string}  $values
     */
    public function create(User $actor, array $values): FinancialNote
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $values): FinancialNote {
            $currentActor = $this->lockAuthorizedActor($actor);

            $note = FinancialNote::create([
                ...$values,
                'business_profile_id' => BusinessProfile::current()->id,
                'reference' => (string) Str::uuid(),
                'status' => FinancialNote::STATUS_ACTIVE,
                'created_by_user_id' => $currentActor->id,
                'updated_by_user_id' => $currentActor->id,
                'version' => 1,
            ]);

            $this->audit('created', $note, $currentActor, self::EDITABLE_FIELDS);

            return $note;
        }, attempts: 3);
    }

    /**
     * @param  array{type: string, title: string, amount_kobo: int, note_date: string, category: ?string, body: string}  $values
     */
    public function update(User $actor, FinancialNote $note, int $version, array $values): FinancialNote
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $note, $version, $values): FinancialNote {
            $currentActor = $this->lockAuthorizedActor($actor);
            $current = $this->lockCurrentVersion($note, $version);
            if ($current->status !== FinancialNote::STATUS_ACTIVE) {
                throw new ConflictHttpException('Restore this note before editing it.');
            }

            $current->fill($values);
            $changedFields = array_values(array_intersect(self::EDITABLE_FIELDS, array_keys($current->getDirty())));
            if ($changedFields === []) {
                return $current;
            }

            $current->updated_by_user_id = $currentActor->id;
            $current->version++;
            $current->save();

            $this->audit('updated', $current, $currentActor, $changedFields);

            return $current;
        }, attempts: 3);
    }

    public function archive(User $actor, FinancialNote $note, int $version): FinancialNote
    {
        return $this->changeStatus($actor, $note, $version, FinancialNote::STATUS_ARCHIVED);
    }

    public function restore(User $actor, FinancialNote $note, int $version): FinancialNote
    {
        return $this->changeStatus($actor, $note, $version, FinancialNote::STATUS_ACTIVE);
    }

    private function changeStatus(User $actor, FinancialNote $note, int $version, string $status): FinancialNote
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $note, $version, $status): FinancialNote {
            $currentActor = $this->lockAuthorizedActor($actor);
            $current = $this->lockCurrentVersion($note, $version);
            if ($current->status === $status) {
                throw new ConflictHttpException('The financial note changed. Refresh before continuing.');
            }

            $archiving = $status === FinancialNote::STATUS_ARCHIVED;
            $current->status = $status;
            $current->archived_at = $archiving ? now() : null;
            $current->archived_by_user_id = $archiving ? $currentActor->id : null;
            $current->updated_by_user_id = $currentActor->id;
            $current->version++;
            $current->save();

            $this->audit($archiving ? 'archived' : 'restored', $current, $currentActor, ['status']);

            return $current;
        }, attempts: 3);
    }

    private function lockAuthorizedActor(User $actor): User
    {
        $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! $this->authorization->allows($currentActor, AdminPermission::FinancialNotesManage)) {
            throw new AuthorizationException;
        }

        return $currentActor;
    }

    private function lockCurrentVersion(FinancialNote $note, int $version): FinancialNote
    {
        $current = FinancialNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
        if ($current->version !== $version) {
            throw new ConflictHttpException('The financial note changed. Refresh before continuing.');
        }

        return $current;
    }

    /**
     * Free text (title, category, body) stays on the note row and out of the audit payload.
     *
     * @param  list<string>  $changedFields
     */
    private function audit(string $action, FinancialNote $note, User $actor, array $changedFields): void
    {
        $type = $note->type instanceof FinancialNoteType ? $note->type->value : (string) $note->type;

        AuditEvent::record('financial_note.'.$action, FinancialNote::class, $note->id, $note->reference, [
            'type' => $type,
            'amount_kobo' => $note->amount_kobo,
            'note_date' => $note->note_date->toDateString(),
            'status' => $note->status,
            'version' => $note->version,
            'changed_fields' => $changedFields,
        ], $actor, context: ['executor' => self::class, 'required_permission' => AdminPermission::FinancialNotesManage->value]);
    }
}
