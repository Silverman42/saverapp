<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class CollectionEvidenceScanner
{
    public const LOCAL_FAKE_VERSION = 'local-unscanned';

    /**
     * Whether evidence can be scanned: a configured binary, or the local/testing-only fake.
     */
    public function isConfigured(): bool
    {
        $binary = config('collections.evidence_scanner_binary');
        $version = config('collections.evidence_scanner_version');

        return $this->usesLocalFake() || (is_string($binary) && str_starts_with($binary, '/') && is_string($version) && trim($version) !== '' && strlen($version) <= 100);
    }

    public function scan(string $absolutePath): string
    {
        if ($this->usesLocalFake()) {
            return self::LOCAL_FAKE_VERSION;
        }
        $binary = config('collections.evidence_scanner_binary');
        $version = config('collections.evidence_scanner_version');
        if (! is_string($binary) || ! str_starts_with($binary, '/') || ! is_string($version) || trim($version) === '' || strlen($version) > 100) {
            throw new ServiceUnavailableHttpException(null, 'Evidence scanning is unavailable.');
        }
        try {
            $result = Process::timeout((int) config('collections.evidence_scan_timeout', 30))
                ->run([$binary, '--no-summary', '--infected', $absolutePath]);
        } catch (Throwable $exception) {
            throw new ServiceUnavailableHttpException(null, 'Evidence scanning is unavailable.', $exception);
        }
        if ($result->exitCode() === 1) {
            throw ValidationException::withMessages(['files' => 'The evidence file did not pass its security scan.']);
        }
        if (! $result->successful()) {
            throw new ServiceUnavailableHttpException(null, 'Evidence scanning is unavailable.');
        }

        return $version;
    }

    private function usesLocalFake(): bool
    {
        return config('collections.evidence_scanner_fake') === true && app()->environment(['local', 'testing']);
    }
}
