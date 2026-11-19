# Module updates — November 2026

## Summary

Agents need to create a plan (page) whose start date is earlier than the day they create it. This conflicts with Module 06, which allowed only today or a future start date and listed backdated creation as deferred. This document records the change, the decisions behind it, and where it lives. The owning module specifications carry the matching functional requirements and acceptance criteria.

**Why.** Two field situations:

- **Late entry.** The Customer started saving days or weeks ago, but the Agent only now enters the plan.
- **Onboarding existing savers.** A Customer moving from a paper card or an old system is already part-way through a cycle.

In both cases the page must start on the real Day 1. The money already received is recorded against those past days with its real received dates.

Decisions confirmed by the product owner on 19 November 2026:

- A plan's start date may be in the past. There is **no fixed day limit**. The only condition is that every financial month from the start date's month through the current month is still **open**.
- Unpaid days before the creation date show as **Catch-up**: pending and payable, not Missed.
- The **current assigned eligible Agent** creates backdated plans, as for any plan. A **reason for backdating** is required. There is no Admin approval step and no new permission.
- Money received on those earlier days is recorded as late receipts with their real received dates. For a backdated plan's pre-creation days only, the **late-receipt lookback limit does not apply**. The receipt month must still be open.

## Implementation tasks

| ID      | Module | Task | Requirements | Status |
| ------- | ------ | ---- | ------------ | ------ |
| TPC-U02 | 06 Thrift Plans | Backdated plan creation and amendment; Catch-up pre-creation slots; renewal start-date floor | TPC-FR-039–041, TPC-AC-012 (rewritten), TPC-AC-052–058 | Implemented on `feat/backdated-plans`, 19 November 2026; browser check open |
| COL-U01 | 07 Collections | Late receipts for a backdated plan's pre-creation days beyond the lookback; Catch-up card status | COL-041, COL-042, COL-AC-064, COL-AC-065 | Implemented on `feat/backdated-plans`, 19 November 2026; browser check open |

## Terms

- **Backdated plan:** a plan whose start date is earlier than its creation date.
- **Creation date:** the local calendar date, in the plan timezone, when the plan creation commits. It is stored and never changes.
- **Pre-creation slot:** a slot of a backdated plan whose due date is earlier than the creation date.

## TPC-U02 — Backdated plan creation

### Start date rule

| Start date | Rule |
| ---------- | ---- |
| Future | Unchanged: at most 365 calendar days ahead. |
| Today | Unchanged. |
| Past | Allowed when every financial month from the start date's month through the current month exists and is `open`, in the plan timezone. There is no fixed day limit. A **reason for backdating** is required: plain text, trimmed, 1–500 characters. |

- Preview checks the rule. Commit checks it again with the financial periods locked. If a month closes between preview and commit, the Agent gets a conflict and must review a fresh preview. Nothing is created.
- The number of contribution days stays 1–366. A backdated plan may lie wholly in the past, so that every slot is a pre-creation slot.
- A first plan may start before the Customer's registration date (the onboarding case). The registration date itself stays a server timestamp and still cannot be backdated.
- Timezone, frequency, currency and fee terms work as before. The plan uses the current business timezone and the **current** applicable fee rule at creation. No historical fee rule is looked up for a past start date.
- **Renewal / Start new page:** the new page's start date must be **on or after the predecessor's closure date** (local date), so pages never overlap in time. The default start date stays today.
- One open plan per Customer, Customer and Agent eligibility, the attestation and the operation reference work as before.

### Creation flow

- **Form.** The date picker accepts past dates. When the chosen date is before today, a required **Reason for backdating** field appears with this notice: "Days before today are catch-up days. Record money you have already received with the date you received it. Money held by someone else or an old system is not imported."
- **Preview.** Adds a **Backdated** badge, the creation date (today), the number of pre-creation slots and their expected total. If a month is not open, the preview names it and blocks confirmation.
- **Attestation.** Adds that the Customer agreed to the plan from the stated start date.
- **Fingerprint.** The preview fingerprint includes the backdate reason, so changing the reason needs a new preview.
- **Storage.** The terms revision stores `backdate_reason` (nullable) and `created_local_date`. The plan is created Active, as today. The `created` lifecycle event keeps `effective_at = now()`. Lifecycle events, assignments, registration and ledger `committed_at` are never backdated.
- **Money.** Creating the plan posts no money, as before.

### Amendment

- **Fixed dead end.** A plan with no financial activity whose start date has passed can now be amended while keeping its current start date. Today any amendment of such a plan is rejected, even a name-only edit.
- **New past start date.** Moving the start date to a different past date counts as backdating: the same open-month rule applies and a reason is required. Pre-creation slots are worked out against the plan's **original** creation date.
- **After activity.** The start date stays locked once the plan has financial activity, as before.

### Display, notifications and audit

- **Agent and Admin views.** The plan detail page and directory show a **Backdated** badge, the start date, the creation date and the reason. The directory gets a **Backdated** filter.
- **Customer view.** The Customer sees the start date and a neutral line: "Plan started on {start date}, set up on {creation date}." The internal reason is not shown.
- **Creation notification.** The notice includes the start date and says that earlier days are catch-up days. It never claims money was paid.
- **Audit.** `plan.created` and `plan.revised` add `backdated`, `backdate_reason`, `pre_creation_slots` and `created_local_date`.

## COL-U01 — Collections for a backdated plan

### Catch-up slots

- A pre-creation slot with no funding shows **Catch-up**: pending and payable.
- It is never automatically shown as Missed, Unavailable or Service-interrupted. This holds whatever the Customer status or assignment history was before the plan was created.
- The Agent may still explicitly mark it Missed or Skipped, with a reason, through the existing annotation action.
- Slots due on or after the creation date follow the existing rules. For example, they become Missed once past and unfunded.
- In the daily workspace, Catch-up slots are listed under catch-up work and never as due today.
- Dashboard and report missed counts and collectible shortfall leave out Catch-up slots that have not been annotated.

### Late receipts beyond the lookback

A receipt may be dated earlier than the `late_lookback_days` setting allows only when **all** of these hold:

1. The receipt is for a backdated plan.
2. The received date is on or after the plan's start date and earlier than its creation date.
3. The received date's financial month is open.
4. A late reason is given.

Every other receipt keeps the normal lookback. Future received dates stay forbidden.

- **Allocation.** Unchanged: oldest unfunded active slots first. `is_advance` is set as it is today.
- **Batches.** Receipts dated in the past use the existing supplemental batch revision, and the freeze job handles them as it does today.
- **Custody.** The receipt is attributed to the recording (current) Agent, who owes the cash or remittance like any receipt. Importing money held by a former Agent or an old system remains deferred (opening-balance migration).
- **Activity and completion.** The first receipt sets `activity_started_at` and locks the terms, as before. If late receipts fund every slot, the plan completes at once, with the normal completion fee and wallet sweep.
- **Audit.** The collection audit adds `backdated_plan_lookback` when this exception is used.

## Out of scope

- Admins creating or approving backdated plans.
- Starting a plan in, or recording a receipt into, a closed financial month (no period override).
- Importing opening balances.
- Backdating Customer registration, assignments, lifecycle events or ledger `committed_at`.
- Applying historical fee rules.
- Future-dated receipts.

## Interfaces (for implementation)

- **Start-date guard:** `ThriftPlanService::normalizeTerms`, used by preview, create, previewRevision and revise. Also the preview fingerprint and the renewal predecessor check.
- **Migration:** add `backdate_reason` (nullable string 500) and `created_local_date` (date) to `plan_terms_revisions`. Backfill `created_local_date` from `thrift_plans.created_at` in the plan timezone.
- **Requests:** `StoreThriftPlanRequest` and `UpdateThriftPlanRequest` accept `backdate_reason`.
- **Receipts:** the received-date window in `CollectionService::preview` and `CollectionPaymentEvidenceService`. `FinancialPeriodService::assertOpen` stays authoritative.
- **Card and workspace:** `CollectionReadService` slot status and `CollectionWorkspaceService` due and catch-up lists, plus the dashboard and report missed counts.
- **UI:** `plans/Create.vue`, `plans/Edit.vue`, the plan show and index pages, and the collections thrift card.
- **Tests:** the existing TPC-AC-012 rejection of a past start date is reversed. Add new Pest feature tests for TPC-AC-052–058 and COL-AC-064/065.
