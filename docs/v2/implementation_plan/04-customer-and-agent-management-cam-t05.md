# CAM-T05 — Agent Registration and Invitation

## Summary

Deliver a working Agent registration flow. An Admin with `agents.manage` can create one Inactive Agent with an Invited account, then manage the invitation. The Agent sets their own password and completes MFA. Registration creates no Customer assignment or fee.

## Implementation

- Add an Agent registration form and named create, submit, and creation-attempt lookup routes. Reuse the existing profile validation, normalization, public ID, authorization, Inertia, and Wayfinder patterns. Reject client-supplied status, role, IDs, attribution, and financial fields.
- Add durable creation attempts bound to the initiating Admin, business, operation, and normalized input. Repeated submissions with the same reference resolve one outcome; changed input conflicts. A committed replay returns the existing Agent only while the Admin still has read access.
- In one transaction, recheck the Admin’s current account and `agents.manage` grant, then create the Invited account, Inactive Agent profile and status history, invitation generation, delivery intent, attempt binding, and durable audit event. A failure in any required write rolls back the registration. Make invited passwords nullable and reject password sign-in until the Agent sets one through activation.
- Seed the single trusted business identity as **SaverApp**, with a versioned display name. Require explicitly configured, verified invitation sender readiness before accepting registration; never use the current placeholder mail identity as a fallback.
- Implement the Agent portion of Authentication’s invitation lifecycle: a hashed, single-use, email- and role-bound challenge valid for 24 hours; safe post-commit queued delivery; bounded retries of the same generation; and authorized resend, invited-email correction, and cancellation. Keep delivery state separate from registration and account state. A delivery failure preserves the registered Agent.
- Activation verifies the current challenge and Agent identity, lets the Agent choose a policy-compliant password, and enters the existing restricted MFA setup flow. Acknowledging recovery codes activates the account; the Agent profile stays operationally Inactive until CAM-T10’s explicit readiness action.
- Add the registration and invitation controls to the Agent directory/profile. Show separate registration, account, and delivery outcomes. Keep tokens, credentials, private notes, and provider diagnostics out of ordinary page props and audit summaries.

## Verification

- Test permission denial and revocation before commit, field and photo validation, normalized duplicate identities, concurrent submissions, changed-payload conflicts, lost-response lookup, scoped replay, and rollback at each required persistence stage.
- Test invitation expiry, single use, resend limits and token rotation, correction and cancellation, delivery failure or uncertainty, suppression of stale queued work, and activation through password and MFA without operational activation.
- Run focused Pest tests, frontend checks and build, PHPStan, and Pint for changed PHP. Update the existing task registers with evidence; mark only the Agent portion of AUTH-T05 and the shared module contracts delivered. Ask for the complete compact test suite after focused tests pass, as the project rules require.

## Assumptions

- “Complete Agent slice” includes the minimum Authentication, notification delivery, audit, and business identity contracts needed by CAM-T05; their broader modules remain open.
- **SaverApp** is the reviewed initial business display name. Production invitation sending remains gated until its sender and provider are verified.
- Admin and Customer invitation flows, Agent operational activation, and Customer registration remain separate tasks.
