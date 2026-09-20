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
| Implement the password policy and role-specific password-reset lifecycle, including token invalidation and post-reset revocation. | Sections 4.3, 7.1–7.7; `AUTH-009`, `AUTH-013`–`AUTH-016` | To Do | — |
| Implement mandatory Admin/Agent TOTP, authenticator replacement, lost-authenticator handling, hashed recovery codes, replay prevention, and factor throttling. | Sections 5.1–5.13; `AUTH-008`, `AUTH-023`–`AUTH-030` | To Do | — |
| Implement first-Admin provisioning and Admin, Agent, and Customer invitation and activation lifecycles. | Sections 3.1–3.14; `AUTH-003`–`AUTH-006`, `AUTH-031`–`AUTH-039` | Blocked | Requires Module 03 permissions, Module 04 profile/assignment and registration decisions, Module 05 fee contracts, Module 13 delivery contracts, Module 14 audit contracts, and Module 15 trusted bootstrap data. |
| Implement dual-confirmation active-account email changes with fresh authentication, reservations, revocation, notification, and audit. | Section 4.4; `AUTH-040`–`AUTH-045` | Blocked | Requires finalized Module 13 security-notification delivery and Module 14 audit/event contracts. |
| Implement Customer, Agent, Admin, and final-Admin assisted recovery with separation of duties and emergency-key controls. | Section 7.8; `AUTH-017`–`AUTH-022` | Blocked | Requires Module 03 permissions, Module 04 assignment and identity-verification evidence, Module 13 notifications, Module 14 security/audit evidence, and Module 15 bootstrap ownership. |
| Implement server-authoritative session/device limits, Agent trusted devices, fresh authentication, safe resume destinations, token rotation, and revocation. | Section 8; `AUTH-010`, `AUTH-046`–`AUTH-051`, `AUTH-053`, `AUTH-055` | To Do | — |
| Integrate account state, permission versions, assignment changes, and session expiry with server authorization and idempotent financial resumption. | Sections 1, 8.5, 8.7, 8.9; `AUTH-007`, `AUTH-049`, `AUTH-052`, `AUTH-054` | Blocked | Requires Module 03 authorization, Module 04 assignment behavior, and owning financial contracts in Modules 05–10. |
| Implement independent abuse counters, progressive password restrictions, MFA and recovery cooldowns, controlled unlock, and safe security visibility. | Section 9; `AUTH-056`–`AUTH-063` | To Do | — |
| Integrate mandatory authentication notifications and canonical audit events with secret-safe payloads and permission-scoped visibility. | Sections 3.14, 4.4.6, 5.13, 8.11, 9.9–10; `AUTH-011` and cross-cutting audit clauses | Blocked | Requires Module 03 permission audiences, Module 13 event/delivery/template contracts, and Module 14 canonical event, retention, and security-operation contracts. |
| Complete end-to-end evidence for every Module 02 acceptance criterion, including concurrency, direct endpoint calls, revocation, redaction, accessibility, and recovery from failure. | Section 12; `AUTH-001`–`AUTH-063` | Blocked | Requires all preceding Module 02 tasks and their cross-module dependencies to be completed. |

## Module 03 — Roles, Permissions, and Authorization

Source: [`03-roles-and-permissions.md`](./modules/03-roles-and-permissions.md)
Dependencies: Modules 01 and 02

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate role, permission, resource-scope, separation-of-duty, and audit requirements into implementation tasks. | Sections 4–16; `AUTHZ-001`–`AUTHZ-037` | To Do | — |

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
