# AUTH-T08 — Server-Authoritative Session & Device Management

## Summary

Implement `AUTH-010`, `AUTH-046`–`AUTH-051`, `AUTH-053`, and `AUTH-055` as defined in Module 02 Section 8. This covers role-specific session inactivity and maximum lifetimes, concurrent device limits with explicit device eviction, Agent 30-day trusted devices, 10-minute non-extendable fresh-authentication windows, Admin and Agent 24-hour safe resume cookies, token rotation, and active session revocation.

## Implementation Changes

### 1. Database & Persistence

- Add session creation metadata and device identifiers to database session tracking.
- Create `agent_trusted_devices` table storing hashed device tokens, device names, IP, user agent, and a 30-day expiration timestamp (`trusted_until`).
- Store non-extendable fresh-authentication timestamp (`auth.fresh_until`) in the session payload upon successful verification (password for Customer; password + TOTP for Agent/Admin).

### 2. Session Durations & Inactivity Enforcement

- Register `EnforceSessionLimits` middleware on the `web` pipeline:
    - **Customer**: 7 days inactivity, 30 days maximum lifetime.
    - **Agent**: 1 hour inactivity, 24 hours maximum lifetime.
    - **Admin**: 30 minutes inactivity, 24 hours maximum lifetime.
    - Genuine user requests update `auth.last_active_at`; background pings and notifications do not extend inactivity.
    - Maximum lifetime is strictly hard-capped and never extended by activity.
    - Expired sessions are flushed and redirected to `/login` with an informative message.

### 3. Concurrent-Device Limits & Eviction

- Enforce maximum concurrent devices:
    - **Customer**: 5 devices.
    - **Agent**: 2 devices.
    - **Admin**: 1 device (Admin single-device rule).
- When a user signs in and their active unexpired session count has reached the limit:
    - The login attempt is held in a pending-eviction state.
    - Render an Inertia `DeviceEviction.vue` screen displaying existing sessions (browser/OS, approximate location, first sign-in, last active; complete IP address masked).
    - The user must explicitly select an active session to revoke before the new session is established.
    - Confirming eviction revokes the selected session, establishes the new session, and dispatches a queued security notification (`AdminConcurrentDeviceRevokedNotification`).

### 4. Agent Trusted Devices

- During Agent TOTP challenge, present a "Trust this device for 30 days" checkbox with clear advisory against shared/public devices.
- A recovery code cannot create a trusted-device authorization.
- Admins are prohibited from trusted devices (must always perform MFA).
- On subsequent logins, if a valid 30-day device token cookie is present and verified against `agent_trusted_devices`, the Agent's TOTP challenge is satisfied automatically.
- Trusted device authorizations are revoked upon password reset, email change, authenticator replacement, suspension, or deactivation.

### 5. Fresh Authentication

- Configure `config('auth.password_timeout')` to 600 seconds (10 minutes).
- `EnsureFreshAuthentication` middleware verifies that `auth.fresh_until` is present and valid.
- Prompts for password (Customer) or password + TOTP (Agent/Admin).

### 6. Admin and Agent Page Resume Cookie

- `ResumeCookieService`: On eligible GET requests by Admins or Agents, issues a 24-hour signed/encrypted `saver_resume_destination` cookie with internal relative path and approved query parameters.
- Excludes authentication routes, state-changing actions, error pages, and secrets.
- On login completion, validates cookie integrity, role binding, route validity, and permission before redirecting. Fallback to default dashboard (`admin.dashboard`, `agent.dashboard`, `customer.dashboard`).
- Preserved on normal sign-out; cleared on security-driven sign-out or account changes.

### 7. Session Revocation & Management UI

- Add an "Active Sessions & Devices" section to `resources/js/pages/settings/Security.vue`.
- Expose endpoints for individual session revocation ("Sign out this device"), "Sign out all other devices", and "Sign out everywhere".
- Ensure password reset and authenticator replacement revoke all existing sessions and trusted devices.

## Verification Plan

- Feature tests in `tests/Feature/Auth/SessionManagementTest.php` covering:
    - Role inactivity and maximum lifetime timeouts.
    - Concurrent device limits and explicit device eviction (Admin single-device rule).
    - Agent trusted devices (30-day bypass, recovery-code exclusion, Admin exclusion, revocation).
    - Fresh authentication 10-minute expiry and factor requirements.
    - Last-page resume cookie creation, 24-hour expiration, validation, and safe redirection.
    - Revocation of sessions and trusted devices on security events.
- Run full Pest suite (`php artisan test --compact`), Pint, TypeScript type check, and asset build.
