<?php

/**
 * Generates the owner evidence JSON files consumed by `php artisan financial:release-evidence`.
 *
 * Usage:
 *   php release-evidence.php --template > notes.json
 *   php release-evidence.php --notes=notes.json --hash=<64-char hash> --out=evidence [--valid-until="2027-10-08 00:00:00"] [--version=1]
 *   php release-evidence.php --notes=notes.json --hash=<64-char hash> --out=evidence --cloud --environment=<Cloud environment ID> --actor=<admin user id>
 *
 * With --cloud, no JSON files are written. Instead evidence/cloud-record.sh runs each record through
 * `cloud command:run`, embedding the JSON (base64) in a remote command that writes, records and removes a temp file.
 *
 * notes.json supplies the evidence text. "defaults" applies a role's text to every capability;
 * "capabilities" overrides it for a single capability/role pair. Every pair must end up with text.
 */
const CAPABILITIES = ['collection_cash', 'withdrawal_cash', 'plan_creation', 'collections', 'payout_execution', 'reversal_posting', 'statement_pdf', 'report_exports', 'manual_charges', 'fee_refunds', 'cash_disbursements', 'retention_restore', 'collection_transfer', 'collection_pos', 'collection_other'];

const ROLES = ['finance_mapping', 'delegated_permissions', 'retention_key_custody', 'operations', 'acceptance', 'enablement'];

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

$options = getopt('', ['template', 'notes:', 'hash:', 'out:', 'valid-until:', 'version:', 'cloud', 'environment:', 'actor:']);

if (isset($options['template'])) {
    echo json_encode([
        'defaults' => array_fill_keys(ROLES, ''),
        'capabilities' => array_fill_keys(CAPABILITIES, (object) []),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

foreach (['notes', 'hash', 'out'] as $required) {
    if (! isset($options[$required]) || $options[$required] === '') {
        fail("Missing --{$required}. Run with --template to start a notes file.");
    }
}

$cloud = isset($options['cloud']);
if ($cloud && (! isset($options['environment']) || trim($options['environment']) === '')) {
    fail('--cloud requires --environment (the Cloud environment ID, see `./vendor/bin/cloud environment:list --json -n`).');
}
if ($cloud && ! ctype_digit((string) ($options['actor'] ?? ''))) {
    fail('--cloud requires --actor (the recording admin user ID).');
}

$hash = strtolower(trim($options['hash']));
if (! preg_match('/\A[0-9a-f]{64}\z/', $hash)) {
    fail('--hash must be the 64-character value printed by `php artisan financial:release-evidence`.');
}

$version = (int) ($options['version'] ?? 1);
if ((string) $version !== (string) ($options['version'] ?? 1) || $version < 1) {
    fail('--version must be a positive integer.');
}

try {
    $validUntil = new DateTimeImmutable($options['valid-until'] ?? '+1 year', new DateTimeZone('UTC'));
} catch (Exception) {
    fail('--valid-until is not a valid date.');
}
if ($validUntil <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
    fail('--valid-until must be in the future.');
}

$contents = @file_get_contents($options['notes']);
if ($contents === false) {
    fail("Cannot read {$options['notes']}.");
}
try {
    $notes = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fail('Notes file is not valid JSON: '.$exception->getMessage());
}

$unknown = array_merge(
    array_diff(array_keys($notes['defaults'] ?? []), ROLES),
    array_diff(array_keys($notes['capabilities'] ?? []), CAPABILITIES),
);
foreach ($notes['capabilities'] ?? [] as $roles) {
    $unknown = array_merge($unknown, array_diff(array_keys((array) $roles), ROLES));
}
if ($unknown !== []) {
    fail('Unknown capability or role in notes: '.implode(', ', array_unique($unknown)));
}

$records = [];
$missing = [];
foreach (CAPABILITIES as $capability) {
    foreach (ROLES as $role) {
        $evidence = trim((string) ($notes['capabilities'][$capability][$role] ?? $notes['defaults'][$role] ?? ''));
        if ($evidence === '') {
            $missing[] = "{$capability}.{$role}";

            continue;
        }
        if (mb_strlen($evidence) > 10000) {
            fail("Evidence for {$capability}.{$role} exceeds 10000 characters.");
        }
        $records["{$capability}.{$role}.json"] = [
            'capability' => $capability,
            'owner_role' => $role,
            'version' => $version,
            'state' => 'accepted',
            'dependency_hash' => $hash,
            'valid_until' => $validUntil->format('Y-m-d H:i:s'),
            'evidence' => $evidence,
        ];
    }
}
if ($missing !== []) {
    fail('Missing evidence text for: '.implode(', ', $missing));
}

$out = rtrim($options['out'], '/');
if (is_dir($out) && glob("{$out}/*") !== []) {
    fail("{$out} already contains files; use an empty directory.");
}
umask(077);
if (! is_dir($out) && ! mkdir($out, 0700, true)) {
    fail("Cannot create {$out}.");
}

if ($cloud) {
    $environment = escapeshellarg(trim($options['environment']));
    $actor = (int) $options['actor'];
    $script = "#!/usr/bin/env bash\n# Usage: ./cloud-record.sh   (run from the local project root, with the Cloud CLI authenticated)\nset -euo pipefail\n"
        ."CLOUD=\"\${CLOUD:-./vendor/bin/cloud}\"\n"
        ."run() {\n"
        ."  echo \"Recording \$1...\"\n"
        ."  local result\n"
        ."  result=\"\$(\"\$CLOUD\" command:run {$environment} --cmd=\"\$2\" --json -n)\"\n"
        ."  if ! printf '%s' \"\$result\" | php -r '\$r = json_decode(stream_get_contents(STDIN), true); echo trim(\$r[\"output\"] ?? \"\"), PHP_EOL; exit(((\$r[\"exitCode\"] ?? 1) === 0) ? 0 : 1);'; then\n"
        ."    echo \"Failed on \$1; stopping. Fix the cause and re-run (already recorded items are accepted again).\" >&2\n"
        ."    exit 1\n"
        ."  fi\n"
        ."}\n";
    foreach ($records as $file => $record) {
        $payload = base64_encode(json_encode($record, JSON_UNESCAPED_SLASHES));
        $remote = "umask 077 && f=\$(mktemp) && echo {$payload} | base64 -d > \"\$f\" && php artisan financial:release-evidence --actor={$actor} --file=\"\$f\"; s=\$?; rm -f \"\$f\"; exit \$s";
        $script .= 'run '.escapeshellarg(basename($file, '.json')).' '.escapeshellarg($remote)."\n";
    }
    file_put_contents("{$out}/cloud-record.sh", $script);
    chmod("{$out}/cloud-record.sh", 0700);

    echo count($records)." evidence records written to {$out}/cloud-record.sh (version {$version}, valid until {$validUntil->format('Y-m-d H:i:s')} UTC).".PHP_EOL;
    echo "Run it from the project root: {$out}/cloud-record.sh".PHP_EOL;
    exit(0);
}

foreach ($records as $file => $record) {
    file_put_contents("{$out}/{$file}", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

$script = "#!/usr/bin/env bash\n# Usage: ./record.sh <admin user id>   (run from the Laravel app root)\nset -euo pipefail\n"
    ."ACTOR=\"\${1:?Pass the recording admin user id}\"\nDIR=\"\$(cd \"\$(dirname \"\$0\")\" && pwd)\"\n";
foreach (array_keys($records) as $file) {
    $script .= "php artisan financial:release-evidence --actor=\"\$ACTOR\" --file=\"\$DIR/{$file}\"\n";
}
file_put_contents("{$out}/record.sh", $script);
chmod("{$out}/record.sh", 0700);

echo count($records)." evidence files written to {$out}/ (version {$version}, valid until {$validUntil->format('Y-m-d H:i:s')} UTC).".PHP_EOL;
echo "Record them from the app root with: {$out}/record.sh <admin user id>".PHP_EOL;
