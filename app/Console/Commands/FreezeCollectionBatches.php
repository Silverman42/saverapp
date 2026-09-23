<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\CollectionBatch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('collections:freeze-batches')]
#[Description('Freeze cash collection batches after their captured local day ends')]
class FreezeCollectionBatches extends Command
{
    public function handle(): int
    {
        if (! config('collections.enabled')) {
            return self::SUCCESS;
        }

        CollectionBatch::query()->where('status', 'open')->orderBy('id')->chunkById(100, function ($batches): void {
            foreach ($batches as $batch) {
                if ($batch->received_date >= CarbonImmutable::now($batch->timezone)->toDateString()) {
                    continue;
                }
                DB::transaction(function () use ($batch): void {
                    $current = CollectionBatch::query()->whereKey($batch->id)->lockForUpdate()->first();
                    if ($current === null || $current->status !== 'open') {
                        return;
                    }
                    $current->status = 'ready_for_review';
                    $current->frozen_at = now();
                    $current->version++;
                    $current->save();
                    AuditEvent::record('collection.batch_frozen', CollectionBatch::class, $current->id,
                        (string) $current->id, ['agent_profile_id' => $current->agent_profile_id,
                            'received_date' => $current->received_date, 'revision' => $current->revision]);
                }, attempts: 3);
            }
        });

        return self::SUCCESS;
    }
}
