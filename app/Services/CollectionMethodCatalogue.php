<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerEntrySide;
use App\Models\AuditEvent;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CollectionMethodCatalogue
{
    /** @var list<string> */
    public const PUBLICATION_FIELDS = ['publication_reference', 'method_key', 'version', 'label', 'custody_account_code',
        'mapping_version', 'destination_key', 'attachment_required', 'reason'];

    /** @param array<string, mixed> $data */
    public function publish(User $actor, array $data): int
    {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $data): int {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AuthorizationService::class)->allows($actor, AdminPermission::BusinessSettingsManage), 403);
            abort_unless(app(FreshAuthenticationService::class)->isFresh($actor, request()), 403);
            foreach (array_diff(array_keys($data), self::PUBLICATION_FIELDS) as $field) {
                throw ValidationException::withMessages([$field => 'This field is not supported for method publication.']);
            }
            $hash = AuditProjection::digest(['actor' => $actor->id, ...$data]);
            $existing = DB::table('collection_method_versions')->where('publication_reference', $data['publication_reference'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('The method publication reference already identifies another request.');
                }

                return $existing->id;
            }
            $allowed = match ($data['method_key']) {
                'transfer' => ['business_bank_ngn'],
                'pos' => ['payment_clearing_ngn'],
                'other' => ['agent_receivable_ngn', 'business_bank_ngn', 'payment_clearing_ngn'],
                default => [],
            };
            if (! in_array($data['custody_account_code'], $allowed, true) || trim($data['destination_key']) === '' || trim($data['reason']) === '') {
                throw new ConflictHttpException('Choose a supported custody pattern and a verified destination.');
            }
            $latest = (int) DB::table('collection_method_versions')->where('method_key', $data['method_key'])->max('version');
            if ($data['version'] !== $latest + 1) {
                throw new ConflictHttpException('Publish the next method version.');
            }
            $this->assertMapping($data['custody_account_code'], $data['mapping_version'], true);
            $id = DB::table('collection_method_versions')->insertGetId([
                ...$data, 'reason' => Crypt::encryptString($data['reason']), 'payload_hash' => $hash,
                'published_by_user_id' => $actor->id, 'effective_at' => now(), 'created_at' => now(),
            ]);
            AuditEvent::record('collection.method_published', 'collection_method_version', $id, $data['publication_reference'],
                ['method_key' => $data['method_key'], 'version' => $data['version'], 'custody_account_code' => $data['custody_account_code'], 'mapping_version' => $data['mapping_version']], $actor);

            return $id;
        }, attempts: 3);
    }

    /** @return list<array{id: int, method_key: string, label: string, destination_key: string, attachment_required: bool}> */
    public function available(): array
    {
        if (! config('collections.noncash_enabled')) {
            return [];
        }
        $available = [];
        foreach (DB::table('collection_method_versions')->orderBy('method_key')->orderByDesc('version')->get() as $candidate) {
            try {
                $method = $this->configured($candidate->id, false);
                app(BusinessSettings::class)->ensureFeature('collection_'.$method->method_key);
            } catch (HttpException) {
                continue;
            }
            $available[] = ['id' => (int) $method->id, 'method_key' => $method->method_key, 'label' => $method->label,
                'destination_key' => $method->destination_key, 'attachment_required' => (bool) $method->attachment_required];
        }

        return $available;
    }

    public function resolve(int $id, bool $lock = false): \stdClass
    {
        if (! config('collections.noncash_enabled')) {
            throw new ConflictHttpException('Noncash collections are unavailable.');
        }

        return $this->configured($id, $lock);
    }

    public function settlementDestination(int $id, bool $lock = false): \stdClass
    {
        $method = $this->configured($id, $lock);
        if ($method->method_key !== 'transfer' || $method->custody_account_code !== 'business_bank_ngn') {
            throw new ConflictHttpException('Choose a configured bank destination for clearing settlement.');
        }

        return $method;
    }

    private function configured(int $id, bool $lock): \stdClass
    {
        $query = DB::table('collection_method_versions')->where('id', $id);
        $method = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($method === null || now()->parse($method->effective_at)->isFuture()) {
            throw new ConflictHttpException('The selected collection method is unavailable.');
        }
        $this->assertMapping($method->custody_account_code, (int) $method->mapping_version, $lock);

        return $method;
    }

    private function assertMapping(string $code, int $version, bool $lock): void
    {
        $query = LedgerAccount::query()->where('code', $code);
        $account = ($lock ? $query->lockForUpdate() : $query)->first();
        $expectedClass = $code === 'agent_receivable_ngn' ? LedgerAccountClass::AgentReceivable : LedgerAccountClass::Asset;
        if ($account === null || $account->version !== $version || $account->mapping_status !== 'mapped'
            || $account->account_class !== $expectedClass || $account->normal_balance !== LedgerEntrySide::Debit
            || $account->currency !== 'NGN' || $account->retired_at !== null || $account->effective_at?->isFuture()) {
            throw new ConflictHttpException('The collection custody mapping is unavailable or has changed.');
        }
    }
}
