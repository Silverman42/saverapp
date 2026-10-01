<?php

namespace App\Console\Commands;

use App\Jobs\RenderFinancialArtifact;
use App\Models\FinancialArtifact;
use App\Services\FinancialArtifactService;
use App\Services\PlatformGuard;
use Illuminate\Console\Command;

class DrainFinancialArtifacts extends Command
{
    protected $signature = 'financial-artifacts:drain {--limit=20 : Maximum queued documents to dispatch}';

    protected $description = 'Recover queued financial documents and expire eligible report files';

    public function handle(FinancialArtifactService $artifacts): int
    {
        app(PlatformGuard::class)->assertAllowed('derived');
        foreach (FinancialArtifact::query()->where('status', 'queued')->orderBy('id')->limit(min(100, max(1, (int) $this->option('limit'))))->get(['id', 'render_generation']) as $artifact) {
            RenderFinancialArtifact::dispatch($artifact->id, $artifact->render_generation);
        }
        $artifacts->expire();
        $artifacts->discardOrphanGenerations();

        return self::SUCCESS;
    }
}
