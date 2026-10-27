# Module updates — October 2026

## Summary

Field operations needed six changes that conflicted with rules in the confirmed module baselines. This document records each change, the decision behind it, and where it lives. The owning module specifications carry the matching functional requirements and acceptance criteria.

Decisions confirmed by the product owner on 27 October 2026:

- An Agent may register a Customer as **offline only**. Email is optional for them and required for everyone else.
- Payouts may be made **outside the platform** (cash or bank transfer, no payment gateway). Staff record them; the Customer does not confirm.
- Customer search includes the **address**.
- The registration fee is recorded at registration as **Pending**, **Part payment (amount)** or **Paid in full**. It is **not one-time**: staff can raise it again manually.
- A **full withdrawal before a page/month is complete closes the page**. The Customer's next page starts fresh, and the service charge is deducted from that new page.
- The **dashboard** groups its overview into tabs and shows, for the business-local day: customers registered, registration fee charged, service charge (plan fee) deducted from savings, and deposits.

## Implementation tasks

| ID      | Module | Task | Requirements | Status |
| ------- | ------ | ---- | ------------ | ------ |
| CAM-U01 | 04 Customer and Agent Management | Offline-only Customers: optional email, no invitation, later "Give app access" | CAM-FR-039, CAM-AC-058 | Done, 27 October 2026 |
| CAM-U02 | 04 / 07 | Address in Customer directory and collections workspace search | CAM-FR-040, CAM-AC-059 | Done, 27 October 2026 |
| FEE-U01 | 05 Fees and Deductions | Pending / Part payment / Paid in full at registration | FEE-FR-037, FEE-AC-049 | Done, 27 October 2026 |
| FEE-U02 | 05 Fees and Deductions | Manual repeat registration fee | FEE-FR-038, FEE-AC-050 | Done, 27 October 2026 |
| WDL-U01 | 08 Withdrawals / 15 Settings | Offline payout method ("Paid outside platform") | Module 08 and 15 updates | In progress on branch `claude/angry-hofstadter-329522` |
| TPC-U01 | 06 Thrift Plans / 08 | Full early withdrawal closes the page; "Start new page" renews with a fresh service charge | Module 06 §12.1 update | In progress on branch `claude/angry-hofstadter-329522` |
| DSH-U01 | 11 Dashboard | Overview tabs and four "today" figures | Module 11 update | In progress on branch `claude/determined-thompson-6a30b9` |

## CAM-U01 — Offline-only Customers

**Behaviour**
- The registration form has an **Offline only** switch. When it is on, email is optional and the page explains that the Customer gets no email or app access.
- An offline-only Customer is created with no email and no invitation. Their account stays `invited` with no password, so they cannot sign in. Registration does not need a verified invitation sender for them.
- A Customer who is not offline only must give an email (validation message on the email field).
- The Customer page shows an **Offline only** badge and card. Staff who can manage invitations can **Give app access**: this saves the email, sends invitation generation 1, clears the flag, and records `customer.online_access_enabled`.
- Customer mail notifications skip a recipient with no email instead of sending to nobody.

**Interfaces**
- Migration `2026_10_27_065739_add_offline_only_to_customer_profiles_and_nullable_user_email`: `customer_profiles.offline_only` (boolean, default false); `users.email` and `users.email_normalized` become nullable (unique indexes kept; several NULLs are allowed). Rolling back is refused while any user has no email.
- `POST customers/{customer}/online-access` → `customers.online-access.enable` (platform class `mutation`).
- Code: `CustomerRegistrationController`, `CustomerRegistrationService`, `CustomerInvitationManagementService::enableOnlineAccess`, `CustomerInvitationController::enableOnlineAccess`, `DeliverCustomerStatusNotificationIntent`, `DeliverCustomerHandoverNotice`, `customers/Create.vue`, `customers/Show.vue`, `components/CustomerOfflineAccessCard.vue`.

## CAM-U02 — Address in search

- Customer directory search and the collections workspace search match any part of `customer_profiles.address`, inside the viewer's existing scope.
- Placeholders now read "Search by name, phone, email or address" and "Name, ID or address".
- Code: `CustomerDirectoryController`, `CollectionWorkspaceService`, `customers/Index.vue`, `collections/Index.vue`.

## FEE-U01 — Registration fee payment option

- The registration form asks for **Fee payment**: Pending, Part payment (with the cash amount collected) or Paid in full. It is hidden for a zero fee.
- Part or Paid posts an ordinary cash fee-only collection receipt for that amount in the same transaction as the registration. It goes through the normal custody, batch and ledger path (`ExternalFeeReceipt`, recorded as a Settlement entry).
- A part amount must be more than zero and less than the fee. If the receipt cannot be recorded (collections off, closed financial period), nothing is saved and the reason is shown.
- The fee status label "Partially settled" is now **Part payment**.
- Code: `RegistrationFeePaymentService` (shared with FEE-U02), `CustomerRegistrationService`, `FeeObligationStatus`.

## FEE-U02 — Repeat registration fee

- On the Customer page, **Charge registration fee** opens a dialog: payment option and reason. The assigned Agent can choose any option. An Admin with `fees.manage` can only raise a Pending fee, because only the Agent can attest to cash received.
- Each charge uses the current published registration rule and gets its own snapshot and obligation. The source is `registration` / `{customer profile id}:{n}`; the original registration keeps `{customer profile id}`.
- Requests are idempotent by attempt reference (`creation_attempts`, operation `registration_fee_charge`) and audited as `customer.registration_fee_charged`.
- Repeat fees show in the Customer's fee list and can be paid later through ordinary fee-only receipts. Nothing raises a repeat fee automatically.
- Interfaces: `POST customers/{customer}/registration-fees` → `customers.registration-fees.store` (platform class `financial`). Policy ability `CustomerProfilePolicy::chargeRegistrationFee`.
- Code: `RegistrationFeeChargeService`, `CustomerRegistrationFeeController`, `CustomerProfile::registrationFeeSnapshots()` / `ownsRegistrationFeeSource()`, source checks in `CollectionService` and `FeeSavingsApplicationService`, `components/RegistrationFeeChargeDialog.vue`.

## WDL-U01, TPC-U01, DSH-U01

Being built in separate sessions; this section will be completed when those branches are merged.

## Verification

- New tests:
  - `CustomerRegistrationAndActivationTest`: CAM-U01 × 4
  - `CustomerAndAgentDirectoryAndProfileTest` and `CollectionTest`: CAM-U02 × 2
  - `RegistrationFeeAcceptanceTest`: FEE-U01 × 4 (including the 3-option dataset), FEE-U02 × 3
- Full PHP suite on 27 October 2026 (SQLite, parallel): 2,924 tests, 2,719 passed, 205 environment-dependent skips, 0 failed (38,351 assertions).
- Pint and `vue-tsc` pass, and `vp check` passes on the changed Vue files.
- Not yet checked in a browser: no seeded test sign-in is available locally.
