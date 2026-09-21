# Module 03 — Spatie Roles, Permissions, and Authorization

## Summary

Adopt `spatie/laravel-permission:^8.0` as the persistence and assignment engine for roles and permissions. The existing `users.user_type` remains the immutable account classification, and every user has exactly one synchronized Spatie `web` role: `customer`, `agent`, or `admin`. Spatie direct permissions represent each Admin's explicit granular grants.

The application remains responsible for account-state checks, resource scope, separation of duties, temporary restrictions, permission-version invalidation, continuity safeguards, and historical evidence. Update the repository's existing `AGENTS.md` with binding package-use guidance; no singular `AGENT.md` exists.

## Implementation Tasks

| ID        | Task                                                                                                                                                                                                                | Requirements                                                                                                 | Initial status and dependency                                                                                                                                                                       |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AUTHZ-T01 | Install and configure `spatie/laravel-permission:^8.0`, publish its configuration and migration, add `HasRoles` to `User`, and define the custom Permission model metadata.                                         | Sections 4, 7.2, 7.5, 15.1; `AUTHZ-002`, `AUTHZ-009`, `AUTHZ-010`                                            | **Completed.** Verified in `tests/Feature/Authz/PermissionCatalogueTest.php`.                                                                                                                       |
| AUTHZ-T02 | Create the fixed Customer, Agent, and Admin roles; backfill exactly one role from `user_type`; enforce ongoing synchronization and immutability; and provision the 13-permission catalogue.                         | Sections 4, 7, 9, 11.1, 15.1; `AUTHZ-001`, `AUTHZ-008`–`AUTHZ-023`                                           | **Completed.** Verified in `tests/Feature/Authz/RoleSynchronizationTest.php`.                                                                                                                       |
| AUTHZ-T03 | Implement the custom Gate evaluator, direct-grant semantics, authorization restrictions, permission versions, session refresh, and reusable authorization evidence.                                                 | Sections 4, 8, 11.2–11.3, 14–15; `AUTHZ-002`, `AUTHZ-003`, `AUTHZ-027`–`AUTHZ-029`, `AUTHZ-033`, `AUTHZ-035` | **Completed.** Verified in `tests/Feature/Authz/AuthorizationKernelTest.php`.                                                                                                                       |
| AUTHZ-T04 | Implement Admin permission viewing and management with fresh authentication, no self-management, atomic history, optimistic concurrency, and final-capable-Admin safeguards.                                        | Sections 9–10, 14.4, 15.2; `AUTHZ-022`–`AUTHZ-027`, `AUTHZ-034`                                              | **Completed.** Verified in `tests/Feature/Authz/AdminPermissionManagementTest.php` and `tests/Feature/Authz/FreshAuthenticationTest.php`.                                                           |
| AUTHZ-T05 | Integrate `security.operations.manage` with existing lockout and password-reset notification behavior, including commit-time reauthorization and required verification reasons.                                     | Sections 7.2–7.4, 8, 14; `AUTHZ-003`, `AUTHZ-019`, `AUTHZ-027`, `AUTHZ-033`                                  | **Completed.** Verified in `tests/Feature/Authz/SecurityOperationsEnforcementTest.php`, `tests/Feature/Auth/AuthenticationAbuseProtectionTest.php`, and `tests/Feature/Auth/PasswordResetTest.php`. |
| AUTHZ-T06 | Document mandatory Spatie role/permission conventions in `AGENTS.md`, including synchronized roles, direct Admin grants, enum-backed codes, custom Gate checks, cache handling, and prohibited bypasses.            | Sections 4, 7, 11, 14                                                                                        | **Completed.** Applied in `AGENTS.md`.                                                                                                                                                              |
| AUTHZ-T07 | Integrate Customer ownership, Agent assignment scope, Admin business scope, related-record validation, scope-safe discovery, and separation of duties into owning modules.                                          | Sections 5–6, 12–13; `AUTHZ-004`–`AUTHZ-007`, `AUTHZ-030`–`AUTHZ-032`                                        | **In Progress.** Module 04 portion delivered via `CustomerAssignment`, `CustomerProfilePolicy`, `AgentProfilePolicy`, `ResourceScopeService`, and `CustomerActionAuthorizationGuard` (`CAM-T03`); remaining resource scopes in progress for Modules 05–10.                                         |
| AUTHZ-T08 | Integrate permission notifications and canonical authorization audit events without treating local grant history as the audit ledger.                                                                               | Sections 10.6 and 16; `AUTHZ-036`, `AUTHZ-037`                                                               | **Blocked.** Requires Modules 13 and 14.                                                                                                                                                            |
| AUTHZ-T09 | Complete end-to-end evidence for all Module 03 acceptance criteria, including active sessions, queued work, concurrent changes, direct endpoint calls, scope-safe denials, and cross-module permission enforcement. | Section 18; `AUTHZ-001`–`AUTHZ-037`                                                                          | **Blocked.** Requires AUTHZ-T01–AUTHZ-T08 and their owning cross-module dependencies.                                                                                                               |

## Package and Persistence Design

- Install the package through Composer and commit both manifest and lockfile changes. Use the package's standard `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, and `role_has_permissions` tables.
- Configure the `web` guard, disable teams and wildcard permissions, hide role/permission names from authorization exceptions, and enable package assignment events.
- Set `register_permission_check_method` to `false`. The package's automatic Gate hook cannot account for application account states and temporary authorization restrictions, so the application registers the authoritative check.
- Extend the Spatie Permission model with display name, description, active/retired status, introduction timestamp, and retirement timestamp.
- Seed the fixed roles and the following closed permission catalogue through version-controlled data migration:
    - `admins.manage`
    - `agents.manage`
    - `customers.manage`
    - `customers.reassign`
    - `withdrawals.review`
    - `reversals.review`
    - `fees.manage`
    - `deductions.manage`
    - `reconciliation.manage`
    - `business.settings.manage`
    - `security.operations.manage`
    - `audit.view`
    - `reports.export`
- Keep `role_has_permissions` empty for these capabilities. Admin capabilities are direct `model_has_permissions` grants so every Admin has an explicit permission set.
- Backfill the one matching Spatie role for existing users. Designate the earliest existing active Admin as the bootstrap Admin and grant that user the complete catalogue with source `system_seed`. If no Admin exists, the first-Admin provisioner performs the same grant when the first active Admin is created.
- Clear Spatie's `PermissionRegistrar` cache after catalogue migrations or direct database backfills. Runtime changes use package APIs so relation and cache handling follow package behavior.
- Add application-owned permission-change history alongside Spatie's current-state pivots. Store an immutable row per grant or revocation with its batch, target, permission-code snapshot, action, source, actor, required reason, resulting permission version, and timestamp.
- Add `authorization_restrictions` for expiring restrictions without deleting the underlying Spatie grant. Expired restrictions stop affecting authorization by timestamp immediately; an idempotent command closes their history and advances affected permission versions.

## Authorization and Management Behaviour

- Retain `user_type` as the immutable account classification. New users receive exactly one matching Spatie role after creation. Role middleware requires both `hasRole()` and a matching `user_type`; drift fails closed and is reported by a read-only diagnostic command.
- Add a string-backed `AdminPermission` enum matching the 13 Spatie permission names. Unknown, retired, wildcard-like, or role-inherited permissions do not authorize an Admin action.
- Register a custom Gate evaluator for enum-backed abilities. Success requires an active account, Admin classification, synchronized Admin role, active permission definition, direct Spatie grant, and no active restriction.
- Add `users.permission_version`. Permission and restriction changes increment it transactionally. Session middleware detects version changes, clears authorization-derived state, rotates the session ID, reloads current Spatie relationships, and stores the new version.
- Add an immutable authorization-evidence value object containing actor, exact permission, permission version, resource/version context, and decision time. Future approval and queued workflows persist this evidence and reauthorize before execution.
- Expose:
    - `GET /admin/access` as `admin.access.index`;
    - `GET /admin/access/{admin}` as `admin.access.show`;
    - `PUT /admin/access/{admin}/permissions` as `admin.access.permissions.update`.
- Any active Admin may view their own exact permissions and concise responsibility summaries for other Admins. Another Admin's complete history and change controls require `admins.manage`.
- Permission updates accept the complete desired permission set, a required 1–500 character reason, `expected_permission_version`, and explicit confirmation. Reject self-management, non-Admin targets, unknown/retired/duplicate codes, stale versions, no-op submissions, and restricted actors.
- Perform changes in one transaction that locks the actor, target, relevant grants and restrictions, and continuity set; invokes Spatie's `syncPermissions`; records history; and increments the target version once.
- Preserve at least one active Admin and one active, unrestricted direct holder of `admins.manage`.
- Implement the Inertia Vue interface with module-specific components/composables, Reka UI, Vuelidate, Lucide, Wayfinder, light/dark support, before/after confirmation, high-risk warnings, and bottom-center outcome toasts.
- Require `security.operations.manage` for the existing lockout list and manual unlock. Require a non-empty identity-verification reason and approved reason category, reauthorize before mutation, and retain self-unlock and final-Admin protections.
- Route privileged password-reset notifications only to active Admins with effective `security.operations.manage`, excluding the affected Admin.
- Share only effective permission codes and `permission_version` through Inertia. Frontend checks control presentation only; every protected action remains server-authoritative.

## `AGENTS.md` Guidance

Add a **Spatie Laravel Permission** section with these standing rules:

- `spatie/laravel-permission` is mandatory for role and permission persistence and assignment; confirm its installed version before using version-specific APIs.
- `user_type` is the immutable account classification and must match exactly one Spatie `web` role.
- Roles are fixed. Granular Admin permissions are direct user grants, never inherited from the Admin role.
- Permission codes come from `AdminPermission`; wildcard permissions and super-Admin bypasses are prohibited.
- All permission mutations use the application permission-management service so history, restrictions, continuity, permission versions, and cache behavior remain correct.
- Controllers and frontend-facing code authorize through Laravel Gates/policies rather than calling `hasDirectPermission()` themselves.
- Resource scope and separation-of-duty checks remain mandatory in addition to Spatie permission checks.
- Direct catalogue/backfill database operations must clear the `PermissionRegistrar` cache; normal runtime changes use Spatie APIs.
- Frontend permission checks are presentational and never replace server authorization.

## Test and Verification Strategy

- Verify package configuration, fixed roles, the 13-permission catalogue, custom Permission metadata, disabled wildcards, and disabled automatic Gate registration.
- Verify every user has exactly one matching role; role/classification drift and post-creation role changes fail authorization.
- Test data backfill and bootstrap behavior, including complete direct grants and `system_seed` history for the designated first Admin.
- Exercise all 13 abilities for explicitly granted Admins, baseline-only Admins, Customer/Agent users, inactive accounts, retired permissions, and active restrictions. A permission placed on `role_has_permissions` must not satisfy an Admin ability.
- Test grant/revocation history, Spatie pivot synchronization, cache handling, rollback atomicity, and one permission-version increment per change.
- Test fresh authentication, self-management denial, payload validation, stale and no-op submissions, final-capable-Admin protection, and concurrent changes.
- Confirm active sessions observe grants, revocations, restrictions, and expiry on the next request.
- Verify lockout authorization, required reason, denial atomicity, and exact privileged password-reset notification recipients.
- Verify Inertia props, Wayfinder usage, direct endpoint authorization, form validation, confirmation flow, light/dark rendering, and outcome toasts.
- Run focused Pest tests after each task, then the complete PHP suite, static/type checks, frontend checks/build, the role-sync diagnostic, and Pint for changed PHP files.

## Assumptions and Deferred Decisions

- The user-approved synchronized model is binding: `user_type` remains the identity classification while Spatie provides the corresponding authorization role and direct permissions.
- Spatie owns current role and permission assignments; application tables add history, restrictions, versioning, and authorization evidence.
- The application remains single-business, so Spatie teams are disabled.
- No dynamic role creation, wildcard permission, role-inherited Admin grant, or super-Admin override is introduced.
- Permission history is domain history, not a substitute for Module 14's canonical audit ledger.
- Generic approval-snapshot persistence is deferred. Each owning module will store the shared authorization-evidence shape with its approval record.
- Resource policies, financial workflows, canonical audit events, notification delivery contracts, and exports remain with their owning Modules 04–15.
