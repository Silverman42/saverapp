# AUTHZ-T04 — Admin Permission Management

## Summary

Add the Admin access directory, permission detail/history view, and atomic permission-editing workflow. Permission changes require `admins.manage`, fresh password-plus-TOTP authentication, explicit review, and optimistic concurrency. Existing Spatie pivots, permission versions, restrictions, and grant-history tables are reused; no migration is required.

## Interfaces and Authorization

- Add:
    - `GET /admin/access` → `admin.access.index`
    - `GET /admin/access/{admin}` → `admin.access.show`
    - `PUT /admin/access/{admin}/permissions` → `admin.access.permissions.update`
    - `GET|POST /user/fresh-authentication` → shared role-aware step-up flow.
- Any active, synchronized Admin may view the directory and concise responsibility summaries. An Admin may view their own exact permissions. Viewing another Admin's full grants/history or changing them requires effective `admins.manage`.
- Permission updates accept the complete desired `AdminPermission[]`, a 1–500 character reason, `expected_permission_version`, and an accepted confirmation flag. Empty permission sets are valid.
- Return `403` for missing authority, restrictions, or self-management; `404` for non-Admin targets; and field-level `422` errors for invalid codes, duplicates, stale versions, no-op submissions, or continuity violations.
- Inertia props expose catalogue metadata, current/effective permissions, restriction state, permission version, management/freshness flags, and paginated newest-first history. The directory exposes only concise summaries for other Admins.

## Implementation Changes

- Add a permission-management service that transactionally locks the actor, target, active-Admin continuity set, relevant restrictions, and current direct grants in deterministic order. At commit time it:
    - Re-runs authoritative `admins.manage` authorization.
    - Rejects self-management, role drift, inactive/unknown catalogue entries, and stale versions.
    - Computes grant/revoke differences and rejects no-op changes.
    - Preserves an active, unrestricted holder of `admins.manage`.
    - Calls Spatie `syncPermissions()`, increments the target's permission version exactly once, and appends one history row per difference under a shared UUID batch with source `permission_change`.
- Introduce a shared fresh-authentication service and page. Customers confirm their password; Agents and Admins confirm password plus a replay-protected six-digit TOTP. Recovery codes and trusted-device bypasses do not satisfy step-up authentication.
- Update the `fresh` middleware to require unexpired role-appropriate evidence for the fixed 10-minute window. Failed password/TOTP attempts use the existing abuse controls without clearing login counters; successful confirmation records the appropriate session timestamps and returns to the intended page.
- Build responsive, dark-mode access pages using existing Reka/UI components, Vuelidate, Wayfinder, and server flash toasts. The edit flow shows explicit before/after grants and revocations, highlights `admins.manage` as highest risk, requires a reason, and uses a final confirmation dialog. Stale submissions discard the draft, reload current data, and require a fresh review.
- Add Admin-only navigation to the access directory. Frontend permission checks control presentation only; every route remains server-authoritative.
- Keep permission-change notifications and canonical audit events deferred to `AUTHZ-T08`; local grant/revocation history is written now. Do not implement invitations or account-status management in this task.
- After verification, mark `AUTHZ-T04` completed in the Module 03 plan and task register with test evidence.

## Test Plan

- Verify directory/detail visibility for guests, Customers, Agents, baseline Admins, authorized Admins, self-view, other-Admin view, restricted actors, and direct endpoint calls.
- Verify validation for unknown, retired, duplicate, malformed, empty-reason, unconfirmed, stale-version, no-op, self-target, and non-Admin-target requests.
- Verify grants and revocations update only direct Spatie assignments, create one shared history batch, increment the target version once, preserve unrelated account data, and roll back completely on failure.
- Verify final-capable-Admin continuity, restricted-actor denial, deterministic stale-form rejection, and immediate target-session refresh on the next request.
- Verify Customer password-only step-up, mandatory Admin/Agent password-plus-TOTP, TOTP replay denial, expired freshness, trusted-device exclusion, throttling, and unchanged timestamps after failure.
- Verify Inertia prop shapes, history ordering/pagination, Wayfinder form wiring, confirmation behaviour, high-risk warnings, and flash outcomes.
- Run focused Pest tests, the full compact suite, PHPStan, `npm run check`, `npm run types:check`, `npm run build`, `php artisan authz:check-role-sync`, and Pint.

## Assumptions

- Current authorization foundations are healthy: 37 Authz tests and 498 assertions pass.
- Current-state grants remain in Spatie pivots; `permission_grant_histories` remains append-only evidence and is not the Module 14 audit ledger.
- Only active catalogue permissions may be selected. Retired or unknown grant drift fails closed and is handled through controlled catalogue maintenance.
- An authorized actor counts toward Admin-management continuity; self-management remains prohibited even for the seeded first Admin.
