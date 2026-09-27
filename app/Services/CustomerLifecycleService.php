<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Http\Requests\CustomerLifecycleRequest;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CustomerLifecycleService extends CustomerStatusManagementService
{
    /** @param array<string, mixed> $input
     * @return array{attempt_reference: string, action: string, status: string, version: int, history_id: int}
     */
    public function execute(User $actor, CustomerProfile $customer, string $action, array $input): array
    {
        if (! in_array($action, ['archive', 'restore'], true)) {
            throw new \InvalidArgumentException('Unknown Customer lifecycle action.');
        }
        $input['reason'] = is_string($input['reason'] ?? null) ? trim($input['reason']) : ($input['reason'] ?? null);
        $input['customer_explanation'] = is_string($input['customer_explanation'] ?? null) ? trim($input['customer_explanation']) : ($input['customer_explanation'] ?? null);
        $input = Validator::make($input, (new CustomerLifecycleRequest)->rules())->validate();
        $hash = hash('sha256', json_encode([$actor->id, $customer->id, $action,
            $input['version'], $input['assignment_version'], trim($input['reason']), trim($input['customer_explanation'])], JSON_THROW_ON_ERROR));

        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $customer, $action, $input, $hash): array {
            $context = $this->actionAuthorizationGuard->lockAndAuthorize($actor, $customer->id, 'manageLifecycle');
            $existing = DB::table('customer_lifecycle_operations')->where('attempt_reference', $input['attempt_reference'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->actor_user_id !== $context->actor->id || (int) $existing->customer_profile_id !== $customer->id
                    || $existing->action !== $action || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('This operation reference belongs to a different lifecycle request.');
                }

                return $this->result($existing);
            }
            $profile = $context->customerProfile;
            if ($profile->version !== (int) $input['version'] || $context->currentAssignment?->version !== ($input['assignment_version'] === null ? null : (int) $input['assignment_version'])) {
                throw new ConflictHttpException('Customer or assignment changed. Refresh and review this action again.');
            }
            if ($action === 'archive') {
                if (! in_array($profile->operational_status, [CustomerStatus::Active, CustomerStatus::Inactive], true)) {
                    throw new ConflictHttpException('Only Active or Inactive Customers may be archived. Resolve any restriction first.');
                }
                $eligibility = app(CustomerLifecycleEligibility::class)->preview($context->actor, $profile, true);
                if (! $eligibility['eligible']) {
                    throw ValidationException::withMessages(['lifecycle' => ['Archival is blocked until every financial check is verified clear.']]);
                }
                app(CustomerNameCorrectionService::class)->cancelForArchival($profile, $context->actor);
                $target = CustomerStatus::Archived;
            } else {
                if ($profile->operational_status !== CustomerStatus::Archived) {
                    throw new ConflictHttpException('Only Archived Customers may be restored to Inactive.');
                }
                if ($context->currentAgentProfile !== null) {
                    $agentUser = User::query()->whereKey($context->currentAgentProfile->user_id)->lockForUpdate()->firstOrFail();
                    $context->currentAgentProfile->setRelation('user', $agentUser);
                }
                $this->ensureCurrentAgentIsEligible($context->currentAgentProfile);
                $target = CustomerStatus::Inactive;
            }
            $profile = $this->persistTransition($context, $target, $input['reason'], $input['customer_explanation'], false);
            $historyId = (int) $profile->statusHistories()->latest('id')->value('id');
            DB::table('customer_lifecycle_operations')->insert([
                'attempt_reference' => $input['attempt_reference'], 'actor_user_id' => $context->actor->id,
                'customer_profile_id' => $profile->id, 'action' => $action, 'payload_hash' => $hash,
                'committed_version' => $profile->version, 'customer_status_history_id' => $historyId,
                'created_at' => now(),
            ]);

            return ['attempt_reference' => $input['attempt_reference'], 'action' => $action, 'status' => $target->value, 'version' => $profile->version, 'history_id' => $historyId];
        }, attempts: 3);
    }

    /** @return array{attempt_reference: string, action: string, status: string, version: int, history_id: int}|null */
    public function lookup(User $actor, CustomerProfile $customer, string $reference): ?array
    {
        Gate::forUser($actor)->authorize('manageLifecycle', $customer);
        $operation = DB::table('customer_lifecycle_operations')->where('attempt_reference', $reference)
            ->where('customer_profile_id', $customer->id)->where('actor_user_id', $actor->id)->first();

        return $operation === null ? null : $this->result($operation);
    }

    /** @return array{attempt_reference: string, action: string, status: string, version: int, history_id: int} */
    private function result(\stdClass $operation): array
    {
        return ['attempt_reference' => $operation->attempt_reference, 'action' => $operation->action,
            'status' => $operation->action === 'archive' ? 'archived' : 'inactive',
            'version' => (int) $operation->committed_version, 'history_id' => (int) $operation->customer_status_history_id];
    }
}
