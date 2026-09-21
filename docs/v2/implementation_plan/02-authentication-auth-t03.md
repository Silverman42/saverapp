# AUTH-T03 — Password Policy and Role-Specific Reset Lifecycle

## Summary

Implement `AUTH-009` and `AUTH-013`–`AUTH-016` using the existing Fortify routes, database reset-token repository, queued mail, and database sessions. Preserve all account, authorization, MFA, assignment, and historical-attribution state while enforcing role-aware password and second-factor rules.

No database migration or dependency change is required.

## Implementation Changes

- Introduce one reusable role-aware password policy:
    - Customers: minimum 15 characters.
    - Agents/Admins: minimum 8 characters because MFA is mandatory.
    - All roles: allow spaces and printable characters, require uncompromised passwords, and remove mixed-case/number/symbol composition rules.
    - Apply it to password resets and authenticated password changes; keep the 15-character Customer rule as the safe global fallback.
- Extend the Fortify reset-link flow without changing its URLs or route names:
    - Normalize lookup emails with `IdentityNormalizer`.
    - Always return: “If an account exists for this email, we've sent password reset instructions.”
    - Send queued reset mail only to verified, eligible accounts. Invited/unverified accounts and Agent/Admin accounts without a confirmed factor remain silent no-ops.
    - Allow suspended and deactivated accounts to reset without changing their state.
    - Enforce separate account and request-source limits of one attempt per minute and five per hour while retaining the generic response.
    - Configure 15-minute expiry; rely on Laravel's hashed, single-row token repository so each newly issued token invalidates its predecessor.
- Make reset completion role-aware:
    - Customers submit only the token and new password.
    - Agents/Admins must submit exactly one valid TOTP or unused recovery code.
    - Verify TOTP through Fortify's provider and consume recovery codes only when the complete reset succeeds.
    - Reject Agent/Admin resets when no confirmed factor exists; activation or future assisted recovery remains the available route.
    - Within one database transaction, change the password, invalidate the reset token, revoke all database sessions, consume any recovery code, rotate persistent-login state, and clear only password-category locks.
    - Preserve user type, account state, Admin permissions, assignments, MFA configuration, non-password restrictions, and historical attribution.
    - Keep Laravel's `PasswordResetLinkSent` and `PasswordReset` events as the integration boundary for AUTH-T11; never place submitted secrets or tokens in events, logs, or flash data.
- Update the Inertia authentication experience:
    - Use Wayfinder with `useForm` and Vuelidate for the forgot-password and reset forms.
    - Validate a token before exposing role-specific fields. Invalid, expired, replaced, or used links show one neutral error and a link back to the forgot-password page.
    - For valid privileged resets, reuse the existing Reka OTP and recovery-code controls without exposing the role name.
    - Preserve password-manager paste/autofill and accessible labels, focus, errors, processing states, light/dark mode, and keyboard navigation.
    - Add `Cache-Control: no-store` and `Referrer-Policy: no-referrer` to reset-link pages.
    - Render auth-flow outcome toasts at bottom-center, including generic reset requests and successful password resets.

## Public Interfaces

- Preserve `password.request`, `password.email`, `password.reset`, and `password.update` with their existing methods and paths.
- Extend `POST /reset-password` with mutually exclusive optional `code` and `recovery_code` fields; one becomes required only for Agent/Admin accounts after token validation.
- Add reset-page props for `validToken`, `requiresSecondFactor`, and `passwordMinimum`; do not expose user type or account state.
- Return HTTP 200 with the same generic message for syntactically valid reset-link requests, including nonexistent, ineligible, throttled, and eligible accounts. Invalid email syntax remains a validation error.

## Test Plan

- Prove Customer 15-character and Agent/Admin 8-character boundaries, printable passphrases, absent composition requirements, compromised-password rejection, and consistent authenticated-password-update behavior.
- Verify normalized requests, identical browser/JSON responses across all account states, eligibility rules, queued notifications, and independent account/source rate limits.
- Freeze time to test 15-minute expiry, newest-token wins, single use, hashed storage, email-change invalidation, and neutral invalid-link rendering.
- Verify Customer email-link reset and Agent/Admin TOTP or recovery-code reset; reject missing, invalid, dual, or unavailable factors without consuming tokens or recovery codes.
- Verify success removes only the target user's sessions, clears only password locks, changes the remember token, invalidates every reset link, rejects the old password, and requires a fresh login.
- Verify invited, suspended, deactivated, and MFA-setup-required behavior while asserting role, state, factor data, assignments, and attribution remain unchanged.
- Run focused authentication tests, Pint, PHPStan, `npm run check`, `npm run types:check`, and `npm run build`. Request the complete Pest suite afterward.

## Assumptions and Tracking

- The confirmed decision is that an Agent/Admin without an existing confirmed authenticator or recovery code cannot use normal password reset.
- TOTP replay prevention and factor cooldowns remain in AUTH-T04/AUTH-T10.
- Trusted-device storage does not yet exist; centralize session revocation so AUTH-T08 can extend the same operation when trusted devices are introduced.
- Owner/Admin security notifications and canonical audit persistence remain in AUTH-T11; this slice preserves the framework events they will consume.
- Move AUTH-T03 to `In Progress` when implementation begins and `Done` after local evidence; defer `Completed` until its shared AUTH-T11 evidence is available.
