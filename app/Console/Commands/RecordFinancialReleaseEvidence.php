<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FinancialReleaseEvidenceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('financial:release-evidence {--actor= : Authorized recording user ID} {--file= : Structured owner evidence JSON file}')]
#[Description('Inspect current release dependency hash or append supplied owner evidence; never enables financial flags')]
class RecordFinancialReleaseEvidence extends Command
{
    public function handle(FinancialReleaseEvidenceService $evidence): int
    {
        if (! $this->option('file')) {
            $this->line($evidence->dependencyHash());

            return self::SUCCESS;
        }
        $contents = file_get_contents($this->option('file'));
        if ($contents === false) {
            $this->error('Cannot read owner evidence.');

            return self::FAILURE;
        }
        $data = Validator::make(json_decode($contents, true, 16, JSON_THROW_ON_ERROR), [
            'capability' => ['required', 'string'], 'owner_role' => ['required', 'string'], 'version' => ['required', 'integer', 'min:1'],
            'state' => ['required', 'string'], 'dependency_hash' => ['required', 'string', 'size:64'],
            'valid_until' => ['required', 'date', 'after:now'], 'evidence' => ['required', 'string', 'max:10000'],
        ])->validate();
        $id = $evidence->record(User::query()->findOrFail($this->option('actor')), ['capability' => $data['capability'], 'owner_role' => $data['owner_role'], 'version' => $data['version'], 'state' => $data['state'], 'dependency_hash' => $data['dependency_hash'], 'valid_until' => $data['valid_until'], 'evidence' => $data['evidence']]);
        $this->info('Owner evidence recorded: '.$id.'. Financial flags are unchanged.');

        return self::SUCCESS;
    }
}
