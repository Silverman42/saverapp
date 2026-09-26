<?php

namespace App\Services;

use App\Enums\PlatformMode;
use App\Models\AuditEvent;
use App\Support\PlatformBlocked;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class PlatformState
{
    public function current(bool $sharedLock = false): stdClass
    {
        try {
            $query = DB::table('platform_state')->where('id', 1);
            $state = ($sharedLock ? $query->sharedLock() : $query)->first();
            if ($state === null || PlatformMode::tryFrom($state->mode) === null || (int) $state->version < 1 || (int) $state->catalogue_version !== PlatformCatalogue::VERSION) {
                throw new PlatformBlocked('platform_state_unavailable');
            }

            return $state;
        } catch (PlatformBlocked $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PlatformBlocked('platform_state_unavailable');
        }
    }

    /** @return array{mode: string, version: int|null, message: string, observed_at: string} */
    public function publicStatus(): array
    {
        try {
            $state = $this->current();
            $mode = PlatformMode::from($state->mode);
            $version = (int) $state->version;
        } catch (PlatformBlocked) {
            $mode = PlatformMode::Unavailable;
            $version = null;
        }

        return ['mode' => $mode->value, 'version' => $version, 'message' => $mode->message(), 'observed_at' => now()->utc()->toIso8601String()];
    }

    /** @param array<string, mixed> $input
     * @return array{mode: string, version: int, operation_id: string, expires_at: string|null}
     */
    public function transition(array $input): array
    {
        $input = Validator::make($input, [
            'mode' => ['required', 'string', 'in:'.implode(',', array_column(PlatformMode::cases(), 'value'))],
            'operation_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'],
            'operator' => ['required', 'string', 'max:200'], 'reason' => ['required', 'string', 'max:1000'],
            'incident' => ['required', 'string', 'max:200'], 'expires_at' => ['nullable', 'date'],
        ])->validate();
        foreach (['operator', 'reason', 'incident'] as $field) {
            if (trim($input[$field]) === '' || preg_match('/[\x00-\x1F\x7F]/', $input[$field])) {
                throw ValidationException::withMessages([$field => 'Use a nonempty reference without control characters.']);
            }
            $input[$field] = trim($input[$field]);
        }
        $input['expires_at'] = empty($input['expires_at']) ? null : CarbonImmutable::parse($input['expires_at'])->utc()->toIso8601String();
        $input['expected_version'] = (int) $input['expected_version'];
        ksort($input);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $hash): array {
            $state = DB::table('platform_state')->where('id', 1)->lockForUpdate()->first();
            if ($state === null || PlatformMode::tryFrom($state->mode) === null || (int) $state->catalogue_version !== PlatformCatalogue::VERSION) {
                throw new PlatformBlocked('platform_state_unavailable');
            }
            $existing = DB::table('platform_operations')->where('operation_id', $input['operation_id'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->input_hash, $hash)) {
                    throw new ConflictHttpException('This platform operation belongs to different input.');
                }

                return json_decode($existing->result, true, flags: JSON_THROW_ON_ERROR);
            }
            if ($input['expires_at'] !== null && CarbonImmutable::parse($input['expires_at'])->isPast()) {
                throw ValidationException::withMessages(['expires_at' => 'Use a future restriction expiry.']);
            }
            if ((int) $state->version !== $input['expected_version']) {
                throw new ConflictHttpException('Platform state changed. Read its current version before retrying.');
            }
            $version = (int) $state->version + 1;
            $audit = AuditEvent::record('platform.mode_changed', 'platform', 1, null,
                ['from_mode' => $state->mode, 'to_mode' => $input['mode'], 'from_version' => (int) $state->version, 'to_version' => $version,
                    'reason' => $input['reason'], 'before_values' => ['operator' => $input['operator'], 'incident' => $input['incident']]], null,
                ['executor' => self::class, 'operation_id' => $input['operation_id']]);
            DB::table('platform_transitions')->insert([
                'version' => $version, 'from_mode' => $state->mode, 'to_mode' => $input['mode'], 'operation_id' => $input['operation_id'],
                'evidence' => Crypt::encryptString(json_encode(['operator' => $input['operator'], 'reason' => $input['reason'], 'incident' => $input['incident']], JSON_THROW_ON_ERROR)),
                'expires_at' => $input['expires_at'] === null ? null : CarbonImmutable::parse($input['expires_at']),
                'audit_event_id' => $audit->id, 'created_at' => now(),
            ]);
            DB::table('platform_state')->where('id', 1)->update(['mode' => $input['mode'], 'version' => $version,
                'expires_at' => $input['expires_at'] === null ? null : CarbonImmutable::parse($input['expires_at']), 'updated_at' => now()]);
            $result = ['mode' => $input['mode'], 'version' => $version, 'operation_id' => $input['operation_id'], 'expires_at' => $input['expires_at']];
            DB::table('platform_operations')->insert(['operation_id' => $input['operation_id'], 'input_hash' => $hash,
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $result;
        }, attempts: 3);
    }
}
