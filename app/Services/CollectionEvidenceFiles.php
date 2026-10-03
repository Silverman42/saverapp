<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToReadFile;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class CollectionEvidenceFiles
{
    public function __construct(private CollectionEvidenceScanner $scanner) {}

    public function isReferenced(string $path): bool
    {
        return DB::table('collection_evidence_files')->where('storage_path', $path)->exists()
            || DB::table('collection_settlement_files')->where('storage_path', $path)->exists();
    }

    public function fileBytes(\stdClass $file): string
    {
        try {
            $bytes = Storage::disk('collection_evidence')->get($file->storage_path);
        } catch (UnableToReadFile $exception) {
            throw new ServiceUnavailableHttpException(null, 'Protected evidence is temporarily unavailable.', $exception);
        }
        if (! is_string($bytes)) {
            throw new ServiceUnavailableHttpException(null, 'Protected evidence is temporarily unavailable.');
        }
        if (! hash_equals($file->checksum, hash('sha256', $bytes)) || strlen($bytes) !== (int) $file->byte_size || blank($file->scanner_version)) {
            throw new ConflictHttpException('The evidence file failed its integrity check.');
        }

        return $bytes;
    }

    /** @return array{storage_path: string, checksum: string, mime_type: string, byte_size: int, scanner_version: string, scanned_at: mixed, created_at: mixed} */
    public function prepareFile(UploadedFile $upload): array
    {
        $mime = $upload->getMimeType();
        if (! $upload->isValid() || $upload->getSize() < 1 || $upload->getSize() > 5120 * 1024
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            throw ValidationException::withMessages(['files' => 'Use valid JPEG, PNG, WebP or PDF files up to 5 MB.']);
        }
        $bytes = $upload->getContent();
        if (($mime === 'application/pdf' && (! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -1024), '%%EOF') || str_contains($bytes, '/Encrypt')))
            || ($mime !== 'application/pdf' && @getimagesize($upload->getPathname()) === false)) {
            throw ValidationException::withMessages(['files' => 'The evidence file is malformed or encrypted.']);
        }
        $path = 'files/'.Str::uuid();
        $disk = Storage::disk('collection_evidence');
        $disk->put($path, $bytes);
        try {
            $checksum = hash('sha256', $bytes);
            $version = $this->scanner->scan($disk->path($path));
            if (! hash_equals($checksum, hash('sha256', $disk->get($path)))) {
                throw new ConflictHttpException('The evidence file changed during scanning.');
            }

            return ['storage_path' => $path, 'checksum' => $checksum, 'mime_type' => $mime, 'byte_size' => strlen($bytes),
                'scanner_version' => $version, 'scanned_at' => now(), 'created_at' => now()];
        } catch (Throwable $exception) {
            $this->removeUnreferencedFile($path);
            throw $exception;
        }
    }

    public function removeUnreferencedFile(string $path): void
    {
        try {
            if (! $this->isReferenced($path)) {
                Storage::disk('collection_evidence')->delete($path);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
