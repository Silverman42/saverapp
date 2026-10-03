<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class CollectionEvidenceScanner
{
    public function scan(string $absolutePath): string
    {
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
}
