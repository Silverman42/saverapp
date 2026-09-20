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
| Translate the user-type, access-boundary, and cross-cutting requirements into implementation tasks. | Sections 4–9 | To Do | — |

## Module 02 — Authentication and Account Access

Source: [`02-authentication.md`](./modules/02-authentication.md)
Dependencies: Modules 01 and 03

| Task | Specification reference | Status | Evidence or blocker |
| --- | --- | --- | --- |
| Translate account provisioning, authentication, MFA, recovery, session, and abuse-protection requirements into implementation tasks. | Sections 3–11; `AUTH-001`–`AUTH-063` | To Do | — |

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
