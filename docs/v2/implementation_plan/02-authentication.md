# Module 02 — Authentication and Account Access

## Summary

Implement the confirmed authentication model in staged, independently verifiable work while keeping authorization, Customer/Agent lifecycle, fee, notification, audit, and business-bootstrap ownership with their source modules. Module 02 owns identity proof, credential and factor lifecycles, account-access state, sessions, trusted devices, fresh authentication, recovery, and abuse protection. It does not grant role capabilities, create Customer or Agent profiles, calculate fees, deliver generic notifications, or define audit retention.

## Current Baseline

- The application runs Laravel 13.32, Fortify 1.39, Inertia 3.3, Vue 3, and Pest 4 with database-backed sessions.
- Module 01 already defines required `UserType` values, defaults factories to Customer, and removes public registration routes and links.
- Fortify currently provides shared email/password login, password reset, email verification, password confirmation, optional TOTP, and starter rate limits.
- The current application does not yet provide explicit access states, normalized email storage, role-aware activation/reset rules, mandatory Admin/Agent MFA, trusted devices, device limits, assisted recovery, progressive abuse controls, or the required security/audit integrations.
- Starter-kit passkeys are enabled, but passkeys are not part of the confirmed Version 2 password-plus-TOTP model. The authentication implementation must disable their routes and UI so they cannot bypass mandatory password or Admin/Agent authenticator checks. Reconsidering passkeys requires an explicit specification change.

## Implementation Tasks

| ID | Task | Requirements | Initial status and dependency |
| --- | --- | --- | --- |
| AUTH-T01 | Add normalized email identity and explicit account-access state foundations, preserving historical attribution and isolating temporary authentication restrictions from suspension or deactivation. | `AUTH-007`, `AUTH-012`, `AUTH-036`, `AUTH-060` | **Completed.** Foundations and account states established and verified in `AccountStateTest.php`. |
| AUTH-T02 | Align the shared sign-in pipeline with Version 2: generic failures, password-only primary authentication, safe role destinations, account-state gates, closed registration, and no passkey bypass. | `AUTH-001`, `AUTH-002`, `AUTH-007` | **Completed.** Pipeline, role destinations, generic failures, and active-account middleware verified in `AuthenticationTest.php`. |
| AUTH-T03 | Implement the password policy and role-specific reset lifecycle, including generic requests, 15-minute single-use tokens, newest-token invalidation, Agent/Admin second-factor proof, post-reset revocation, and special account-state behavior. | `AUTH-009`, `AUTH-013`–`AUTH-016` | **Completed.** Password policy, 15m expiration, generic broker, role-specific TOTP/recovery verification, session revocation, and security notifications implemented and verified in `PasswordResetTest.php`. |
| AUTH-T04 | Implement mandatory Admin/Agent TOTP enrolment and challenge, one-time-code replay protection, authenticator replacement, lost-authenticator handling, hashed recovery codes, recovery-code regeneration, and factor-specific throttling. | `AUTH-008`, `AUTH-023`–`AUTH-030` | **Completed.** Mandatory Admin/Agent TOTP, enrolment, replay protection, replacement, hashed recovery codes, and factor throttling implemented and verified in `TwoFactorEnrolmentTest.php`, `TwoFactorChallengeTest.php`, `TwoFactorReplacementTest.php`, and `TwoFactorRecoveryCodesTest.php`. |
| AUTH-T05 | Implement first-Admin provisioning and Admin, Agent, and Customer invitation/activation lifecycles, including ownership, token security, expiry, resend, correction, cancellation, duplicate prevention, Customer fee snapshot, and acknowledgement. | `AUTH-003`–`AUTH-006`, `AUTH-031`–`AUTH-039` | **Blocked.** Requires Module 03 permissions, Module 04 profile/assignment and atomic registration decisions, Module 05 fee contracts, Module 13 delivery contracts, Module 14 audit contracts, and Module 15 trusted bootstrap data. |
| AUTH-T06 | Implement dual-confirmation active-account email changes with role-appropriate fresh authentication, proposed-email reservation, replacement/cancellation, token invalidation, session revocation, and required notices. | `AUTH-040`–`AUTH-045` | **Blocked.** Requires finalized Module 13 security-notification delivery and Module 14 audit/event contracts. |
| AUTH-T07 | Implement Customer, Agent, Admin, and final-Admin assisted recovery with separation of duties, state transitions, credential revocation, Admin cooling-off restriction, and single-use emergency keys. | `AUTH-017`–`AUTH-022` | **Blocked.** Requires Module 03 permissions and separation of duties, Module 04 assignment/identity-verification evidence, Module 13 notifications, Module 14 security/audit evidence, and Module 15 bootstrap ownership. |
| AUTH-T08 | Implement server-authoritative session/device management: role-specific inactivity and absolute limits, explicit concurrent-device eviction, Agent trusted devices, fresh-authentication timestamps, safe resume destinations, token rotation/reuse response, and revocation. | `AUTH-010`, `AUTH-046`–`AUTH-051`, `AUTH-053`, `AUTH-055` | **Completed.** Role session lifetimes, device limit eviction flow, Agent trusted devices, fresh authentication (10m), safe resume cookies, and active session revocation implemented and verified in `SessionManagementTest.php`. |
| AUTH-T09 | Integrate account state, fresh authentication, permission versions, assignment changes, and session expiry with server authorization and idempotent financial resumption. | `AUTH-007`, `AUTH-049`, `AUTH-052`, `AUTH-054` | **Blocked.** Requires Module 03 authorization, Module 04 assignment behavior, and the owning fee, plan, collection, withdrawal, reversal, and ledger contracts in Modules 05–10. |
| AUTH-T10 | Implement separate failure counters, progressive password delays/locks, MFA and recovery-code cooldowns, accessible bot challenges, controlled unlock, counter-reset rules, and safe security visibility. | `AUTH-056`–`AUTH-063` | **To Do.** Privileged Admin visibility and durable notifications/audit complete in AUTH-T11. |
| AUTH-T11 | Integrate mandatory authentication notifications and canonical audit events without storing credentials, tokens, authenticator material, recovery codes, or session secrets. | `AUTH-011`, plus notification/audit clauses in `AUTH-016`, `AUTH-030`, `AUTH-039`, `AUTH-045`, `AUTH-055`, `AUTH-062`, and `AUTH-063` | **Blocked.** Requires Module 03 permission audiences, Module 13 event/delivery/template contracts, and Module 14 canonical event, retention, and security-operation contracts. |
| AUTH-T12 | Complete end-to-end acceptance evidence for all 80 Module 02 scenarios, including concurrency, direct endpoint calls, revocation, redaction, accessibility, and failure recovery. | Section 12; `AUTH-001`–`AUTH-063` | **Blocked.** Requires AUTH-T01–AUTH-T11 and their owning cross-module dependencies to be completed. |

## Future Interface Boundaries

- **HTTP and UI:** retain one shared login and forgot-password entry point; provide dedicated invitation, mandatory-MFA, email-change, recovery, device/session, and lock-management flows. Every non-GET completion must return the project-standard outcome toast, and all Vue forms must use the established Inertia, Vuelidate, Wayfinder, Reka UI, light/dark, and accessibility conventions.
- **Persistence:** store normalized identity separately from accepted display spelling; represent account access, invitations, authenticators, recovery material, email-change requests, assisted-recovery requests, sessions/devices, trusted devices, fresh-authentication evidence, and abuse restrictions as server-authoritative lifecycle records. Store only hashes for bearer-style tokens and recovery codes, and encrypt TOTP secrets at rest.
- **Services:** expose purpose-specific token issuance/consumption, session revocation, fresh-authentication verification, factor verification, and abuse-counter operations. Callers must not set passwords or authentication secrets for another user.
- **Authorization:** every protected request rechecks current account state, role, exact Admin permission, resource scope, temporary restriction, and separation-of-duty rules. Module 02 supplies authentication state and freshness; Module 03 remains authoritative for permission decisions.
- **Events:** emit redacted, versioned authentication events only after the owning state transition commits. Module 13 transports approved content; Module 14 retains canonical evidence. Provider delivery state never changes authentication state.

## Acceptance Mapping

| Acceptance criteria | Owning tasks |
| --- | --- |
| 1–10 — first access, invitations, shared login, access loss, fresh authentication, authorization, and audit | AUTH-T02, AUTH-T04, AUTH-T05, AUTH-T09, AUTH-T11 |
| 11–16 — generic and role-specific password reset | AUTH-T03, AUTH-T11 |
| 17–24 — assisted and emergency recovery | AUTH-T07, AUTH-T11 |
| 25–34 — TOTP, authenticator replacement, recovery codes, secret protection, and factor audit | AUTH-T04, AUTH-T11 |
| 35–45 — invitation authority, lifecycle, fee snapshot, and token protection | AUTH-T05, AUTH-T11 |
| 46–54 — active-account email changes | AUTH-T06, AUTH-T11 |
| 55–68 — session/device limits, trusted devices, fresh authentication, resume, authorization refresh, and financial resumption | AUTH-T08, AUTH-T09, AUTH-T11 |
| 69–80 — progressive restrictions, isolated counters, unlock, notification, visibility, and distributed-abuse evidence | AUTH-T10, AUTH-T11 |

## Verification Strategy

- Each implementation task must add focused Pest feature tests for its successful lifecycle, important denial paths, token expiry/replay, concurrency/idempotency, state preservation, and secret redaction.
- Frontend tasks must add or update the narrowest applicable browser coverage for mandatory MFA, recovery, dual email confirmation, device eviction, session expiry, safe resume, and accessible failure states.
- Cross-module acceptance must call protected endpoints directly, change permission/assignment/account state during active sessions, and prove authorization changes immediately without partial mutations.
- Before promoting AUTH-T12, verify all 63 functional requirements and all 80 acceptance criteria have passing evidence, run the focused suites after each task, then run the complete PHP and frontend validation commands required by the project.

## Assumptions and Deferred Decisions

- Confirmed decisions in Modules 01–03 are binding. Proposed decisions in Modules 04–05 and 13–15 are not implemented until approved.
- Authentication email is the only activation, notification, and self-service recovery delivery channel in initial scope; Module 13 owns provider truth and delivery operations.
- Fortify may supply framework mechanics, but its starter behavior is not accepted where it conflicts with role-specific MFA, reset, session, token, replay, or abuse requirements.
- No task introduces public registration, role conversion, shared credentials, Admin-set permanent passwords, optional Admin/Agent MFA, or a platform super-administrator.
