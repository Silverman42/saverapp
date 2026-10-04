<?php

use App\Services\CollectionEvidenceScanner;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $binary = env('COLLECTION_EVIDENCE_SCANNER_BINARY');
    if (env('COLLECTION_EVIDENCE_SCANNER_LIVE') !== '1' || ! is_string($binary) || ! is_executable($binary)) {
        $this->markTestSkipped('Requires an operational scanner: set COLLECTION_EVIDENCE_SCANNER_LIVE=1 and COLLECTION_EVIDENCE_SCANNER_BINARY.');
    }
    $this->scannerBinary = $binary;
    $this->directory = sys_get_temp_dir().'/scanner-live-'.Str::uuid();
    mkdir($this->directory, 0700, true);
    config()->set('collections.evidence_scanner_binary', $binary);
    config()->set('collections.evidence_scanner_version', 'live-scanner-test');
});

afterEach(function (): void {
    if (isset($this->directory) && is_dir($this->directory)) {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }
});

/** Wraps the real scanner in a script so the unchanged service argument contract can be exercised under failure. */
function scannerLiveWrapper(string $directory, string $body): string
{
    $path = $directory.'/wrapper.sh';
    file_put_contents($path, "#!/bin/sh\n".$body."\n");
    chmod($path, 0700);

    return $path;
}

test('live scanner accepts a clean image and a clean pdf', function (): void {
    $png = $this->directory.'/clean.png';
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    $pdf = $this->directory.'/clean.pdf';
    file_put_contents($pdf, "%PDF-1.1\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    expect(app(CollectionEvidenceScanner::class)->scan($png))->toBe('live-scanner-test')
        ->and(app(CollectionEvidenceScanner::class)->scan($pdf))->toBe('live-scanner-test');
});

test('live scanner rejects the standard antivirus test string without returning a version', function (): void {
    $path = $this->directory.'/eicar.txt';
    file_put_contents($path, strrev('*H+H$!ELIF-TSET-SURIVITNA-DRADNATS-RACIE$}7)CC7)^P(45XZP\4[PA@%P!O5X'));

    expect(fn () => app(CollectionEvidenceScanner::class)->scan($path))
        ->toThrow(ValidationException::class, 'did not pass its security scan');
});

test('live scanner reports unavailable when signatures cannot be loaded', function (): void {
    $empty = $this->directory.'/empty-database';
    mkdir($empty);
    config()->set('collections.evidence_scanner_binary', scannerLiveWrapper($this->directory,
        'exec '.escapeshellarg($this->scannerBinary).' --database='.escapeshellarg($empty).' "$@"'));
    $path = $this->directory.'/clean.txt';
    file_put_contents($path, 'plain evidence');

    expect(fn () => app(CollectionEvidenceScanner::class)->scan($path))
        ->toThrow(ServiceUnavailableHttpException::class, 'Evidence scanning is unavailable.');
    rmdir($empty);
});

test('live scanner reports unavailable when the scan times out', function (): void {
    config()->set('collections.evidence_scanner_binary', scannerLiveWrapper($this->directory, 'sleep 5'));
    config()->set('collections.evidence_scan_timeout', 1);
    $path = $this->directory.'/clean.txt';
    file_put_contents($path, 'plain evidence');

    expect(fn () => app(CollectionEvidenceScanner::class)->scan($path))
        ->toThrow(ServiceUnavailableHttpException::class, 'Evidence scanning is unavailable.');
});
