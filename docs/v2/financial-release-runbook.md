# Financial Feature Release Runbook

Use this runbook to make financial capabilities available in production on Laravel Cloud. It covers checking release readiness, mapping ledger accounts, recording owner evidence, and enabling capabilities. The process was first completed on 12 October 2026 for release revision `fdcc2af97ab507b6829c4667d0616a2e28d38f3a`.

Recording evidence never enables a feature. It only moves a capability from **Unavailable** to **Ready to enable**. An Admin activates a capability separately by publishing Business Settings.

## How readiness works

`FinancialReleaseEvidenceService::checks()` marks a capability **Ready to enable** only when all of these hold:

| Gate                 | Requirement                                                                                                                                                                                                                                                                      |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Release revision     | `FINANCIAL_RELEASE_REVISION` is set in the environment.                                                                                                                                                                                                                          |
| Ledger mappings      | Every ledger account except `business_bank_ngn` and `payment_clearing_ngn` has `mapping_status = mapped`. Non-cash collections also need those two accounts mapped.                                                                                                              |
| Owner evidence       | The capability has the latest accepted evidence for all six owner roles: `finance_mapping`, `delegated_permissions`, `retention_key_custody`, `operations`, `acceptance` and `enablement`. That evidence must be intact, unexpired and bound to the **current dependency hash**. |
| Method prerequisites | `collection_transfer`, `collection_pos` and `collection_other` need the non-cash switch and a configured evidence scanner. `withdrawal_transfer` needs an enabled, certified bank payout provider.                                                                               |

The **dependency hash** is a digest of the applied migrations, ledger mappings (including `mapping_status`), cash and collection method versions, and `FINANCIAL_RELEASE_REVISION`. Changing any of them makes all recorded evidence stale. **Map ledger accounts before recording evidence, never after.**

Evidence rows are immutable and versioned per capability and role. A new decision is recorded as the next version, and older versions stay as history.

The `READINESS_LOCAL_OVERRIDE`, `COLLECTIONS_LOCAL_CERTIFIED` and `COLLECTION_EVIDENCE_SCANNER_FAKE` flags are honoured only in `local` and `testing`. They have no effect in production.

## Prerequisites

- The release is deployed, and `main` holds the revision you are certifying.
- The Cloud CLI is authenticated. Check with `./vendor/bin/cloud environment:list --json -n`. An HTTP 302 "redirected" error means the token has expired, so log in again.
- An active Admin with the Business Settings permission exists in production. You need their user ID.
- The owner checks the evidence describes have actually been completed.

## 1. Prepare the notes file

Owner evidence text lives in `saverapp-evidence/notes.json`. The folder is git-ignored and must not be committed.

```bash
php release-evidence.php --template > saverapp-evidence/notes.json
```

- `defaults.<role>` applies one role's text to every capability.
- `capabilities.<capability>.<role>` overrides it for one capability.
- Every capability/role pair must end up with text of at most 10,000 characters.
- Text should name the accountable owner and state what was checked. It should also say that recording does not enable features.

To check that the file covers every pair, run a dry run with a placeholder hash into an empty scratch folder:

```bash
php release-evidence.php --notes=saverapp-evidence/notes.json --hash=$(printf 'a%.0s' {1..64}) --out=/tmp/evidence-dry-run
```

It should report 120 evidence files (20 capabilities × 6 roles). Delete the dry-run folder afterwards.

## 2. Identify the environment and actor

```bash
./vendor/bin/cloud environment:list --json -n
```

Use the `id` of the `production` environment, for example `env-a2ee2935-f236-45a1-aedc-b31072d3cc6b`.

```bash
./vendor/bin/cloud command:run <ENV_ID> --cmd="php artisan tinker --execute 'echo App\Models\User::where(\"email\", \"<ADMIN_EMAIL>\")->value(\"id\");'" --json -n
```

## 3. Confirm the release revision

Set `FINANCIAL_RELEASE_REVISION` in Cloud to the deployed commit SHA, then redeploy so the new config value is loaded. Do this before mapping or recording. Changing it later invalidates the evidence.

## 4. Check current readiness (read-only)

```bash
./vendor/bin/cloud command:run <ENV_ID> --cmd="php artisan financial:readiness" --json -n
```

This prints the environment, release revision, dependency hash, unmapped ledger accounts, and the state and blocker for each capability. It changes nothing.

## 5. Map ledger accounts and record evidence

Save the script below as `saverapp-evidence/release-<n>/record.sh`, set the variables at the top, and run it from the project root:

```bash
chmod 700 saverapp-evidence/release-<n>/record.sh
```

```bash
saverapp-evidence/release-<n>/record.sh
```

The script does two things:

1. It marks the remaining ledger accounts as mapped, which is the `finance_mapping` owner's decision. This changes the dependency hash, which is why it runs first.
2. It records the evidence with `financial:readiness`, one capability per Cloud command. Sending the whole notes file at once (about 59 KB as base64) risks the Cloud command size limit. The command records the next version for each role, uses the current hash, and skips evidence that is already current. That makes the script safe to re-run after a failure.

```bash
#!/usr/bin/env bash
set -euo pipefail

ENVIRONMENT="<ENV_ID>"
ACTOR=<ADMIN_USER_ID>
VALID_UNTIL="<YYYY-MM-DD HH:MM:SS>"   # UTC; keep one value for the whole release
NOTES="saverapp-evidence/notes.json"
CLOUD="${CLOUD:-./vendor/bin/cloud}"
CAPABILITIES="collection_cash withdrawal_cash plan_creation collections payout_execution reversal_posting statement_pdf report_exports manual_charges fee_refunds cash_disbursements retention_restore collection_transfer collection_pos collection_other timezone withdrawal_transfer customer_registration transactional_email emergency_recovery"

run() {
  echo "== $1"
  local result
  result="$("$CLOUD" command:run "$ENVIRONMENT" --cmd="$2" --json -n)"
  if ! printf '%s' "$result" | php -r '$lines = preg_split("/\R/", trim(stream_get_contents(STDIN))); $r = json_decode((string) end($lines), true); echo is_array($r) ? trim($r["output"] ?? "") : implode(PHP_EOL, $lines), PHP_EOL; exit((($r["exitCode"] ?? 1) === 0) ? 0 : 1);'; then
    echo "Failed on $1; stopping. Fix the cause and re-run (current evidence is skipped)." >&2
    exit 1
  fi
}

run "map ledger accounts" "php artisan financial:readiness --map-ledger --actor=$ACTOR --force"

for capability in $CAPABILITIES; do
  payload="$(php -r '
    $notes = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
    $capability = $argv[2];
    echo base64_encode(json_encode([
        "defaults" => $notes["defaults"],
        "capabilities" => isset($notes["capabilities"][$capability]) ? [$capability => $notes["capabilities"][$capability]] : (object) [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  ' "$NOTES" "$capability")"
  run "$capability" "php artisan financial:readiness --capability=$capability --notes=base64:$payload --actor=$ACTOR --valid-until='$VALID_UNTIL' --force"
done

echo "Done. Check Business Settings; financial flags are unchanged."
```

Keep `CAPABILITIES` in sync with `FinancialReleaseEvidenceService::CAPABILITIES`.

`cloud command:run --json` prints several JSON lines (status updates, then the result), so the script reads only the last line.

## 6. Verify

```bash
./vendor/bin/cloud command:run <ENV_ID> --cmd="php artisan financial:readiness" --json -n
```

Every capability without a method prerequisite should show **Ready to enable**, with `Unmapped ledger accounts: none`.

## 7. Enable in Business Settings

1. Sign in to production as the Admin and open **Business Settings** (`admin/business-settings`).
2. Create a draft, enable the capabilities you want to activate, preview it, and publish it. Start with a small set and widen it in stages.
3. To roll back, publish a version with the affected capabilities disabled, or use the version rollback in Business Settings.

## After the release

- **Redeploys**: a deploy that adds a migration, changes mappings or method versions, or changes `FINANCIAL_RELEASE_REVISION` moves capabilities back to Unavailable. Run step 4 again, then re-run the step 5 script. It records new versions against the new hash and skips anything still current.
- **Expiry**: evidence lapses at `VALID_UNTIL`. Renew before then by re-running step 5 with a later date.
- **Method prerequisites**: configure the evidence scanner (non-cash collections) or a certified bank payout provider (`withdrawal_transfer`). Then re-run step 4. If those changes alter the dependency hash, also re-run step 5.

## Troubleshooting

| Symptom                                                                   | Cause and fix                                                                                                                                                                                  |
| ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Blocker `versioned implementation and verified mappings`                  | Ledger accounts are unmapped or `FINANCIAL_RELEASE_REVISION` is blank. Run step 4 to see which, then step 5.                                                                                   |
| Blocker lists owner roles after recording                                 | The evidence is stale because the hash changed after it was recorded, usually by mapping afterwards. Re-run the step 5 script.                                                                 |
| `Owner evidence versions are immutable.`                                  | A row with the same version but different content (often a different `valid_until`) already exists. Use `financial:readiness`, which records the next version, instead of a fixed `--version`. |
| `Evidence must identify a current release…`                               | The hash changed between reading it and recording. Re-run the step 5 script, which reads the hash at record time.                                                                              |
| `The actor is not an active Admin with the business settings permission.` | Wrong `ACTOR`, or that user lacks the permission in production.                                                                                                                                |
| Cloud CLI `HTTP 302 … redirected`                                         | The CLI token has expired or lacks access. Log in again and re-run. Completed items are skipped.                                                                                               |
| Blocker `evidence scanner` / `enabled and certified bank payout provider` | An infrastructure prerequisite, not evidence. See "Method prerequisites" above.                                                                                                                |

## Alternative: `release-evidence.php` per-record files

`php release-evidence.php --notes=… --hash=<hash> --out=<empty dir> [--cloud --environment=<ENV_ID> --actor=<id>]` writes one JSON record per capability/role, or a `cloud-record.sh` script, for `php artisan financial:release-evidence --file=…`. It binds every record to the hash you pass and to a fixed `--version`. It does not map ledger accounts. It is error-prone if the hash changes or a version already exists, so prefer step 5.
