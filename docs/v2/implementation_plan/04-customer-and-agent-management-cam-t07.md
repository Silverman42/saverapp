# CAM-T07 — Profile Editing and Protected History

## Summary

Implement the confirmed Customer and Agent field-editing rules, including the Authentication, notification, and audit work needed to complete the feature. Build on the CAM-T06 work currently in the worktree.

## Implementation

- **Ordinary profile edits:** Replace the unrestricted `settings/profile` name and email update with dedicated, authorized workflows. Add Customer and Agent edit forms and named Wayfinder routes. Enforce actor-specific field allowlists, explicit optional clears, existing normalization and uniqueness rules, and archived-record read-only behavior. Reject mixed requests containing protected fields. At commit, recheck account state, permission, current assignment, Agent eligibility, and profile version. Treat unchanged normalized submissions as no-ops.
- **Identity changes:** Support pre-activation staff name and phone corrections with reasons; Customer self-service name and phone changes with fresh password authentication; and Agent phone changes with fresh password and authenticator verification. Implement seven-day staff name proposals with replace, accept, reject, cancel, expiry, and authority-loss handling. Acceptance rechecks requester authority, assignment, and profile version.
- **Active email changes:** Implement one shared workflow for Customer, Agent, and Admin accounts. Reserve the proposed address and require separate, single-use confirmations sent to the current and proposed addresses. Keep the current address authoritative until both confirmations succeed. Use the specified 30-minute token lifetime and three requests per account per 24 hours. On completion, revoke sessions, trusted devices, and outstanding access links, then require sign-in with the new address.
- **Audit and notifications:** Commit immutable audit events and linked protected before/after history with each material edit. Keep sensitive values out of ordinary audit payloads. Persist deduplicated notification intents with the edit, dispatch after commit, and recheck recipient scope before inbox access or email delivery. Include the permitted notices and delivery outcomes needed by these workflows.
- **Photos and frontend:** Preserve the current photo if an edit fails; remove the replaced asset only after a successful commit. Add responsive forms, validation and conflict states, name-proposal review, and email-change confirmation states using existing Vue components and page conventions.

## Verification

- Add focused Pest coverage for actor-specific field access, forged fields, optional clears, duplicate values, no-ops, stale versions, lost permission or assignment, concurrent activation, name-proposal lifecycle, and email confirmation and revocation.
- Cover audit durability and masking, notification deduplication and scope loss, photo rollback, direct endpoint denial, and omission of private data from Customer and Agent self-service props.
- Run affected Pest files, frontend type checks and build, PHPStan, and Pint. Update CAM-T07 and the relevant Authentication, Notifications, and Audit task records with evidence; complete them only when their acceptance scenarios pass.

## Assumptions

- CAM-T06’s current uncommitted worktree changes are the implementation baseline and must be preserved.
- Deliver the portions of Modules 13–14 and AUTH-T06 required by CAM-T07; unrelated module-wide work remains on its own plan.
- Keep SMS verification, bulk editing, financial changes, and unrestricted sensitive-history reveal out of scope.

## Implementation Evidence

**Status: Completed.** CAM-T07 implements authorized Customer and Agent profile editing, dedicated name and phone workflows, protected history, dual-confirmation active-account email changes, after-commit notification intents, and omission of private fields from self-service props.

- Focused regression set: 147 tests and 1,170 assertions passed across `PhoneAndEmailChangeTest.php`, `Settings/ProfileUpdateTest.php`, and `CustomerAndAgent`.
- Full Pest suite: 377 tests and 2,886 assertions passed.
- Targeted PHPStan: zero errors. Pint, `npm run types:check`, the eight-file `npx vp check`, and `npm run build` passed.
- The public email-confirmation GET does not reveal whether a pending record exists; only the single-use POSTed token can confirm a change. Expired requests release proposed-address reservations.
- CAM-T07 co-delivers the specific Authentication, Notifications, and Audit slices above. AUTH-T06 and AUTH-T11 remain Blocked for broader cross-module contracts; the Module 13/14 translation tasks remain unchanged.
