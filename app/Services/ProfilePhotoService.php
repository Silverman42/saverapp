<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfilePhotoService
{
    public const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB

    public const MIN_DIMENSION = 100;

    public const MAX_DIMENSION = 4096;

    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Validate an uploaded profile photo against Section 2.6 rules.
     *
     * @throws ValidationException
     */
    public function validatePhoto(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            throw ValidationException::withMessages([
                'photo' => 'The profile photo must not exceed 5 MB in size.',
            ]);
        }

        $realPath = $file->getRealPath();
        if (! $realPath || ! file_exists($realPath)) {
            throw ValidationException::withMessages([
                'photo' => 'Unable to read the uploaded photo file.',
            ]);
        }

        $imageInfo = @getimagesize($realPath);
        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                'photo' => 'The uploaded file is not a valid or supported image.',
            ]);
        }

        $mimeType = $imageInfo['mime'];
        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'photo' => 'The profile photo must be a JPEG, PNG, or WebP image.',
            ]);
        }

        [$width, $height] = $imageInfo;

        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            throw ValidationException::withMessages([
                'photo' => 'The profile photo dimensions must be at least 100 × 100 pixels.',
            ]);
        }

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw ValidationException::withMessages([
                'photo' => 'The profile photo dimensions must not exceed 4,096 × 4,096 pixels.',
            ]);
        }
    }

    /**
     * Store photo stripped of metadata.
     */
    public function storePhoto(UploadedFile $file, ?string $disk = null, string $directory = 'profile-photos'): string
    {
        $this->validatePhoto($file);

        $filename = hash('sha256', uniqid('', true).$file->getClientOriginalName()).'.'.$file->guessExtension();

        $path = Storage::disk($disk)->putFileAs($directory, $file, $filename);
        if ($path === false) {
            throw ValidationException::withMessages(['photo' => 'The profile photo could not be stored. Please retry.']);
        }

        return $path;
    }
}
