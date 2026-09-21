# AUTHZ-T05 — Security Operations Enforcement

## Summary

Require the effective `security.operations.manage` permission for lockout visibility, manual unlocks, and privileged Admin password-reset alerts. Preserve the existing routes while adding commit-time authorization, structured identity-verification evidence, and immediate permission-revocation behavior.

## Interfaces and Data

- Preserve:
    - `GET /admin/lockouts` → `admin.lockouts.index`
    - `POST /admin/lockouts/{user}/unlock` → `admin.lockouts.unlock`
- Require effective `security.operations.manage` for both endpoints; unauthorized authenticated requests return `403`.
- Manual-unlock payload:
    - `category`: required `password`, `mfa`, or `recovery_code`.
    - `verification_method`: required enum value: `in_person`, `verified_phone_callback`, `approved_video_call`, `approved_documents`, or `other_approved_method`.
    - `reason`: trimmed, required, 5–255 characters.
- Add nullable `authentication_locks.unlock_verification_method` for legacy compatibility. Future manual unlocks must persist the method, detail, actor, and timestamp.
- Expose verification-method options and recorded method labels through Inertia; do not store submitted credentials, authentication codes, document contents, or other verification secrets.

## Implementation Changes

- Add a focused manual-unlock form request that performs request-time permission authorization and enum-backed validation.
- Refactor `AuthenticationAbuseService::manualUnlock()` to:
    - Lock actor and target users deterministically, then lock matching active restriction rows.
    - Re-run `AuthorizationService` for `SecurityOperationsManage` inside the transaction.
    - Reject self-unlock and final-active-Admin unlock with `403`; reject expired or missing matching restrictions with `422`.
    - Clear only the requested restriction category and matching abuse counters while preserving passwords, MFA, account state, roles, permissions, assignments, and unrelated restrictions.
    - Persist verification method and reason atomically and queue the account-owner notification only after commit.
- Gate the lockout index before querying any security records. Hide the Lockouts sidebar item unless the shared effective-permissions prop contains `security.operations.manage`.
- Update the lockout dialog to use the existing Select component, required free-text detail, server validation errors, and Wayfinder routes. Display the restriction category as read-only instead of allowing arbitrary category entry.
- Change Admin password-reset alert recipients to active, synchronized Admins who currently hold an effective direct `security.operations.manage` grant; exclude the affected Admin and all baseline-only, role-inherited, restricted, inactive, drifted, retired-grant, or revoked recipients.
- Keep the existing queueable email notification. In-app delivery and canonical security audit events remain deferred to AUTHZ-T08 and AUTH-T11.
- Update the Module 03 plan and task-register status and evidence after verification.

## Test Plan

- Verify guest, Customer, Agent, baseline Admin, role-inherited Admin, restricted Admin, drifted Admin, and direct authorized Admin access to both endpoints.
- Verify missing or invalid category, verification method, and reason return field-level errors without changing database rows, caches, or notifications.
- Verify category-specific unlocks, persisted verification evidence, owner notification, self- and final-Admin denial, no-op rejection, and preservation of unrelated account and authentication state.
- Verify permission revocation or restriction after opening the page prevents submission through commit-time reauthorization with no partial effects.
- Verify Admin password-reset alerts reach only currently effective security-permission holders, exclude the affected Admin, and preserve the owner's normal reset-success notification.
- Verify Inertia prop shapes, permission-aware navigation, Wayfinder submission, method selection, validation feedback, and dark and responsive rendering.
- Run focused Authz, abuse-protection, and password-reset Pest tests; then the complete compact suite, PHPStan, `npm run check`, `npm run types:check`, `npm run build`, `php artisan authz:check-role-sync`, and Pint.

## Assumptions

- Manual unlock does not require fresh password or TOTP authentication; the specification requires permission plus independently completed identity verification.
- One manual action clears all active records for the selected user and restriction category, not unrelated categories.
- Existing historical unlocks retain a null verification method; no inferred backfill is performed.
- No new dependencies or permission codes are introduced.
