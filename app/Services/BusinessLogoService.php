<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BusinessLogoService
{
    public const MAX_FILE_SIZE_BYTES = 2 * 1024 * 1024;

    public const MIN_DIMENSION = 128;

    public const MAX_DIMENSION = 2048;

    public const DIRECTORY = 'business-logos';

    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Decode, re-encode and store an uploaded logo as an immutable, content-addressed private asset.
     *
     * Re-encoding from decoded pixels discards EXIF/XMP/ICC metadata and any non-image payload.
     *
     * @throws ValidationException
     */
    public function store(UploadedFile $file, User $actor): string
    {
        $path = $file->getRealPath();
        if ($file->getSize() === false || $file->getSize() > self::MAX_FILE_SIZE_BYTES || $path === false) {
            $this->fail('The logo must be an image no larger than 2 MB.');
        }
        $info = @getimagesize($path);
        if ($info === false || ! in_array($info[2], self::ALLOWED_TYPES, true)) {
            $this->fail('The logo must be a JPEG, PNG or WebP image.');
        }
        [$width, $height] = $info;
        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            $this->fail('The logo must be between 128 × 128 and 2,048 × 2,048 pixels.');
        }
        $bytes = $this->reencode($this->decode($path, $info[2]));
        $reference = hash('sha256', $bytes);
        $stored = self::DIRECTORY.'/'.$reference.'.png';
        if (! Storage::disk()->exists($stored) && ! Storage::disk()->put($stored, $bytes)) {
            $this->fail('The logo could not be stored. Please retry.');
        }
        AuditEvent::record('business_settings.logo_uploaded', self::class, null, $reference,
            ['changed_fields' => ['logo_reference'], 'outcome' => 'stored'], $actor,
            ['executor' => self::class, 'required_permission' => AdminPermission::BusinessSettingsManage->value]);

        return $reference;
    }

    public function exists(string $reference): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $reference) === 1 && Storage::disk()->exists($this->path($reference));
    }

    public function path(string $reference): string
    {
        return self::DIRECTORY.'/'.$reference.'.png';
    }

    private function decode(string $path, int $type): GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };
        if (! $image instanceof GdImage) {
            $this->fail('The logo image could not be decoded.');
        }

        return $image;
    }

    private function reencode(GdImage $image): string
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        $encoded = imagepng($image, null, 9);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        if (! $encoded || $bytes === '') {
            $this->fail('The logo image could not be processed.');
        }

        return $bytes;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['logo' => $message]);
    }
}
