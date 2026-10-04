# Modules 05–07 — Remaining Acceptance Plan

Prepared 4 October 2026 from the current [V2 register](../tasks.md) and the Module 05, 06 and 07 acceptance records. Module specifications remain the source of truth; every task below follows [the implementation workflow](../implementation-workflow.md).

## 1. Current position

| Module | Matrix                            | Open scenarios                                       |
| ------ | --------------------------------- | ---------------------------------------------------- |
| 05     | 47 Verified / 1 Partial           | FEE-AC-042                                           |
| 06     | 50 Verified / 1 Partial           | TPC-AC-048                                           |
| 07     | 60 Passed / 0 Partial / 3 Blocked (COL-AC-040 passed 4 Oct 2026) | COL-AC-018, 059, 062 (Blocked) |

Final shared PHP suite: 2,075 passed / 31,940 assertions, 170 environment skips exercised separately. Approved policy acceptance is complete (54 / 519). Main database and schema are unchanged.

No open scenario needs new financial behavior. Everything left is evidence that the local synthetic environment cannot produce by itself:

| Gap                                    | Scenarios                                                   | Workstream |
| -------------------------------------- | ----------------------------------------------------------- | ---------- |
| Operational malware scanner            | COL-AC-040                                                  | WS1        |
| Protected-file upload/download UI      | COL-AC-040                                                  | WS1        |
| Live assistive-technology (AT) results | FEE-AC-042, TPC-AC-048, COL-AC-018, COL-AC-059, COL-AC-062  | WS2        |
| Agreed authenticated concurrent load   | COL-AC-062                                                  | WS3        |
| Register certification                 | All three modules                                           | WS4        |

Real provider reconciliation, production release flags, deployment and offboarding remain release-owner dependencies tracked in [financial-workflow-release-readiness.md](./financial-workflow-release-readiness.md). They are not part of this plan, and closing this plan does not certify a production release.

## 2. Decisions required before work starts

Each one needs user approval. The recommended default is listed first.

| ID | Decision                     | Recommended default                                                                                                                                                                                               | Why approval is needed                                    |
| -- | ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| D1 | Scanner for QA               | ClamAV via Homebrew, QA only. Use `clamdscan` (daemon) if `clamscan` signature loading approaches the 30 s `collections.evidence_scan_timeout`. Both match the existing `--no-summary --infected` / exit-1 contract. | Installs a system package and downloads signatures.       |
| D2 | AT device profile            | Agent surfaces: TalkBack + Chrome on Android. Admin/Customer surfaces: VoiceOver + Safari on macOS. VoiceOver + Safari on iOS for Customer card/receipt.                                                         | Needs real devices and a person who can listen to speech. |
| D3 | Concurrent workload          | 30 concurrent authenticated Agent sessions (one per fixture Agent) on the existing 10,000 / 30 / 20,000 / 2,000,000 dataset. 2-minute ramp, then 10 minutes steady. Each session loops workspace → search → preview → receipt commit with 5–15 s think time. 3 Admin sessions read directories in parallel. Same p95 targets: 3 s / 1 s / 2 s. | The spec says "agreed" profile; the numbers are a business choice. |
| D4 | Load tooling                 | No new dependency: an env-gated Pest test that drives real HTTP sessions with Laravel `Http::pool` against the Herd-served QA host.                                                                                | `k6` or similar tools would be a new dependency.          |
| D5 | Load-user authentication     | Sign the synthetic QA Agents in through the normal Fortify flow, using fixture TOTP secrets where MFA is enforced. Never bypass auth middleware.                                                                  | Confirms that synthetic MFA secrets are acceptable in QA. |
| D6 | Device network profile       | Chrome DevTools "Fast 4G" throttling at 390 × 844 for the device-side p95 sample, 20 samples per operation.                                                                                                      | The spec requires an agreed mobile-network profile.       |

Unknowns that could change the design: whether ClamAV signature loading fits the timeout (D1), whether all Agents have MFA enforced (D5), and whether the Herd QA host can sustain 33 PHP-FPM workers (WS3 step 2).

## 3. Workstreams

### WS1 — Protected evidence and operational scanner (COL-AC-040)

```text
Task: Module 07 "Verify and certify each Module 07 acceptance scenario" — COL-AC-040
Result: A real scanner rejects infected and unscannable files and accepts clean ones in isolated QA; actual upload/download UI passes for Agent, Admin and Customer roles.
Source: Module 07 COL-024, COL-AC-040; config/collections.php; App\Services\CollectionEvidenceScanner
Scope: QA environment configuration, one new env-gated test file, browser acceptance; no financial owner changes
Reference: tests/Feature/CollectionLoadProfileTest.php (env-gated isolated test pattern)
Dependencies: D1 approval; COLLECTIONS_NONCASH_ENABLED in QA only
Verification: live scanner test, existing evidence tests, browser acceptance at 390 px with keyboard
```

1. **Install and configure (after D1).** Install ClamAV, run `freshclam`, and record the exact version string. In QA `.env` only, set `COLLECTION_EVIDENCE_SCANNER_BINARY` to the absolute binary path and `COLLECTION_EVIDENCE_SCANNER_VERSION` to that version string. Time one cold scan; switch to `clamdscan` if it takes more than about 15 s.
2. **Live scanner test.** Add `tests/Feature/CollectionEvidenceScannerLiveTest.php`, skipped unless `COLLECTION_EVIDENCE_SCANNER_LIVE=1` and the configured binary exists. Cover these cases against the real binary:
   - The EICAR test string, generated at runtime and never committed, raises the `files` validation error.
   - A clean PNG and a clean PDF return the configured version.
   - A missing or unreadable signature database returns exit 2, which maps to 503 "Evidence scanning is unavailable."
   - A timeout maps to 503.
   - Through the HTTP owner, an infected upload leaves no staged file, no evidence row and no posting.
3. **UI acceptance in isolated QA (noncash enabled).**
   - Agent uploads clean evidence through `collections/Create.vue` and sees the scanned file in `collections/evidence/Show.vue`.
   - Uploading an infected file shows a focused, announced error and keeps the draft.
   - An Admin reviewer downloads through a signed link. A stale or expired link and a tampered signature are denied.
   - A Customer is denied (403).
   - Run every step at 390 px, keyboard only. Check that no credential or storage path appears in props or URLs.
4. **Evidence.** Update the COL-AC-040 row with scanner name and version, signature date, test counts and browser results. State clearly that this certifies isolated QA only. Production scanner deployment stays with the release owner.

### WS2 — Live assistive-technology acceptance (FEE-AC-042, TPC-AC-048, COL-AC-018, COL-AC-059, COL-AC-062 accessibility clause)

Speech output cannot be verified by automation. This workstream has three parts: Claude prepares scripts and a pre-pass, a person runs the device session, and then the two together fix and retest.

1. **Pre-pass (Claude, no device needed).** For each surface below, use the built-in browser accessibility tree at 390 px to check:
   - accessible names
   - roles
   - `aria-invalid` and error associations
   - live-region announcements
   - focus order and focus return

   Fix concrete defects with the smallest frontend change, following `.ai/rules/pages.md`. After each fix, run Vue types, scoped lint/format and a production build. This step removes avoidable failures before device time, but it does not certify anything.
2. **Scripts.** Write one checklist per surface, with the expected announcement for each step:

   | Surface | Roles | What must be announced |
   | ------- | ----- | ---------------------- |
   | Fees register (`admin` fees pages) | Admin; Agent/Customer waived views | Filter controls and applied state; ordering change; whole-register totals; pagination position; adjustment dialog (open, invalid field, Checking/unknown outcome, cancel); grant-loss access alert; waived NGN 500 terms with zero unpaid |
   | Plans (`plans/Index`, `Show`, `Create`, `Edit`) | Admin, Agent | Date-filter labels and range error; empty and paginated results; loading lock on create/edit; owner validation messages; Pause/Resume conflict alert and reload outcome |
   | Thrift card (`collections/Card.vue`) | Agent, Customer | Each slot's date, state (Paid / Partial / Missed / Pending / Blocked) and amount as text, not colour alone; paused interval announced as blocked |
   | Collection entry (`collections/Create.vue`, four Agent entrypoints) | Agent | Same form reached from dashboard, Customer profile, daily list and card; preview totals; submit live notice; uncertain-outcome guidance; unavailable ledger read as "unavailable", never zero |
   | Receipt (`collections/Show.vue`) | Customer, Agent | Original tender and current savings; no recording controls for Customer; access-loss message after reassignment |

3. **Device session (person, D2 profile).** Run each script on the agreed device and screen reader. Record pass/fail per step, the device and OS version, the screen-reader version, the date, and any speech that was wrong.
4. **Fix and retest.** Treat each failure as a separate small frontend task with its own brief. Retest only the failed steps on the same device profile.
5. **Evidence.** Update each scenario row with the device profile and results. COL-AC-062's accessibility clause closes from the collection-entry and card results.

### WS3 — Agreed authenticated concurrent workload (COL-AC-062)

```text
Task: Module 07 "Verify and certify each Module 07 acceptance scenario" — COL-AC-062
Result: The agreed D3 profile meets the p95 targets with real authenticated sessions, and produces no duplicate or unbalanced postings under concurrency.
Source: Module 07 COL-040, COL-AC-062, Section 18 measured targets
Scope: one new env-gated Pest test; isolated saverapp_audit_testing database and QA host only
Reference: tests/Feature/CollectionLoadProfileTest.php (dataset seeding, p95 calculation, artifact, rollback/teardown)
Dependencies: D3, D4, D5, D6
Verification: the profile run itself, then existing collection/history regressions and the MySQL concurrency suite
```

1. **Fixture reuse.** Extract the dataset seeding already used by `CollectionLoadProfileTest` into the existing `Tests\CreatesLifecycleCustomers` trait, or a sibling trait in `tests/`, so that both profiles seed the same data. The single-client test must keep passing unchanged.
2. **Host capacity check.** Before measuring, confirm that the QA host's PHP-FPM worker count and MySQL `max_connections` can serve 33 sessions. If they cannot, record the limit and agree an adjusted D3. A miss is documented, never hidden.
3. **Concurrent profile test.** Add `tests/Feature/CollectionConcurrentSessionProfileTest.php`, skipped unless `COLLECTION_CONCURRENT_PROFILE=1` and the database is `saverapp_audit_testing`. The test:
   - signs in each Agent over HTTP through the normal flow (D5);
   - runs the D3 loop through `Http::pool`, with a unique idempotency key per receipt;
   - records per-operation latencies;
   - writes a JSON artifact (p50/p95/max, errors, throughput) to `/private/tmp`.
4. **Integrity assertions after the run.**
   - Exactly one receipt per idempotency key.
   - Every journal group is balanced.
   - No slot is overfunded.
   - Rebuilt card and batch projections match the authoritative ledger.
   - No 5xx responses other than documented, classified 503s.
   - Teardown empties every measured table.
5. **Device-side sample (D6).** Take 20 throttled 390 px samples per operation in the browser while the concurrent load runs, and record p95 against the same targets.
6. **Evidence.** Update COL-AC-062 with the artifact path, profile parameters, p95 values and integrity results. Keep the earlier single-client results as separate evidence.

### WS4 — Certification and register closeout

1. Move a scenario to Verified/Passed only when its row contains the evidence its workstream requires.
2. When a module has no open scenarios, move its register rows to Completed:
   - Module 05: "Complete cross-module acceptance evidence".
   - Module 06: "Translate plan lifecycle…".
   - Module 07: "Implement the gated cash collection release…", "Verify and certify…" and "Add withdrawal-aware reversal compensation and noncash methods".
   - The "Implemented for cash" rows keep their cash scope unless their noncash owners are separately verified.
3. Run Pint, PHPStan, Vue types, scoped lint and a production build. Ask the user to run the complete `php artisan test --compact` suite.
4. Read-only check of the main database: zero plans and fee obligations, and none of the QA-only migrations applied.
5. Hand release items to `financial-workflow-release-readiness.md`: the production scanner, release flags, provider reconciliation and deployment.

## 4. Sequence

| Step | Work                                                   | Owner             | Depends on   |
| ---- | ------------------------------------------------------ | ----------------- | ------------ |
| 1    | Approve D1–D6                                          | User              | —            |
| 2    | WS3 steps 1–4 (largest code change)                    | Claude            | D3, D4, D5   |
| 3    | WS1 steps 1–2                                          | Claude            | D1           |
| 4    | WS2 pre-pass and scripts                               | Claude            | —            |
| 5    | WS1 step 3 and WS3 step 5 (browser acceptance)         | Claude            | Steps 2–3, D6 |
| 6    | WS2 device session                                     | User / AT tester  | Step 4, D2   |
| 7    | WS2 fixes and retest                                   | Claude + tester   | Step 6       |
| 8    | WS4 closeout                                           | Claude, then user (full suite) | Steps 2–7 |

Steps 2–4 can proceed in parallel once their decisions are approved. Only step 6 needs a person with devices; everything else can run in the existing isolated QA environment.

## 5. Boundaries

- No financial owner, ledger posting or policy behavior changes. If a workstream finds a defect, that defect becomes its own focused brief.
- Isolated QA database, storage and keys only. No main database migrations, no main release-flag or grant changes, and no real mail or provider calls.
- No new Composer or npm dependencies. The ClamAV system package needs D1 approval.
- Do not commit generated EICAR files, credentials or TOTP secrets.
