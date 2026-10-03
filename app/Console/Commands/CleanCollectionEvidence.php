<?php

namespace App\Console\Commands;

use App\Services\CollectionEvidenceFiles;
use App\Services\PlatformGuard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('collections:clean-evidence {--limit=500 : Maximum unreferenced files to remove}')]
#[Description('Remove aged unpublished collection evidence files while preserving all durable references')]
class CleanCollectionEvidence extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 5000) {
            $this->error('Choose a cleanup limit between 1 and 5000.');

            return self::FAILURE;
        }
        app(PlatformGuard::class)->assertAllowed('mutation');
        $disk = Storage::disk('collection_evidence');
        $removed = 0;
        foreach ($disk->allFiles('files') as $path) {
            if ($removed >= $limit) {
                break;
            }
            if ($disk->lastModified($path) >= now()->subDay()->timestamp) {
                continue;
            }
            $deleted = app(PlatformGuard::class)->transaction('mutation', function () use ($disk, $path): bool {
                if (app(CollectionEvidenceFiles::class)->isReferenced($path)) {
                    return false;
                }

                return $disk->delete($path);
            });
            $removed += $deleted ? 1 : 0;
        }
        $this->info('Removed '.$removed.' unpublished evidence files.');

        return self::SUCCESS;
    }
}
