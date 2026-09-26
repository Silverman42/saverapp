<?php

namespace App\Console\Commands;

use App\Services\PlatformState;
use App\Support\PlatformBlocked;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SetPlatformMode extends Command
{
    protected $signature = 'platform:set-mode {mode} {--operation=} {--expected-version=} {--operator=} {--reason=} {--incident=} {--expires-at=} {--json}';

    protected $description = 'Apply an audited, version-checked infrastructure platform mode transition';

    public function handle(PlatformState $state): int
    {
        try {
            $result = $state->transition(['mode' => $this->argument('mode'), 'operation_id' => $this->option('operation'),
                'expected_version' => $this->option('expected-version'), 'operator' => $this->option('operator'),
                'reason' => $this->option('reason'), 'incident' => $this->option('incident'), 'expires_at' => $this->option('expires-at')]);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

            return self::SUCCESS;
        } catch (ValidationException) {
            $this->error('Invalid platform transition. Supply a supported mode, UUID, expected version, operator, reason, incident and optional future expiry.');
        } catch (ConflictHttpException|PlatformBlocked $exception) {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
