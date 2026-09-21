# AUTH-T02 — Shared Sign-In and Role Routing

## Summary

Align Fortify sign-in with `AUTH-001`, `AUTH-002`, and the currently implementable portion of `AUTH-007`: normalized email/password authentication, generic failures, fail-closed account-state checks, role-specific placeholder destinations, and removal of remaining passkey entry points.

## Implementation Changes

- Add a focused Fortify authentication action that:
    - Looks up `email_normalized` using the existing identity normalizer.
    - Verifies and rehashes passwords through Laravel's configured provider.
    - Preserves authentication timeboxing and the existing rate limiter.
    - Returns the same failure for unknown emails, invalid passwords, prohibited account states, and password-locked accounts.
    - Allows Customers through password authentication and preserves Fortify's challenge for Agent/Admin accounts with confirmed TOTP.
    - Fails closed for `mfa_setup_required` and Agent/Admin accounts lacking confirmed TOTP until AUTH-T04 provides enrolment.
- Add active-account middleware across authenticated web access:
    - Invited, MFA-setup-required, suspended, and deactivated sessions are invalidated and returned to login without exposing the state.
    - Temporary password locks block new password authentication but do not terminate legitimate active sessions.
- Introduce a single role-destination resolver and three guarded placeholder routes:
    - `/customer/dashboard` → `customer.dashboard`
    - `/agent/dashboard` → `agent.dashboard`
    - `/admin/dashboard` → `admin.dashboard`
    - Each renders the existing dashboard placeholder without adding Module 11 data.
    - `/dashboard` remains a compatibility dispatcher and redirects to the authenticated user's role route.
    - Direct access to another role's route returns `403`.
- Bind a custom Fortify login response so completed authentication lands at the role destination while preserving Fortify's JSON response contract and two-factor challenge flow.
- Set `auth.failed` to “We could not sign you in with those details.” and normalize the login limiter key consistently.
- Remove the remaining `.well-known/passkey-endpoints` route, passkey limiter, and inactive Fortify passkey configuration. Keep the historical migration and installed package unchanged because dependency removal requires separate approval.
- Update `docs/v2/tasks.md` from `In Progress` to `Completed` only after the focused evidence passes.

## Public Interfaces

- Add the three named role-dashboard routes above; keep `dashboard` as the stable generic entry point.
- Standardize all credential/account-state login failures under the `email` validation error with one generic message.
- Add no database schema, external API, or TypeScript authentication-contract changes.

## Test Plan

- Verify mixed-case and whitespace-padded email sign-in uses normalized identity.
- Verify Customer password login, Agent/Admin two-factor completion, and `/dashboard` dispatch reach the correct role route.
- Verify wrong password, unknown email, invited, suspended, deactivated, MFA-setup-required, password-locked, and missing mandatory TOTP cases remain unauthenticated with the identical generic error.
- Verify a non-password temporary lock does not block the password step and an existing active session survives a password-only lock.
- Verify each role can open only its own placeholder; cross-role direct requests are forbidden.
- Verify an authenticated session loses application access after suspension or deactivation.
- Preserve existing two-factor challenge, logout, closed-registration, and rate-limit tests.
- Verify `/register`, passkey routes, and the well-known passkey endpoint are unavailable.
- Run focused Pest tests, Pint, PHP static analysis, frontend checks/type-checking, and the production build; then request the full test suite before broader release validation.

## Assumptions

- AUTH-T04 will replace the temporary fail-closed MFA-setup behavior with restricted enrolment access.
- AUTH-T08/T09 will own session limits, saved destinations, immediate permission/assignment refresh, and complete authorization integration.
- Module 11 will replace the shared placeholder with authoritative role-specific dashboard pages without changing these route names.
