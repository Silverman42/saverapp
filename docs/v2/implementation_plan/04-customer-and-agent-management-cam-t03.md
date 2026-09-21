# CAM-T03 — Customer Assignment Scope and Authorization

## Summary

Implement the assignment and authorization foundation required by later Customer workflows: versioned assignment history, explicit Agent eligibility, scope-safe queries and policies, and reusable commit-time reauthorization. Do not add registration, reassignment, lifecycle, directory, or profile-editing endpoints in this task.

## Persistence and Assignment History

- Add `customer_assignments` with Customer profile, Agent profile, assigning actor, required reason, `current`/`ended` status, nullable current marker, effective/end timestamps, and a per-Customer assignment version.
- Enforce one current assignment with a unique `(customer_profile_id, is_current)` constraint, using `1` for current and `NULL` for historical rows.
- Enforce unique `(customer_profile_id, version)` and index current assignments by Agent.
- Retain all history; assignment identity, participants, actor, effective time, and version are immutable, and assignment rows cannot be deleted.
- Add `CustomerAssignmentStatus`, the `CustomerAssignment` model/factory, and typed relationships for Customer assignment history/current assignment and Agent current/historical assignments. Include assignment participation in user historical-attribution deletion safeguards.

## Eligibility, Scope, and Authorization Interfaces

- Replace the ambiguous `AgentProfile::isEligible()` contract with an `AgentEligibilityService` and closed capability/result types:
    - `ReadAssignedCustomers`: exact Agent role, Active account, completed MFA, and profile present; operationally Inactive Agents remain read-only.
    - `PerformAssignedCustomerWork`: read requirements plus Active operational status.
    - `ReceiveAssignment`: work requirements plus no effective temporary authentication lock.
    - Return stable reason codes for missing profile, role drift, unusable account, incomplete MFA, operational inactivity, and temporary lock.
- Add a resource-scope service exposing scope-safe Customer and Agent builders:
    - Customers see only their own profile.
    - Agents see only Customers with a current assignment to them; historical assignment or attribution grants no access.
    - Active synchronized Admins receive baseline business-wide read scope.
    - Agents see their own Agent profile; active Admins see Agent records; Customers receive no full Agent-directory scope.
    - Invalid roles, unusable accounts, and missing relationships produce an empty query rather than leaking record existence.
- Add auto-discovered `CustomerProfilePolicy` and `AgentProfilePolicy` rules:
    - Customer viewing follows own/current-Agent/Admin scope.
    - Customer creation remains Agent-only and requires assignment-recipient eligibility; Admin creation is always prohibited.
    - Customer updates allow only the Customer, an eligible current Agent, or an Admin with `customers.manage`; later workflows still enforce field allowlists.
    - Reassignment requires `customers.reassign`; Agent management requires `agents.manage`; destructive deletion is denied.
- Add a shared commit-time guard for future owning services. Inside the caller’s transaction it must deterministically lock and reload the actor, Customer profile, current assignment, and relevant Agent profile; re-run the policy, permission, role, account, MFA, operational-status, and current-assignment checks; validate expected profile and assignment versions; and return locked context to the caller.
    - Lost authority throws an authorization failure.
    - Stale profile or assignment versions produce field-specific validation conflicts.
    - No mutation, notification, job, or audit success may occur before the guard passes.
- Add a query for eligible assignment recipients using database-level coarse filters, followed by the authoritative eligibility service under lock at commit.

## Test Plan

- Verify assignment relationships, immutable history, monotonic versions, one-current-assignment enforcement, indexed Agent lookup, and deletion protection.
- Exercise the full eligibility matrix: role drift, missing profile, invited/MFA-setup/suspended/deactivated accounts, incomplete MFA, Active/Inactive operational states, and the temporary-lock exception for existing assigned work versus receiving assignments.
- Verify Customer, Agent, and Admin query scopes and policies, including own Customer access, current versus former Agent access, Inactive Agent read-only access, baseline Admin reads, granular Admin mutations, known-ID denial, and historical-attribution denial.
- Verify scoped lists, counts, and searches cannot include out-of-scope records.
- Simulate stale forms and authority changes before commit: assignment replacement, Agent inactivity, account suspension, permission revocation, profile-version change, and assignment-version change must fail without partial effects.
- Run the focused Customer/Agent and authorization tests, the complete compact Pest suite, PHPStan, and `vendor/bin/pint --dirty --format agent`.

## Assumptions and Deferred Work

- The current database contains no Customer or Agent profiles, so no assignment backfill is required.
- Customer registration will create the initial assignment atomically in CAM-T06.
- Ending an assignment, creating its replacement, pending-work handover, immediate cross-module invalidation, notifications, and audit events remain CAM-T12/CAM-T13 responsibilities.
- No routes, Inertia pages, frontend types, financial behavior, or new dependencies are introduced.
- After implementation and verification, record CAM-T03 evidence in the Module 04 plan/register, mark the Module 04 portion of `AUTHZ-T07` delivered while leaving `AUTHZ-T07` in progress for Modules 05–10, and make CAM-T04 ready.
