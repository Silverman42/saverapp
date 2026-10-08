<?php

namespace App\Console\Commands;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\FinancialReleaseEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

#[Signature('financial:readiness
    {--local : Map ledger accounts and record local development evidence (local and testing environments only)}
    {--notes= : Owner evidence notes JSON file, or base64:<encoded JSON> when the file cannot be uploaded (e.g. Laravel Cloud)}
    {--map-ledger : Mark the ledger accounts the selected capabilities depend on as mapped before recording}
    {--actor= : ID of the Admin recording the evidence (defaults to the first authorized Admin with --local)}
    {--capability=* : Limit to these capabilities (defaults to every financial release capability)}
    {--valid-until= : Evidence expiry boundary (defaults to one year from now)}
    {--force : Skip the production confirmation prompt}')]
#[Description('Show financial release readiness and, when asked, map ledger accounts and record owner evidence; never enables financial flags')]
class FinancialReadiness extends Command
{
    private const LOCAL_EVIDENCE = 'Local development evidence only; not a production release certification.';

    private const NONCASH_CAPABILITIES = ['collection_transfer', 'collection_pos', 'collection_other'];

    public function handle(FinancialReleaseEvidenceService $evidence): int
    {
        $capabilities = $this->option('capability') ?: FinancialReleaseEvidenceService::CAPABILITIES;
        $unknown = array_diff($capabilities, FinancialReleaseEvidenceService::CAPABILITIES);
        if ($unknown !== []) {
            $this->error('Unknown capability: '.implode(', ', $unknown).'. Known: '.implode(', ', FinancialReleaseEvidenceService::CAPABILITIES).'.');

            return self::FAILURE;
        }

        $this->showStatus($evidence, $capabilities);

        $local = (bool) $this->option('local');
        $mapLedger = $local || (bool) $this->option('map-ledger');
        if (! $local && $this->option('notes') === null && ! $mapLedger) {
            return self::SUCCESS;
        }

        if ($local && $this->option('notes') !== null) {
            return $this->failWith('Use either --local or --notes, not both.');
        }
        if ($local && ! app()->environment(['local', 'testing'])) {
            return $this->failWith('Local evidence may only be recorded in local or testing environments. Use --notes with real owner evidence.');
        }
        if (blank(config('app.financial_release_revision'))) {
            return $this->failWith('Set FINANCIAL_RELEASE_REVISION (and refresh cached config or redeploy) before recording readiness.');
        }

        $actor = $this->resolveActor($local);
        if ($actor === null) {
            return $this->failWith($local
                ? 'No active Admin with the business settings permission exists. Bootstrap one first or pass --actor.'
                : 'Pass --actor with the ID of an active Admin holding the business settings permission.');
        }

        try {
            $notes = $local ? null : $this->readNotes($capabilities);
            $validUntil = CarbonImmutable::parse($this->option('valid-until') ?? 'now +1 year')->utc();
        } catch (Throwable $exception) {
            return $this->failWith($exception->getMessage());
        }
        if ($validUntil->isPast()) {
            return $this->failWith('--valid-until must be in the future.');
        }

        $accounts = $mapLedger ? $evidence->unmappedLedgerAccounts($local || array_intersect($capabilities, self::NONCASH_CAPABILITIES) !== []) : [];
        if (! $mapLedger && ($notes !== null) && $evidence->unmappedLedgerAccounts() !== []) {
            return $this->failWith('Ledger accounts are still unmapped. Add --map-ledger: mapping changes the dependency hash, so it must happen before evidence is recorded.');
        }

        if (! $local && ! $this->confirmProduction($actor, $accounts, $notes !== null ? count($capabilities) : 0, $validUntil)) {
            return self::FAILURE;
        }

        try {
            if ($accounts !== []) {
                $mapped = $evidence->mapLedgerAccounts($actor, $accounts);
                $this->info("Mapped {$mapped} ledger account(s): ".implode(', ', $accounts).'.');
            }
            if ($local || $notes !== null) {
                [$recorded, $skipped] = $this->recordEvidence($evidence, $actor, $capabilities, $notes, $validUntil);
                $this->info("Recorded {$recorded} owner evidence record(s); {$skipped} were already current.");
            }
        } catch (HttpException $exception) {
            return $this->failWith($exception->getStatusCode() === 403
                ? 'The actor is not an active Admin with the business settings permission.'
                : $exception->getMessage());
        }

        $this->newLine();
        $this->showStatus($evidence, $capabilities);
        $this->comment('Financial flags are unchanged. Enable ready capabilities in Business Settings.');

        return self::SUCCESS;
    }

    /** @param list<string> $capabilities */
    private function showStatus(FinancialReleaseEvidenceService $evidence, array $capabilities): void
    {
        $revision = config('app.financial_release_revision');
        $unmapped = $evidence->unmappedLedgerAccounts(true);
        $this->line('Environment: '.app()->environment());
        $this->line('Release revision: '.(blank($revision) ? '<error>not set (FINANCIAL_RELEASE_REVISION)</error>' : $revision));
        $this->line('Dependency hash: '.$evidence->dependencyHash());
        $this->line('Unmapped ledger accounts: '.($unmapped === [] ? 'none' : implode(', ', $unmapped)));
        $checks = $evidence->checks();
        $this->table(['Capability', 'State', 'Blocker'], array_map(fn (string $capability): array => [
            $capability, $checks[$capability]['state'], $checks[$capability]['blocker'] ?: '-',
        ], $capabilities));
    }

    private function resolveActor(bool $local): ?User
    {
        $authorization = app(AuthorizationService::class);
        if ($this->option('actor') !== null) {
            $actor = User::query()->find($this->option('actor'));

            return $actor !== null && $authorization->allows($actor, AdminPermission::BusinessSettingsManage) ? $actor : null;
        }
        if (! $local) {
            return null;
        }

        return User::query()->where('user_type', 'admin')->orderBy('id')->get()
            ->first(fn (User $user): bool => $authorization->allows($user, AdminPermission::BusinessSettingsManage));
    }

    /**
     * Read evidence text for every selected capability and owner role from the notes file.
     *
     * @param  list<string>  $capabilities
     * @return array<string, array<string, string>>|null
     *
     * @throws JsonException
     */
    private function readNotes(array $capabilities): ?array
    {
        $source = $this->option('notes');
        if ($source === null) {
            return null;
        }
        if (str_starts_with($source, 'base64:')) {
            $contents = base64_decode(substr($source, 7), true);
            if ($contents === false) {
                throw new InvalidArgumentException('The base64 notes are not valid base64.');
            }
        } else {
            if (! is_file($source) || ! is_readable($source)) {
                throw new InvalidArgumentException("Cannot read notes file {$source}.");
            }
            $contents = (string) file_get_contents($source);
        }
        $raw = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        $defaults = (array) ($raw['defaults'] ?? []);
        $overrides = (array) ($raw['capabilities'] ?? []);

        $unknown = array_merge(array_diff(array_keys($defaults), FinancialReleaseEvidenceService::ROLES), array_diff(array_keys($overrides), FinancialReleaseEvidenceService::CAPABILITIES));
        foreach ($overrides as $roles) {
            $unknown = array_merge($unknown, array_diff(array_keys((array) $roles), FinancialReleaseEvidenceService::ROLES));
        }
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown capability or role in notes: '.implode(', ', array_unique($unknown)).'.');
        }

        $notes = [];
        $missing = [];
        foreach ($capabilities as $capability) {
            foreach (FinancialReleaseEvidenceService::ROLES as $role) {
                $text = trim((string) ($overrides[$capability][$role] ?? $defaults[$role] ?? ''));
                if ($text === '') {
                    $missing[] = "{$capability}.{$role}";
                } elseif (mb_strlen($text) > 10000) {
                    throw new InvalidArgumentException("Evidence for {$capability}.{$role} exceeds 10000 characters.");
                } else {
                    $notes[$capability][$role] = $text;
                }
            }
        }
        if ($missing !== []) {
            throw new InvalidArgumentException('Missing evidence text for: '.implode(', ', $missing).'.');
        }

        return $notes;
    }

    /** @param list<string> $accounts */
    private function confirmProduction(User $actor, array $accounts, int $capabilityCount, CarbonImmutable $validUntil): bool
    {
        $summary = "Recording as {$actor->email} in ".app()->environment().': '
            .($accounts === [] ? 'no ledger mapping changes' : 'map '.implode(', ', $accounts))
            .($capabilityCount > 0 ? "; owner evidence for {$capabilityCount} capability(ies), valid until {$validUntil->toDateTimeString()} UTC" : '').'.';
        $this->warn($summary);
        if ($this->option('force')) {
            return true;
        }
        if (! $this->input->isInteractive()) {
            $this->error('Re-run with --force to confirm non-interactively.');

            return false;
        }

        return $this->confirm('These owner attestations are permanent. Continue?');
    }

    /**
     * @param  list<string>  $capabilities
     * @param  array<string, array<string, string>>|null  $notes
     * @return array{int, int}
     */
    private function recordEvidence(FinancialReleaseEvidenceService $evidence, User $actor, array $capabilities, ?array $notes, CarbonImmutable $validUntil): array
    {
        $hash = $evidence->dependencyHash();
        $recorded = 0;
        $skipped = 0;
        foreach ($capabilities as $capability) {
            foreach (FinancialReleaseEvidenceService::ROLES as $role) {
                if ($evidence->hasCurrentEvidence($capability, $role)) {
                    $skipped++;

                    continue;
                }
                $evidence->record($actor, [
                    'capability' => $capability, 'owner_role' => $role, 'version' => $evidence->nextVersion($capability, $role),
                    'state' => 'accepted', 'dependency_hash' => $hash, 'valid_until' => $validUntil->toDateTimeString(),
                    'evidence' => $notes[$capability][$role] ?? self::LOCAL_EVIDENCE,
                ]);
                $recorded++;
            }
        }

        return [$recorded, $skipped];
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
