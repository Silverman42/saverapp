# CAM-T06 — Customer Registration and Activation

## Summary

Build on the CAM-T05 work currently in the worktree. Eligible, operationally Active Agents will register Customers with an initial self-assignment and a confirmed registration-fee preview. Customers will activate through a seven-day invitation after acknowledging their fee; payment will not be required for activation.

## Implementation

- Add an Admin screen to publish the initial NGN registration-fee rule, protected by `fees.manage` and fresh authentication. Each published version has a fixed positive kobo amount or an explicit zero amount. Published terms remain immutable, and registration fails closed when no current rule exists.
- Add an Agent-only Customer form and authoritative fee preview. Reuse the existing profile validation, normalization, photo handling, public IDs, scope checks, creation attempts, and Wayfinder patterns. Reject client-supplied status, assignment, attribution, and financial fields.
- At commit, recheck Agent eligibility, unique Customer identities, and the previewed rule version. In one transaction create the Invited account, Active profile, initial assignment and status history, immutable fee snapshot, nonzero obligation when applicable, invitation, attempt binding, and audit event. Persist delivery intent with that boundary and dispatch only after commit.
- Extend invitation delivery and management for Customers: seven-day expiry, current-generation checks, bounded delivery retries, authorized resend, invited-email correction, and cancellation. The assigned Agent or an Admin with Customer management authority may manage an existing invitation. These actions preserve the original fee snapshot and obligation.
- Add Customer activation that confirms identity, displays the snapshotted fee, records acknowledgement, sets a policy-compliant password, verifies email, consumes the invitation, and signs the Customer in to their dashboard. Add safe registration, account, and delivery states to scoped Customer views.

## Interfaces and verification

- Add persistent fee-rule versions, Customer fee snapshots, and nonzero obligations using NGN integer kobo. An explicit zero rule creates a snapshot without a payable obligation.
- Add named routes for Admin fee publication, Customer preview/create/attempt lookup, Customer invitation management, and public Customer activation; regenerate Wayfinder bindings.
- Cover authorization and eligibility changes, positive and zero fees, missing or changed rules, validation and privacy-safe duplicates, atomic rollback, concurrent and uncertain attempts, scope loss on replay, invitation lifecycle, and activation before payment.
- Run focused Pest tests, `npm run types:check`, `npm run build`, PHPStan, and Pint for changed PHP. Update the existing Module 04, Authentication, and Fees task records with evidence. After focused tests pass, ask the user to run the complete compact suite as project rules require.

## Assumptions

- Existing eligible Active Agents can register Customers; newly invited Agents become eligible through CAM-T10.
- An Admin publishes the first fee rule through the new screen. No amount is seeded or inferred from an unavailable rule.
- Fee collection, settlement, waiver, refunds, earnings recognition, and Customer MFA remain outside CAM-T06.
