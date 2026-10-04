<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Integrity incidents are acknowledged by a person, never by the next successful rebuild. A clean rebuild only marks an incident
 * recovered; an authorized reviewer then resolves it with a note. No resolution edits a balance or a posted row.
 */
class LedgerIntegrityIncidentService
{
    public function __construct(private AuthorizationService $authorization, private FreshAuthenticationService $freshAuthentication) {}

    /** @return list<array<string, mixed>> */
    public function open(int $limit = 20): array
    {
        return array_values(DB::table('ledger_integrity_incidents')->whereIn('status', ['open', 'recovered'])->orderByDesc('detected_at')->limit($limit)->get()
            ->map(fn (object $row): array => ['reference' => $row->incident_reference, 'category' => $row->category, 'status' => $row->status,
                'summary' => $row->summary, 'detected_at' => (string) $row->detected_at, 'recovered_at' => $row->recovered_at])->all());
    }

    public function resolve(User $actor, string $reference, string $note, Request $request): void
    {
        app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $reference, $note, $request): void {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $this->authorization->allows($actor, AdminPermission::ReconciliationManage) || ! $this->freshAuthentication->isFresh($actor, $request)) {
                throw new AuthorizationException('Fresh reconciliation authority is required to resolve an integrity incident.');
            }
            $incident = DB::table('ledger_integrity_incidents')->where('incident_reference', $reference)->lockForUpdate()->first();
            abort_if($incident === null, 404);
            if ($incident->status === 'resolved') {
                return;
            }
            if ($incident->status !== 'recovered') {
                throw new ConflictHttpException('The ledger must verify cleanly before this incident can be resolved.');
            }
            DB::table('ledger_integrity_incidents')->where('id', $incident->id)->update(['status' => 'resolved', 'resolved_at' => now(),
                'resolved_by_user_id' => $actor->id, 'resolution_note' => trim($note), 'updated_at' => now()]);
            AuditEvent::record('ledger.integrity_incident_resolved', LedgerPostingGroup::class, null, $reference,
                ['category' => $incident->category, 'incident_reference' => $reference, 'projection_version' => (int) $incident->projection_version],
                $actor, context: ['executor' => self::class, 'required_permission' => 'reconciliation.manage']);
        }, attempts: 3);
    }
}
