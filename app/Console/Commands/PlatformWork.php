<?php

namespace App\Console\Commands;

use App\Services\BackgroundRecovery;
use App\Support\PlatformBlocked;
use App\Support\RecoveryConflict;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PlatformWork extends Command
{
    protected $signature = 'platform:work {--owner=} {--state=} {--limit=100} {--adopt : Adopt a bounded page of existing owner records} {--json}';

    protected $description = 'Inspect safe recovery metadata or adopt a bounded page of existing durable intents';

    public function handle(BackgroundRecovery $recovery): int
    {
        try {
            $input = Validator::make(['owner' => $this->option('owner'), 'state' => $this->option('state'), 'limit' => $this->option('limit')], [
                'owner' => ['nullable', 'in:'.implode(',', BackgroundRecovery::OWNERS)],
                'state' => ['nullable', 'in:queued,running,retry_scheduled,outcome_unknown,succeeded,failed,dead_letter,cancelled'],
                'limit' => ['required', 'integer', 'min:1', 'max:1000'],
            ])->validate();
            $adopted = 0;
            if ($this->option('adopt')) {
                if ($input['owner'] === null) {
                    throw new InvalidArgumentException('Adoption requires an owner.');
                }
                $adopted = $recovery->adopt($input['owner'], (int) $input['limit']);
            }
            $query = DB::table('platform_recovery_work')->select(['id', 'owner', 'source_id', 'source_version', 'adapter_version',
                'correlation_reference', 'state', 'attempts', 'cycle_attempts', 'max_attempts', 'available_at',
                'lease_token', 'lease_expires_at', 'heartbeat_at', 'checkpoint', 'failure_code']);
            if ($input['owner'] !== null) {
                $query->where('owner', $input['owner']);
            }
            if ($input['state'] !== null) {
                $query->where('state', $input['state']);
            }
            $this->line(json_encode(['adopted' => $adopted, 'work' => $query->orderBy('id')->limit((int) $input['limit'])->get()],
                JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

            return self::SUCCESS;
        } catch (ValidationException|InvalidArgumentException|RecoveryConflict|PlatformBlocked) {
            $this->error('Recovery inspection unavailable or invalid options. Adoption requires a supported owner and limit 1–1000.');

            return self::FAILURE;
        }
    }
}
