# AUTHZ-T02 — Fixed Roles and Bootstrap Grants

## Summary

Create the fixed `customer`, `agent`, and `admin` Spatie roles, synchronize each account to exactly one role matching `user_type`, and explicitly grant the complete permission catalogue to the deterministic bootstrap Admin.

## Implementation Changes

- Add a transactional data migration that:
    - Creates the three `web` roles from `UserType`.
    - Rejects unknown roles, invalid user types, or conflicting existing assignments.
    - Backfills exactly one matching role for every existing user.
    - Selects the earliest active Admin by `created_at`, then `id`, and grants all 13 `AdminPermission` values directly.
    - Leaves `role_has_permissions` empty and clears Spatie's permission cache after direct backfills.
- Add append-only bootstrap grant-history records sharing one batch identifier, with `system_seed` as the source and one record per permission. This becomes the history foundation extended by `AUTHZ-T04`.
- Add an application role-synchronization service used when users are created:
    - Assign the single role matching `user_type`.
    - Reject post-creation `user_type` changes.
    - Provide an idempotent bootstrap-Admin method for the future first-Admin provisioner when no active Admin exists during migration.
    - Do not automatically give ordinary newly created Admins any granular permissions.
- Strengthen role middleware to require both the requested `user_type` and exactly one matching Spatie role; missing, mismatched, or additional roles fail closed.
- Add the read-only `authz:check-role-sync` command. It returns success only when the fixed role catalogue, all user-role mappings, direct-grant boundaries, empty role-permission pivot, and bootstrap grant provenance are valid; it reports drift without repairing it.
- Update the implementation register and Module 03 plan status and evidence after implementation verification.

## Public Interfaces

- `RoleSynchronizationService` exposes initial-role synchronization, synchronization checking, and idempotent bootstrap-Admin provisioning.
- `UserType` supplies the canonical Spatie role name using its existing backed value.
- `php artisan authz:check-role-sync` provides a non-mutating deployment and operational diagnostic.
- No new HTTP routes, frontend interfaces, or user-facing permission controls are introduced.

## Test Plan

- Verify exactly three fixed `web` roles exist and no role inherits granular permissions.
- Verify Customer, Agent, and Admin creation produces exactly one matching role while ordinary Admins receive no direct grants.
- Verify changing `user_type` is rejected without modifying either the user or role pivots.
- Verify role middleware permits synchronized users and denies missing, mismatched, and additional-role drift.
- Verify migration backfill, transaction rollback on conflicting data, deterministic bootstrap selection, all 13 direct grants, shared `system_seed` history, cache invalidation, and the no-Admin case.
- Verify bootstrap provisioning is idempotent and rejects non-active or non-Admin targets.
- Verify the diagnostic command's clean and drifted exit paths and confirm it never mutates data.
- Run the focused authorization tests, full compact Pest suite, PHPStan, the diagnostic command, and `vendor/bin/pint --dirty --format agent`.

## Assumptions and Deferred Work

- `AUTHZ-T02` is the next task after completed `AUTHZ-T01`.
- Unexpected pre-existing roles or conflicting role assignments fail migration rather than being silently deleted or converted.
- If no active Admin exists, this task creates no account; the future first-Admin workflow must call the bootstrap service.
- Permission versions, Gate evaluation, session refresh, restrictions, and runtime permission management remain in `AUTHZ-T03` and `AUTHZ-T04`.
