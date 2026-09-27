<?php

namespace App\Console\Commands;

use App\Services\RecoveryReplay;
use App\Support\PlatformBlocked;
use App\Support\RecoveryConflict;
use Illuminate\Console\Command;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PlatformReplay extends Command
{
    protected $signature = 'platform:replay {action : plan, approve, execute, stop, or resume} {--operation=} {--operator=} {--reason=} {--incident=} {--run=} {--digest=} {--owner=} {--ids= : Comma-separated immutable work IDs} {--limit=100} {--json}';

    protected $description = 'Plan, approve and execute an immutable bounded recovery replay through infrastructure tooling';

    public function handle(RecoveryReplay $replay): int
    {
        try {
            $input = [];
            foreach (['operation', 'operator', 'reason', 'incident', 'run', 'digest', 'owner', 'limit'] as $key) {
                $input[$key] = $this->option($key);
            }
            $input['ids'] = $this->option('ids') === null ? null : explode(',', $this->option('ids'));
            $action = $this->argument('action');
            $result = $replay->command($action, $input);
            if (in_array($action, ['execute', 'resume'], true)) {
                $result = [...$result, ...$replay->execute($result['run'], (int) $input['limit'])];
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

            return self::SUCCESS;
        } catch (ValidationException|RecordNotFoundException|InvalidArgumentException|RecoveryConflict|PlatformBlocked) {
            $this->error('Replay refused: verify the UUID, operator evidence, manifest approval, current owner eligibility and platform mode.');

            return self::FAILURE;
        }
    }
}
