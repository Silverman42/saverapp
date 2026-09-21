# AUTH-T04 — Mandatory MFA and Authenticator Lifecycle

## Summary

Implement `AUTH-008` and `AUTH-023`–`AUTH-030` by replacing Fortify's optional starter behavior with mandatory, server-enforced TOTP for Admins and Agents. Cover enrolment, login challenges, replay protection, hashed recovery codes, authenticator replacement, lost-authenticator recovery, notifications, and per-context throttling without adding dependencies.

## Implementation Changes

- Add an `AuthenticatorState` enum and persistence for:
    - Active-factor last accepted TOTP timestep.
    - A single encrypted pending enrolment/replacement secret with purpose, expiry, and last accepted timestep.
    - Ten individually hashed recovery-code records with consumption timestamps.
    - Recovery-code acknowledgement time.
- Use a 10-minute pending-setup lifetime and TOTP configuration of six digits, 30-second periods, and a maximum ±1-period clock window.
- Fail migration when legacy Fortify factor data exists because plaintext-recoverable codes cannot safely meet the new storage contract. Transition active Admins/Agents without MFA to `mfa_setup_required`; keep Customers password-only.
- Add focused services for atomic TOTP verification/replay prevention, recovery-code issuance and consumption, factor-attempt limits, pending-factor expiry, and session revocation. Update password reset to use these services instead of Fortify's recoverable-code methods.
- Dispatch an idempotent delayed job when a pending factor is created; expired jobs clear only the matching pending generation. All lifecycle events and queued notifications are emitted after commit and contain no secrets or submitted codes.
- Customize Fortify's login pipeline and MFA handlers while preserving its route names:
    - Password-verified Admins/Agents without MFA receive a restricted setup-only session.
    - Confirmed factors enter the normal TOTP/recovery challenge.
    - Five invalid attempts end the current login/setup/replacement context; persistent ten-per-hour cooldowns and broader abuse intelligence remain AUTH-T10.
    - Successful password-plus-TOTP authentication records non-extendable password and MFA freshness timestamps for later AUTH-T08/T09 enforcement.
- Allow `mfa_setup_required` sessions only on enrolment, recovery-code acknowledgement, and logout routes. Other requests redirect to setup; invited, suspended, and deactivated accounts remain signed out.
- Initial enrolment generates recovery codes only after TOTP confirmation. Codes are returned once, never stored client-side or in session, and dashboard access remains blocked until acknowledgement.
- Replacement requires the current password and active TOTP. The old authenticator remains active until the pending secret is confirmed; success atomically swaps secrets, invalidates old codes, issues ten new codes, rotates persistent-login state, and revokes every other database session.
- A recovery-code login consumes one code and creates a restricted session that must complete authenticator replacement before dashboard access. Users without a valid factor receive a safe assisted-recovery handoff page for AUTH-T07.
- Recovery-code regeneration requires current password and TOTP, invalidates the entire previous set, returns the new set once, and warns when two or fewer unused codes remain.
- Remove self-service disabling for Admins/Agents. The existing DELETE endpoint may cancel a pending setup or replacement but must never remove an active mandatory factor.

## Public Interfaces and UI

- Preserve Fortify's existing `two-factor.*` paths and route names through application-owned handlers.
- Add a named mandatory-enrolment page, a recovery-code acknowledgement endpoint, and an assisted-recovery handoff page.
- Change `GET /user/two-factor-recovery-codes` to return no codes; raw recovery codes are available only in the immediate confirmation/regeneration response.
- Expose only non-secret UI state: authenticator state, pending expiry, acknowledgement requirement, and remaining recovery-code count.
- Replace the starter settings controls with:
    - Mandatory enrolment steps: scan/manual key, confirm TOTP, copy/download/print codes, acknowledge.
    - Authenticator replacement and cancellation.
    - Password-plus-TOTP recovery-code regeneration.
    - Remaining-code warnings and lost-authenticator guidance.
- Use Wayfinder, Inertia `useHttp`/forms, Vuelidate, existing Reka controls, Lucide icons, accessible keyboard/focus behavior, light/dark styling, and bottom-center outcome toasts.
- Mark setup, QR, manual-key, and one-time-code responses `no-store` with `Referrer-Policy: no-referrer`; load no third-party resources.

## Test Plan

- Verify the role matrix: Customers cannot manage MFA, while Admins/Agents cannot reach dashboards without confirmed and acknowledged enrolment.
- Verify setup expiry, abandonment, cancellation, restricted-session boundaries, and re-entry after password verification.
- Verify valid current and adjacent TOTP periods, rejection beyond the window, and atomic rejection of replayed or concurrent duplicate codes.
- Verify five invalid TOTP or recovery attempts terminate only the active context and never disable the factor or consume legitimate codes.
- Verify exactly ten codes are displayed once, stored only as hashes, consumed individually, warned at two remaining, and completely replaced on regeneration.
- Verify replacement proof, expiry, cancellation, concurrency, old-factor preservation on failure, atomic swap on success, and revocation of other sessions.
- Verify recovery-code login forces replacement, cannot create trusted-device authority, and cannot bypass into the application.
- Verify queued security notifications for enrolment, replacement lifecycle, factor revocation, recovery-code use/regeneration, and excessive failures without secret leakage.
- Re-run password-reset coverage against the shared verifier and hashed recovery-code store.
- Run focused Pest suites, Pint, PHPStan, frontend checks, Vue type-checking, and the production build.

## Assumptions and Tracking

- "Next implementation plan" means `AUTH-T04`, the next actionable task after completed `AUTH-T03`.
- Canonical audit persistence remains AUTH-T11; this slice emits redacted after-commit events and delivers its required queued security emails.
- Trusted-device storage, cross-session ten-per-hour cooldowns, bot challenges, and Admin lock visibility remain AUTH-T08/AUTH-T10.
- Mark AUTH-T04 `In Progress` when implementation begins and `Done` after local verification; promote it to `Completed` when the shared AUTH-T11 audit evidence passes.
