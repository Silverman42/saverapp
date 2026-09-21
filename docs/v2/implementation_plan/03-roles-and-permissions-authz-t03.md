# AUTHZ-T03 — Central Authorization Kernel

## Summary

Implement the authoritative Admin permission evaluator, temporary authorization restrictions, permission-version session refresh, and reusable authorization evidence. No Admin-management pages or permission-editing endpoints are added in this task.

## Persistence and Restriction Lifecycle

- Add `users.permission_version` as an unsigned integer defaulting to `1`, preserving alignment with existing bootstrap grant history.
- Add `authorization_restrictions` with the target Admin, restriction type, affected `AdminPermission`, source and source reference, start and expiry timestamps, creator, clear metadata, and the permission versions produced when the restriction is applied and cleared.
- Add `AuthorizationRestrictionType::PostRecoveryAdminManagement`. It blocks only `admins.manage` for 24 hours while preserving the underlying direct permission grant.
- Add an idempotent restriction service that:
    - Applies and clears restrictions transactionally while locking the affected user.
    - Increments `permission_version` exactly once per effective restriction transition.
    - Treats elapsed restrictions as ineffective immediately according to their expiry timestamp.
    - Retains cleared and expired rows as authorization history.
- Add the `authz:expire-restrictions` command and schedule it every minute without overlap. The command closes elapsed restrictions and advances each affected Admin's permission version once.

## Authorization and Public Interfaces

- Add an `AuthorizationService` exposing:
    - `allows(User, AdminPermission): bool`.
    - `effectivePermissionCodes(User): array`.
    - `captureEvidence(User, AdminPermission, subject context): AuthorizationEvidence`.
    - `reauthorize(AuthorizationEvidence): bool`.
- Register every `AdminPermission` enum case as a Laravel Gate ability. Authorization requires:
    - An active account with the Admin classification.
    - Exactly one synchronized `admin` role.
    - An active `web` permission definition from the closed catalogue.
    - A direct user grant; role-inherited grants never count.
    - No currently effective restriction for the requested permission.
- Add immutable `App\Support\AuthorizationEvidence` containing the actor ID, exact permission, permission version, subject type, subject ID, subject version, and decision timestamp, with stable array serialization.
- Evidence reauthorization reloads the actor, requires the original permission version, and reruns the current Gate check. Owning modules remain responsible for validating their stored subject version.
- Unknown, retired, wildcard-like, and undefined abilities remain denied with Laravel's standard safe forbidden response.

## Session and Inertia Integration

- Add authenticated middleware after account and session validation that:
    - Initializes `auth.permission_version` without rotating a newly established session.
    - On a version mismatch, destroys the old session ID, regenerates the CSRF token, clears authorization-derived session state, reloads Spatie role and permission relations, and stores the current version before continuing.
- Share only effective permission codes and the current permission version with Inertia:
    - Add the closed `AdminPermission` TypeScript union.
    - Add `permission_version` to the authenticated user contract.
    - Add `auth.permissions`, returning an empty array for guests and users without effective Admin permissions.
    - Do not expose raw grants, restrictions, histories, or inactive permissions.
- Update the task register and Module 03 plan status only after implementation verification succeeds.

## Test Plan

- Exercise all 13 Gates for explicitly granted Admins and verify denial for baseline Admins, Customers, Agents, inactive accounts, role drift, retired permissions, inherited-only permissions, unknown abilities, and active restrictions.
- Verify a post-recovery restriction preserves the direct grant, blocks only `admins.manage`, expires by timestamp, records clear metadata, and changes the permission version once per apply, clear, or expiry transition.
- Verify restriction creation, clearing, and the expiry command are idempotent and concurrency-safe.
- Verify active sessions initialize their version, rotate on mismatch, discard stale authorization state, and expose grants, revocations, restriction starts, and restriction expiry on the next request.
- Verify authorization evidence serialization, version pinning, subject context, and rejection after revocation, restriction, account-state change, or any intervening permission-version change.
- Run the focused Authz tests, the full compact Pest suite, `composer types:check`, `npm run check`, `npm run types:check`, `npm run build`, `php artisan authz:check-role-sync`, and `vendor/bin/pint --dirty --format agent`.

## Assumptions and Deferred Work

- `AUTHZ-T03` follows completed `AUTHZ-T01` and `AUTHZ-T02`; the current focused Authz baseline is 21 passing tests with 287 assertions.
- `AUTHZ-T04` remains responsible for permission mutation, grant and revocation history, continuity safeguards, and the management interface.
- `AUTHZ-T05` consumes these Gates for lockout and security operations.
- Assisted recovery will invoke the restriction service later; this task supplies the restriction primitive without implementing recovery workflows.
- Canonical audit events, notifications, resource policies, and approval-table persistence remain deferred to their owning modules.
