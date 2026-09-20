# Version 2 Module Implementation Register

This register is the canonical task-management record for the Version 2 modules. Maintain it throughout planning, implementation, testing, review, and release work. The module specifications in [`modules`](./modules) remain the source of truth.

## Status definitions

| Status | Meaning |
| --- | --- |
| To Do | Scoped from the module specification but not yet started. |
| In Progress | Actively being designed, implemented, or tested. |
| Done | Implementation is written and locally verified; acceptance is still outstanding. |
| Completed | Applicable acceptance criteria and required tests have passed. |
| Blocked | Cannot advance until the recorded decision, dependency, or external input is resolved. |

## How to maintain this register

- Read the module and the dependencies named in its header before creating or changing a task.
- Keep every task linked to a specification section, functional requirement, or acceptance scenario.
- Update status and evidence when work begins, advances, completes, or becomes blocked.
- Do not mark a task `Completed` while its acceptance criteria, required tests, or a required dependency remain unresolved.

## Module 01 — User Types and Access Model

Source: [`01-user-types.md`](./modules/01-user-types.md)

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate the user-type, access-boundary, and cross-cutting requirements into implementation tasks. | Sections 4–9 | Completed | Implementation plan established in `docs/v2/implementation_plan/01-user-types.md` and decomposed into verified tasks. |
| Define string-backed UserType enum and add required users.user_type database column with fail-fast legacy check. | Sections 3, 7, 9.2, 9.5 | Completed | `App\Enums\UserType` defined and `2026_09_20_100328_add_user_type_to_users_table.php` migration with fail-fast check created; verified in `tests/Feature/UserTypeTest.php`. |
| Configure User model casting and UserFactory states with least-privileged Customer default, and update DatabaseSeeder. | Sections 3, 4, 5, 6, 8 | Completed | `User` casts `user_type` to `UserType`; `UserFactory` defaults to Customer and provides `customer()`, `agent()`, `admin()` states; sample user removed from `DatabaseSeeder`; verified in `tests/Feature/UserTypeTest.php`. |
| Disable Fortify registration, remove registration actions/views, and eliminate public registration links. | Sections 4.2, 6.5, 8 | Completed | Fortify registration disabled in `config/fortify.php` and `FortifyServiceProvider`; `CreateNewUser` and `Register.vue` deleted; sign-up links removed from `Welcome.vue` and `Login.vue`; verified by route inspection and `tests/Feature/Auth/RegistrationTest.php`. |
| Update Inertia TypeScript contracts to expose required UserType on authenticated user. | Sections 3, 8 | Completed | `UserType` and required `user_type` added to `resources/js/types/auth.ts`; verified with `npm run types:check` and `npm run build`. |
| Bootstrap singleton Business profile and initial first-Admin provisioning. | Sections 6.5, 9.1 | Blocked | Awaiting confirmed decisions from Modules 02, 03, 04, 14, and 15. |
| Enforce resource-level authorization boundaries and separation of duties for Customer, Agent, and Admin actions. | Sections 4.4, 5.4, 6.4, 8 | Blocked | Awaiting confirmed decisions from Modules 02 and 03. |

## Module 02 — Authentication and Account Access

Source: [`02-authentication.md`](./modules/02-authentication.md)
Dependencies: Modules 01 and 03

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate account provisioning, authentication, MFA, recovery, session, and abuse-protection requirements into implementation tasks. | Sections 3–11; `AUTH-001`–`AUTH-063` | Completed | Implementation plan established in `docs/v2/implementation_plan/02-authentication.md`; all 63 functional requirements and 80 acceptance criteria are mapped to implementation tasks. |
| Add normalized email identity and explicit account-access state foundations while preserving historical attribution and isolating temporary authentication restrictions. | Sections 4.2, 6, 9.6; `AUTH-007`, `AUTH-012`, `AUTH-036`, `AUTH-060` | Completed | `App\Enums\AccountState` defined; migration `2026_09_20_143000_add_account_state_and_normalized_email_to_users_table.php` added with fail-fast check; `IdentityNormalizer` and `UniqueNormalizedEmail` implemented; `User` casts `account_state` and enforces normalized email sync, temporary-lock isolation, final active Admin protection, and attribution checks; starter-kit passkeys removed from Fortify config, `User`, and UI; verified in `tests/Feature/Auth/AccountStateTest.php`. |
| Align shared sign-in with generic failures, account-state gates, role routing, closed registration, password-first authentication, and no passkey bypass. | Sections 2, 4.1, 6; `AUTH-001`, `AUTH-002`, `AUTH-007` | Completed | `App\Actions\Fortify\AuthenticateUser` implemented with normalized identity lookup, password verification/rehashing, account-state gates, timeboxing, and fail-closed MFA checks; `RoleDestinationResolver` implemented and exposed via `UserType::dashboardRouteName()`; custom `LoginResponse` and `TwoFactorLoginResponse` bound in `FortifyServiceProvider`; `EnsureActiveAccount` and `EnsureUserType` middleware wired; guarded `/customer/dashboard`, `/agent/dashboard`, and `/admin/dashboard` routes and `/dashboard` dispatcher added in `routes/web.php`; generic error message configured in `lang/en/auth.php`; remaining passkey route, limiter, and config removed; verified in `tests/Feature/Auth/AuthenticationTest.php`, `tests/Feature/Auth/AccountStateTest.php`, `tests/Feature/DashboardTest.php`, and `tests/Feature/UserTypeTest.php`. |
| Implement the password policy and role-specific password-reset lifecycle, including token invalidation and post-reset revocation. | Sections 4.3, 7.1–7.7; `AUTH-009`, `AUTH-013`–`AUTH-016` | Completed | `App\Support\PasswordPolicy` defined (Customer: min 15 chars; Agent/Admin: min 8 chars; uncompromised); token expiration set to 15m in `config/auth.php`; generic localized messages in `lang/en/passwords.php`; `PasswordResetBroker` implemented with normalized lookup, rate-limiting abuse protection, and invited-account gates; `ResetUserPassword` enforces role-specific TOTP/recovery code verification for Agent/Admin, consumes recovery codes, validates password policies, revokes database sessions, rotates remember tokens, clears password locks, and sends queued `PasswordResetSuccessNotification` and `AdminPasswordResetNotification`; frontend `ResetPassword.vue` supports invalid link states, TOTP/recovery code inputs, Vuelidate, and bottom-center toasts; verified in `tests/Feature/Auth/PasswordResetTest.php` (18 tests). |
| Implement mandatory Admin/Agent TOTP, authenticator replacement, lost-authenticator handling, hashed recovery codes, replay prevention, and factor throttling. | Sections 5.1–5.13; `AUTH-008`, `AUTH-023`–`AUTH-030` | Completed | `AuthenticatorState` defined; migration `2026_09_20_180000_update_two_factor_authentication_storage.php` dropped plaintext-recoverable recovery codes column, added `two_factor_recovery_codes` table with SHA-256 hashes, and migrated state; `TwoFactorService` implemented with BaconQrCode SVG generator, Google2FA ±1 window and atomic `two_factor_last_used_timestep` replay protection, SHA-256 single-use recovery code consumption, enrolment, replacement swap, and recovery code regeneration; `ExpirePendingTwoFactorSetup` idempotent cleanup job; 7 queueable notifications implemented; Fortify actions, custom `TwoFactorAuthenticatedSessionController` (5-attempt termination, replay lockout), `TwoFactorEnrolmentController`, and `TwoFactorManagementController` implemented; self-service MFA disabling blocked for Admins/Agents; `TwoFactorEnrolment.vue` with Vuelidate, InputOTP, and single display of 10 codes, `AssistedRecoveryHandoff.vue`, and updated `ManageTwoFactor.vue` and `TwoFactorChallenge.vue`; verified by `tests/Feature/Auth/TwoFactorEnrolmentTest.php` (8 tests), `tests/Feature/Auth/TwoFactorChallengeTest.php` (7 tests), `tests/Feature/Auth/TwoFactorReplacementTest.php` (4 tests), `tests/Feature/Auth/TwoFactorRecoveryCodesTest.php` (4 tests), `tests/Feature/Auth/AuthenticationTest.php` (27 tests), `tests/Feature/Auth/PasswordResetTest.php` (18 tests), full suite (113 tests passing), Pint, `npm run types:check`, and `npm run build`. |
| Implement first-Admin provisioning and Admin, Agent, and Customer invitation and activation lifecycles. | Sections 3.1–3.14; `AUTH-003`–`AUTH-006`, `AUTH-031`–`AUTH-039` | Blocked | Requires Module 03 permissions, Module 04 profile/assignment and registration decisions, Module 05 fee contracts, Module 13 delivery contracts, Module 14 audit contracts, and Module 15 trusted bootstrap data. |
| Implement dual-confirmation active-account email changes with fresh authentication, reservations, revocation, notification, and audit. | Section 4.4; `AUTH-040`–`AUTH-045` | Blocked | Requires finalized Module 13 security-notification delivery and Module 14 audit/event contracts. |
| Implement Customer, Agent, Admin, and final-Admin assisted recovery with separation of duties and emergency-key controls. | Section 7.8; `AUTH-017`–`AUTH-022` | Blocked | Requires Module 03 permissions, Module 04 assignment and identity-verification evidence, Module 13 notifications, Module 14 security/audit evidence, and Module 15 bootstrap ownership. |
| Implement server-authoritative session/device limits, Agent trusted devices, fresh authentication, safe resume destinations, token rotation, and revocation. | Section 8; `AUTH-010`, `AUTH-046`–`AUTH-051`, `AUTH-053`, `AUTH-055` | Completed | Migration `2026_09_20_200000_create_agent_trusted_devices_and_update_sessions_table.php` added; `EnforceSessionLimits` middleware enforces Customer (7d/30d/5 devices), Agent (1h/24h/2 devices), Admin (30m/24h/1 device) lifetimes; explicit device eviction flow (`DeviceEvictionController` & `DeviceEviction.vue`) with masked IPs and queueable notifications; Agent 30-day trusted devices (`AgentTrustedDeviceService`) with bypass on TOTP; `EnsureFreshAuthentication` (10m window) middleware; signed/encrypted resume destination cookie (`ResumeCookieService`); session management endpoints (`SessionController` & `ActiveSessions.vue`); verified in `tests/Feature/Auth/SessionManagementTest.php` (14 tests), full suite (127 tests passing), Pint, `npm run types:check`, and `npm run build`. |
| Integrate account state, permission versions, assignment changes, and session expiry with server authorization and idempotent financial resumption. | Sections 1, 8.5, 8.7, 8.9; `AUTH-007`, `AUTH-049`, `AUTH-052`, `AUTH-054` | Blocked | Requires Module 03 authorization, Module 04 assignment behavior, and owning financial contracts in Modules 05–10. |
| Implement independent abuse counters, progressive password restrictions, MFA and recovery cooldowns, controlled unlock, and safe security visibility. | Section 9; `AUTH-056`–`AUTH-063` | Completed | Migration `2026_09_20_210000_create_authentication_locks_table.php` added; `AuthenticationLock` model and `AuthenticationAbuseService` implemented with independent sliding windows, progressive delays, 10-failure/15m and 20-failure/1h locks, 10-attempt MFA (15m) and recovery (1h) cooldowns, stuffing/spraying detection, controlled manual unlock, and compromise session revocation; `LockoutController` and `Lockouts.vue` implemented with secret-safe redaction, Vuelidate dialog, and bottom-center toasts; verified by `tests/Feature/Auth/AuthenticationAbuseProtectionTest.php` (23 tests), full suite (150 tests passing), Pint, `npm run types:check`, and `npm run build`. |
| Integrate mandatory authentication notifications and canonical audit events with secret-safe payloads and permission-scoped visibility. | Sections 3.14, 4.4.6, 5.13, 8.11, 9.9–10; `AUTH-011` and cross-cutting audit clauses | Blocked | Requires Module 03 permission audiences, Module 13 event/delivery/template contracts, and Module 14 canonical event, retention, and security-operation contracts. |
| Complete end-to-end evidence for every Module 02 acceptance criterion, including concurrency, direct endpoint calls, revocation, redaction, accessibility, and recovery from failure. | Section 12; `AUTH-001`–`AUTH-063` | Blocked | Requires all preceding Module 02 tasks and their cross-module dependencies to be completed. |

## Module 03 — Roles, Permissions, and Authorization

Source: [`03-roles-and-permissions.md`](./modules/03-roles-and-permissions.md)
Dependencies: Modules 01 and 02

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate role, permission, resource-scope, separation-of-duty, and audit requirements into implementation tasks. | Sections 4–16; `AUTHZ-001`–`AUTHZ-037` | Completed | Implementation plan established in `docs/v2/implementation_plan/03-roles-and-permissions.md`; Spatie package adoption, synchronized roles, all 13 permissions, authorization enforcement, and deferred cross-module integrations are mapped to implementation tasks. |
| Install and configure Spatie Laravel Permission, add the custom permission metadata model, and establish the closed permission catalogue. | Sections 4, 7.2, 7.5, 15.1; `AUTHZ-002`, `AUTHZ-009`, `AUTHZ-010` | Completed | `spatie/laravel-permission:^8.0` installed; `config/permission.php` configured (`web` guard, `register_permission_check_method => false`, no teams, no wildcards, hidden exceptions, enabled events); migrations `2026_09_20_210130_create_permission_tables.php` and `2026_09_20_221000_seed_permission_catalogue.php` added; `App\Enums\AdminPermission` defined with 13 cases, display names, and descriptions; `App\Models\Permission` implemented with metadata fields and scopes; `HasRoles` added to `User`; verified by `tests/Feature/Authz/PermissionCatalogueTest.php` (7 tests, 166 assertions). |
| Create fixed Spatie roles, synchronize them with immutable user types, backfill existing users, and grant the seeded first Admin the complete direct permission catalogue. | Sections 4, 7, 9, 11.1, 15.1; `AUTHZ-001`, `AUTHZ-008`–`AUTHZ-023` | Completed | Fixed `customer`, `agent`, and `admin` Spatie roles created on `web` guard; migrations `2026_09_20_223000_create_permission_grant_histories_table.php` and `2026_09_20_224000_create_fixed_roles_and_bootstrap_admin_grants.php` added with fail-fast validation and transaction rollback; `PermissionGrantHistory` model implemented with append-only foundation; `RoleSynchronizationService` implemented with synchronization, role immutability enforcement, and idempotent `bootstrapAdmin` provisioning; `EnsureUserType` middleware strengthened to fail closed on missing/mismatched/additional roles; read-only `authz:check-role-sync` diagnostic command added; verified by `tests/Feature/Authz/RoleSynchronizationTest.php` (14 tests, 120 assertions), `tests/Feature/Authz` (21 tests, 287 assertions), and full Pest suite (171 tests, 1183 assertions). |
| Implement the custom Gate evaluator, permission versions, temporary authorization restrictions, active-session refresh, and authorization evidence. | Sections 4, 8, 11.2–11.3, 14–15; `AUTHZ-002`, `AUTHZ-003`, `AUTHZ-027`–`AUTHZ-029`, `AUTHZ-033`, `AUTHZ-035` | To Do | Planned as `AUTHZ-T03`; depends on the Spatie roles and permission catalogue. |
| Implement Admin permission viewing and management with fresh authentication, immutable history, concurrency protection, and final-capable-Admin safeguards. | Sections 9–10, 14.4, 15.2; `AUTHZ-022`–`AUTHZ-027`, `AUTHZ-034` | To Do | Planned as `AUTHZ-T04`; depends on the central authorization kernel. |
| Apply `security.operations.manage` to lockout operations and privileged password-reset notification audiences. | Sections 7.2–7.4, 8, 14; `AUTHZ-003`, `AUTHZ-019`, `AUTHZ-027`, `AUTHZ-033` | To Do | Planned as `AUTHZ-T05`; depends on the custom Gate evaluator. |
| Add binding Spatie role and permission usage rules to `AGENTS.md`. | Sections 4, 7, 11, 14 | Completed | Binding `=== spatie/core rules ===` section added to `AGENTS.md` covering role immutability, direct user grants, enum codes, custom gates, cache handling, and prohibited bypasses. |
| Integrate Customer ownership, Agent assignment scope, Admin business scope, related-record validation, scope-safe discovery, and separation of duties. | Sections 5–6, 12–13; `AUTHZ-004`–`AUTHZ-007`, `AUTHZ-030`–`AUTHZ-032` | Blocked | Planned as `AUTHZ-T07`; requires resource and workflow contracts from Modules 04–10. |
| Integrate permission notifications and canonical authorization audit events. | Sections 10.6 and 16; `AUTHZ-036`, `AUTHZ-037` | Blocked | Planned as `AUTHZ-T08`; requires Modules 13 and 14, and local permission history is not the canonical audit ledger. |
| Complete end-to-end evidence for every Module 03 acceptance criterion, including concurrent changes, active-session invalidation, queued work, and direct endpoint denial paths. | Section 18; `AUTHZ-001`–`AUTHZ-037` | Blocked | Planned as `AUTHZ-T09`; requires all preceding Module 03 tasks and their cross-module dependencies. |

## Module 04 — Customer and Agent Management

Source: [`04-customer-and-agent-management.md`](./modules/04-customer-and-agent-management.md)
Dependencies: Modules 01–03

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate customer and agent lifecycle, assignment, eligibility, and audit requirements into implementation tasks. | Section 17 | To Do | — |

## Module 05 — Fees and Deductions

Source: [`05-fees-and-deductions.md`](./modules/05-fees-and-deductions.md)
Dependencies: Modules 01–04; related Modules 06–07

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate fee configuration, assessment, settlement, correction, and reporting requirements into implementation tasks. | Sections 15–16 | To Do | — |

## Module 06 — Thrift Plans and Savings Cycles

Source: [`06-thrift-plans-and-savings-cycles.md`](./modules/06-thrift-plans-and-savings-cycles.md)
Dependencies: Modules 01–05 and 07

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate plan lifecycle, schedule, progress, settlement, closure, and renewal requirements into implementation tasks. | Sections 4–18 | To Do | — |

## Module 07 — Collections, Digital Thrift Card, and Reconciliation

Source: [`07-collections-thrift-card-and-reconciliation.md`](./modules/07-collections-thrift-card-and-reconciliation.md)
Dependencies: Modules 01–06

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate collection, allocation, thrift-card, custody, reconciliation, and correction requirements into implementation tasks. | Sections 19–20 | To Do | — |

## Module 08 — Withdrawals and Payout Approvals

Source: [`08-withdrawals-and-payout-approvals.md`](./modules/08-withdrawals-and-payout-approvals.md)
Dependencies: Modules 01–07

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate withdrawal request, approval, reservation, payout, and audit requirements into implementation tasks. | Sections 19–20 | To Do | — |

## Module 09 — Reversals and Financial Corrections

Source: [`09-reversals-and-financial-corrections.md`](./modules/09-reversals-and-financial-corrections.md)
Dependencies: Modules 01–08

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate reversal eligibility, review, compensating postings, dependency handling, and evidence requirements into implementation tasks. | Sections 15–16 | To Do | — |

## Module 10 — Transaction Ledger, Balances, and Statements

Source: [`10-transaction-ledger-balances-and-statements.md`](./modules/10-transaction-ledger-balances-and-statements.md)
Dependencies: Modules 01–09

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate subledger, balance, transaction-history, statement, integrity, and projection requirements into implementation tasks. | Sections 18–19 | To Do | — |

## Module 11 — Dashboard and Operational Analytics

Source: [`11-dashboard-and-operational-analytics.md`](./modules/11-dashboard-and-operational-analytics.md)
Dependencies: Modules 01–10

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate role-specific dashboard, metric, freshness, drill-down, and accessibility requirements into implementation tasks. | Sections 15–16 | To Do | — |

## Module 12 — Reports and Exports

Source: [`12-reports-and-exports.md`](./modules/12-reports-and-exports.md)
Dependencies: Modules 01–11

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate report catalogue, metric, scope, export, privacy, and job-lifecycle requirements into implementation tasks. | Sections 16–17 | To Do | — |

## Module 13 — Notifications and Communication

Source: [`13-notifications-and-communication.md`](./modules/13-notifications-and-communication.md)
Dependencies: Modules 01–12

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate event routing, channel, template, preference, delivery, inbox, and retention requirements into implementation tasks. | Sections 17–18 | To Do | — |

## Module 14 — Audit, Security Operations, and Retention

Source: [`14-audit-security-operations-and-retention.md`](./modules/14-audit-security-operations-and-retention.md)
Dependencies: Modules 01–13

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate audit trail, security operations, retention, archival, integrity, and restore requirements into implementation tasks. | Sections 16–17 | To Do | — |

## Module 15 — Business Settings and Configuration

Source: [`15-business-settings-and-configuration.md`](./modules/15-business-settings-and-configuration.md)
Dependencies: Modules 01–14

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate business configuration, publication, safety gates, versioning, rollback, and readiness requirements into implementation tasks. | Sections 20–21 | To Do | — |

## Module 16 — Platform Reliability and Data Operations

Source: [`16-platform-reliability-and-data-operations.md`](./modules/16-platform-reliability-and-data-operations.md)
Dependencies: Modules 01–15

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate reliability, background processing, backup, recovery, deployment, observability, and incident-response requirements into implementation tasks. | Sections 17–18 | To Do | — |
