# Financial Notes

**Product version:** 2.0  
**Module:** 17  
**Module status:** Implemented locally; acceptance in progress  
**Sources:** [User Types](./01-user-types.md), [Roles and Permissions](./03-roles-and-permissions.md), [Audit, Security Operations, and Retention](./14-audit-security-operations-and-retention.md), [Platform Reliability and Data Operations](./16-platform-reliability-and-data-operations.md)  
**Related modules:** [Transaction Ledger, Balances, and Statements](./10-transaction-ledger-balances-and-statements.md), [Reports and Exports](./12-reports-and-exports.md) (explicitly not integrated)

## 1. Purpose and specification status

Give authorized Admins a private place to record notes about the business's own revenue and expenses, such as rent paid, generator fuel, staff transport, or a sponsorship received. A financial note is a memo. It is **not** a transaction, ledger entry, fee, deduction, withdrawal, or reconciliation record, and it never changes a Customer balance, Agent cash responsibility, business fee earnings, report, or statement.

Product decisions confirmed by the product owner on 2 November 2026:

- Each note has a type (**Credit** or **Debit**), a title, a required naira amount, a note date, an optional category, and a free-text body.
- Access is limited to Admins holding the new grantable permission `financial.notes.manage`.
- Notes can be edited. Deleting a note archives it (a soft delete) and it can be restored. Every create, edit, archive, and restore is audited.

## 2. Initial scope and boundaries

### 2.1 Included

- Create, list, filter, edit, archive, and restore financial notes for the single configured business.
- Credit, debit, and net totals for the notes that match the current filters.
- Filters: text search (title or category), type, category, date range, and Active or Archived view.
- Audit events for every change.
- Sidebar entry and help article for Admins holding the permission.

### 2.2 Excluded or deferred

- Any link to ledger postings, collections, fees, withdrawals, reversals, cash, or reconciliation. Notes cannot reference or create those records.
- Inclusion in dashboards, reports, statements, exports, or Customer and Agent views.
- Attachments (receipts or documents), approval workflows, recurring notes, budgets, multiple currencies, and tax treatment.
- Hard deletion. Archived notes remain in storage.
- Notifications. Note changes notify no one.

## 3. Terminology and invariants

| Term            | Meaning                                                                                                 |
| --------------- | ------------------------------------------------------------------------------------------------------- |
| Financial note  | An Admin memo about business money that came in or went out. It has no financial effect.                 |
| Credit          | A note about money received by the business (revenue or other income).                                   |
| Debit           | A note about money spent by the business (an expense).                                                    |
| Active note     | A note shown in the default list and included in totals.                                                  |
| Archived note   | A soft-deleted note, hidden from the default list and totals, retained and restorable.                   |
| Net             | Sum of credit amounts minus sum of debit amounts over the filtered notes; may be negative.               |

- Amounts are stored as positive integer kobo (`amount_kobo`). The type, not the sign, says whether money came in or went out. Display ₦12,500.50 for 1,250,050 kobo.
- Entry accepts naira with at most two decimal places; thousands separators are ignored. Zero, negative, and over-precision values are rejected rather than rounded.
- Credit and Debit are labels on a memo, not double-entry postings. No ledger account, posting group, or balance projection reads or writes notes.
- Each note has a public UUID `reference` used in URLs and audit, and an integer `version` for optimistic concurrency.

## 4. Authority and eligibility

### 4.1 Action matrix

| Action                              | Customer | Agent | Admin                    |
| ----------------------------------- | -------- | ----- | ------------------------ |
| View financial notes and totals     | No       | No    | `financial.notes.manage` |
| Create a note                       | No       | No    | `financial.notes.manage` |
| Edit an active note                 | No       | No    | `financial.notes.manage` |
| Archive or restore a note           | No       | No    | `financial.notes.manage` |

### 4.2 Rules

- The permission is a direct grant under Module 03: an active Admin account, the single `admin` role, an active catalogue entry, a direct grant, and no active restriction. Admin baseline read does **not** include notes.
- The permission is checked on every request and again inside the write transaction after locking the actor, so a revocation or suspension between page load and save is enforced.
- `financial.notes.manage` grants no ledger, fee, report, export, audit-log, or reconciliation authority, and no other permission implies it.
- Fresh authentication is not required; notes carry no financial effect.

## 5. Fields and validation

| Field       | Required | Rules                                                                 |
| ----------- | -------- | --------------------------------------------------------------------- |
| Type        | Yes      | `credit` or `debit`.                                                  |
| Title       | Yes      | Trimmed, 1–150 characters.                                            |
| Amount (₦)  | Yes      | Greater than zero, at most two decimals, within the shared maximum (`MoneyAmount`). Stored as kobo. |
| Note date   | Yes      | `YYYY-MM-DD`. Past and future dates are allowed.                      |
| Category    | No       | Trimmed, up to 80 characters; blank becomes empty. Previously used categories are suggested. |
| Body        | Yes      | Up to 5,000 characters.                                               |
| Version     | On edit  | The version the Admin last saw.                                       |

System fields: business, reference, status (`active` or `archived`), archived at/by, created by, last changed by, version, timestamps.

## 6. Lifecycle

| From     | Action  | To       | Conditions                                                   |
| -------- | ------- | -------- | ------------------------------------------------------------ |
| —        | Create  | Active   | Valid fields; version 1.                                     |
| Active   | Edit    | Active   | Matching version; version increases only when a field changes. |
| Active   | Archive | Archived | Matching version; records archived at/by.                    |
| Archived | Restore | Active   | Matching version; clears archived at/by.                     |
| Archived | Edit    | —        | Rejected; restore first.                                     |

A stale version, or archiving an already archived note, is rejected without change and the Admin is told to refresh.

## 7. Screens and accessibility

- **Financial notes page** (`/admin/financial-notes`): header and description, an "Add note" action, Credit / Debit / Net summary tiles, a filter row, and a paginated list (25 per page, newest note date first). Each row shows title, type badge, category, a two-line body preview, date, last editor, signed amount, and Edit / Archive or Restore actions.
- **Add/Edit sheet:** the fields in Section 5 with inline errors. It states that notes do not post money or change balances.
- **Archive/Restore dialog:** confirms the action and explains the effect on the list and totals.
- Empty states distinguish "no notes yet", "no archived notes", and "no matches".
- Follows the dashboard page standards: breadcrumbs from Dashboard, shared date picker, 44px filter controls, labelled controls, focus-visible styles, and usable layout at 390px width in light and dark themes.

## 8. Failure, concurrency, and integrity

- Writes run in the platform mutation transaction (`PlatformGuard`) with deadlock retry, and are blocked when the platform mode blocks mutations.
- Concurrent edits use the note `version`; the loser gets a "Note not changed" message and no change is written.
- Notes are scoped to the current business profile; foreign keys restrict deletion of referenced users and business.
- Nothing in this module locks or reads ledger, collection, or cash tables.

## 9. Audit

| Event                     | When                     | Recorded fields                                                     |
| ------------------------- | ------------------------ | ------------------------------------------------------------------- |
| `financial_note.created`  | A note is created        | type, amount_kobo, note_date, status, version, changed_fields       |
| `financial_note.updated`  | An edit changes a field  | same, with the changed field names                                  |
| `financial_note.archived` | A note is archived       | same, `changed_fields = [status]`                                   |
| `financial_note.restored` | A note is restored       | same, `changed_fields = [status]`                                   |

- Target is the note's `reference`; the actor and `required_permission = financial.notes.manage` are recorded in context.
- Free text (title, category, body) is kept on the note row and not copied into audit payloads, so personal or sensitive text in a note does not enter the audit stream.
- Events follow Module 14 retention as business evidence.

## 10. Owning-module contracts

| Owner     | Contract                                                                                         |
| --------- | ------------------------------------------------------------------------------------------------ |
| Module 03 | Adds `financial.notes.manage` to the closed permission catalogue and capability matrix.          |
| Module 14 | Registers the `financial_note` audit family and allowed fields in `AuditCatalogue`.              |
| Module 16 | Classifies the four write routes as `mutation` in `PlatformCatalogue`.                           |
| Module 10, 11, 12 | No contract. Notes must not appear in ledger, dashboard, report, or export data.         |

## 11. Indexed functional requirements

| ID         | Requirement                                                                                                  | Detail      |
| ---------- | ------------------------------------------------------------------------------------------------------------ | ----------- |
| FNT-FR-001 | Restrict every note view and action to Admins holding a valid direct `financial.notes.manage` grant.         | Section 4   |
| FNT-FR-002 | Re-check authorization inside the write transaction after locking the actor.                                | Section 4.2 |
| FNT-FR-003 | Capture type, title, amount, note date, optional category, and body with the Section 5 validation.          | Section 5   |
| FNT-FR-004 | Store amounts as positive integer kobo; reject zero, negative, and over-precision input.                     | Section 3   |
| FNT-FR-005 | Allow editing of active notes with optimistic version checks.                                                | Section 6   |
| FNT-FR-006 | Archive (soft delete) and restore notes; never hard-delete.                                                  | Section 6   |
| FNT-FR-007 | List, search, and filter notes by type, category, date range, and status, paginated.                        | Section 7   |
| FNT-FR-008 | Show credit, debit, and net totals for the filtered notes; archived notes are excluded unless viewing them. | Section 7   |
| FNT-FR-009 | Record an audit event for each create, edit, archive, and restore, without free text.                       | Section 9   |
| FNT-FR-010 | Never read, write, or influence ledger, balance, fee, collection, cash, report, or export records.          | Section 2.2 |
| FNT-FR-011 | Show the sidebar entry and help article only to Admins holding the permission.                              | Section 7   |

## 12. Acceptance criteria and evidence

| ID         | Requirements           | Scenario and expected result                                                                                                  | Status / evidence |
| ---------- | ---------------------- | ----------------------------------------------------------------------------------------------------------------------------- | ----------------- |
| FNT-AC-001 | FNT-FR-001             | An Admin without the grant, an Agent, and a Customer call every note route; all are denied (403) and nothing changes. Granting the permission opens the page. | Passed — `FinancialNoteTest` |
| FNT-AC-002 | FNT-FR-003, FNT-FR-004 | Create a debit of "12,500.50"; it is stored as 1,250,050 kobo with the given date and category.                                 | Passed — `FinancialNoteTest` |
| FNT-AC-003 | FNT-FR-003, FNT-FR-004 | Zero, negative, three-decimal amounts, unknown type, missing title or body, and a malformed date are rejected with no note saved. | Passed — `FinancialNoteTest` |
| FNT-AC-004 | FNT-FR-005, FNT-FR-009 | Edit a note; version rises to 2 and `financial_note.updated` records changed fields without the note text. A second edit with version 1 is refused unchanged. | Passed — `FinancialNoteTest` |
| FNT-AC-005 | FNT-FR-006, FNT-FR-008 | Archive a note; it leaves the default list and totals, appears in the Archived view, cannot be edited, and restore brings it back. Both actions are audited. | Passed — `FinancialNoteTest` |
| FNT-AC-006 | FNT-FR-007, FNT-FR-008 | With one credit and two debits, totals and net (including a negative net) are correct; date range, type, and search narrow the list and totals. | Passed — `FinancialNoteTest` |
| FNT-AC-007 | FNT-FR-010             | Creating a note leaves ledger entry and posting group counts unchanged.                                                        | Passed — `FinancialNoteTest` |
| FNT-AC-008 | FNT-FR-002             | Revoke the grant or suspend the Admin between page load and save; the write is refused.                                        | Covered by the shared `AuthorizationService` re-check; no dedicated race test |
| FNT-AC-009 | FNT-FR-011             | The sidebar item and help article appear only with the permission; the page works at 390px in light and dark themes.          | Browser check pending |

## 13. Proposed decisions requiring confirmation

1. Future note dates are allowed (for planned expenses). Confirm or restrict to today and earlier.
2. Categories are free text with suggestions rather than a managed list.
3. Totals exclude archived notes by default and are not shown anywhere outside this page.
4. Note text is excluded from audit payloads; only field names and non-text values are audited.
5. Attachments and export are deferred; adding either needs a revision of this module and Module 12 or the protected-file owner.
