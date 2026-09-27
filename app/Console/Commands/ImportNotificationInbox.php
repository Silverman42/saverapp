<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationCatalogue;
use App\Services\NotificationPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ImportNotificationInbox extends Command
{
    protected $signature = 'notifications:import {--dry-run : Validate sources without changing any records}';

    protected $description = 'Import verified source-linked database notices without sending historical email';

    public function handle(NotificationCatalogue $catalogue, NotificationPipeline $pipeline): int
    {
        $verified = 0;
        $skipped = 0;
        foreach (NotificationCatalogue::OWNERS as $family => $owner) {
            $query = DB::table($owner['table']);
            if ($family !== 'collection') {
                $query->where('channel', 'database');
            }
            $query->orderBy('id')->chunkById(100, function ($rows) use ($family, $catalogue, $pipeline, &$verified, &$skipped): void {
                foreach ($rows as $row) {
                    $existing = DB::table('notifications')->where('id', $row->notification_id)->first();
                    if ($existing === null && $row->status !== 'pending') {
                        continue;
                    }
                    try {
                        $catalogue->describe($family, $row);
                        if ($existing !== null && ((int) $existing->notifiable_id !== (int) $row->recipient_user_id
                            || $existing->notifiable_type !== (new User)->getMorphClass())) {
                            throw new InvalidArgumentException('Unverified recipient.');
                        }
                        if (! $this->option('dry-run')) {
                            DB::transaction(function () use ($family, $row, $existing, $pipeline): void {
                                $id = $pipeline->capture($family, (int) $row->id, false);
                                if ($id === null) {
                                    return;
                                }
                                $canonical = DB::table('notification_inbox_intents')->where('id', $id)->first();
                                if ($canonical !== null && $existing !== null && $existing->read_at !== null) {
                                    DB::table('notifications')->where('id', $canonical->notification_id)->whereNull('read_at')
                                        ->update(['read_at' => $existing->read_at]);
                                }
                                if ($existing !== null) {
                                    if ($canonical !== null && $canonical->notification_id === $existing->id) {
                                        DB::table('notification_inbox_intents')->where('id', $id)->update(['created_at' => $existing->created_at]);
                                    }
                                    DB::afterCommit(fn () => $pipeline->materialize($id));
                                }
                            });
                        }
                        $verified++;
                    } catch (InvalidArgumentException) {
                        $skipped++;
                    }
                }
            });
        }
        $this->info(($this->option('dry-run') ? 'Dry run: ' : '')."{$verified} verified notices; {$skipped} unsafe or unsupported notices excluded.");

        return self::SUCCESS;
    }
}
