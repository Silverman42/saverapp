<?php

namespace App\Console\Commands;

use App\Services\BusinessBootstrap;
use App\Services\EmergencyRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BootstrapBusiness extends Command
{
    protected $signature = 'business:bootstrap {--display-name= : Reviewed business display name} {--admin-name= : First administrator name} {--admin-email= : First administrator login email} {--password-file= : Private local password file; never a command-line password}';

    protected $description = 'Provision the first Admin through trusted private input and mandatory MFA onboarding';

    public function handle(BusinessBootstrap $bootstrap, EmergencyRecoveryService $emergency): int
    {
        $path = $this->option('password-file');
        $password = null;
        if (is_string($path) && $path !== '') {
            if (! is_file($path) || is_link($path) || ! is_readable($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 4096) {
                $this->error('Use a readable private regular password file of at most 4096 bytes with no group/world permissions.');

                return self::FAILURE;
            }
            $password = rtrim((string) file_get_contents($path), "\r\n");
        } elseif ($this->input->isInteractive()) {
            $password = $this->secret('First Admin password');
        }
        if (! is_string($password) || $password === '') {
            $this->error('A private password file is required for non-interactive provisioning.');

            return self::FAILURE;
        }
        try {
            $admin = $bootstrap->provision((string) $this->option('display-name'), (string) $this->option('admin-name'), (string) $this->option('admin-email'), $password);
        } catch (ValidationException|ConflictHttpException $exception) {
            $this->error($exception instanceof ValidationException ? 'Provisioning input did not satisfy the required identity or password policy.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            unset($password);
        }
        $key = $emergency->issue($admin, 'issued_at_bootstrap');
        $this->info('First Admin provisioned. Mandatory MFA setup is required.');
        $this->warn('Business emergency recovery key. Store it offline now; it is shown once and only its hash is kept:');
        $this->line($key);

        return self::SUCCESS;
    }
}
