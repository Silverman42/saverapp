# Authentication and Account Access

**Product version:** 2.0  
**Module status:** Draft  
**Depends on:** [User Types and Access Model](./01-user-types.md)

## 1. Purpose

This module defines how Customers, Agents, and Admins receive accounts, activate access, sign in, recover access, and end sessions.

Authentication proves who a user is. Authorization determines what that authenticated user may do. Role and permission checks must therefore be enforced by the server on every protected operation, not only by hiding interface elements.

The applicable capability boundaries, Admin permission codes, and meaning of an “authorized Admin” are defined in [Roles, Permissions, and Authorization](./03-roles-and-permissions.md).

## 2. Recommended Authentication Model

Version 2 should use a single authentication system for all three user types with role-specific onboarding and security controls.

- All users sign in from one login page.
- The login form does not ask the user to select a role.
- The system identifies the account and routes the user to the correct experience after authentication.
- All users authenticate with their email address and password.
- There is no public self-service registration page.
- Accounts are created only through the seeded setup or by an authorized user.
- Each person receives their own account. Credentials must never be shared.
- A user account has one user type: Customer, Agent, or Admin.
- Authentication state does not bypass role or Admin-permission requirements.

## 3. Account Provisioning by User Type

### 3.1 First Admin

The business profile and first Admin account are provided as seeded data.

The first Admin must complete a secure first-access flow:

1. Sign in using the seeded email address and initial password.
2. Replace the initial password if it was provisioned as temporary.
3. Enter mandatory authenticator setup if an authenticator is not already configured.
4. Scan the authenticator setup code and confirm it with a valid authenticator code.
5. Receive and securely store recovery codes.
6. Receive and securely store the single-use business emergency recovery key.
7. Review the configured business details.
8. Enter the Admin dashboard.

Any temporary credential must expire after one use or after a defined time limit. The first Admin must not continue using a shared default password.

### 3.2 Additional Admins

Only an Admin with the Admin-management permission can add another Admin.

The authorized Admin enters the new Admin's required identity and email address and assigns their initial permissions. The system then sends a single-use activation link or code to the new Admin's email address.

The new Admin must:

1. Verify the activation challenge.
2. Confirm their identity details.
3. Create their own password.
4. Set up an authenticator app.
5. Receive recovery codes.
6. Accept access to the Admin dashboard.

The inviting Admin must never create or know the new Admin's permanent password.

### 3.3 Agents

An Admin registers an Agent. The Agent cannot register themselves.

The Admin records the Agent's identity and email address. The system sends the Agent a single-use activation link or code by email. The Agent verifies the challenge, creates a personal password, and sets up an authenticator app before gaining access.

Agent activation must not depend on a shared password supplied verbally by an Admin.

Customers cannot be assigned to an Agent until the Agent's account is active.

### 3.4 Customers

An Agent creates a Customer and becomes that Customer's assigned Agent. The Customer cannot register themselves. Every Customer must have a unique email address and unique phone number.

After creation, the system creates a Customer invitation containing a snapshot of the applicable registration fee and sends an activation challenge to the Customer's email address. The Customer reviews the registration fee, verifies the email address, acknowledges the fee, and creates their own password before gaining access to the Customer experience.

The Agent must never create, view, request, or reset the Customer's permanent password.

If the Customer does not activate immediately, their savings profile may still exist for collection operations, but their account remains in **Invited** status and cannot be used to sign in.

### 3.5 Invitation ownership

There is no public registration. Invitations are the normal mechanism through which additional Admins, Agents, and Customers activate access.

| User being invited | Account created by                     | Invitation may be managed by               |
| ------------------ | -------------------------------------- | ------------------------------------------ |
| Admin              | Admin with Admin-management permission | Any Admin with Admin-management permission |
| Agent              | Authorized Admin                       | Any authorized Admin                       |
| Customer           | Agent                                  | The assigned Agent or an authorized Admin  |

Creating a Customer remains exclusive to Agents. An authorized Admin may manage an existing Customer invitation but cannot create the Customer.

### 3.6 Invitation states

Invitation state is tracked separately from account state.

The successful lifecycle is:

**Pending delivery → Sent → Opened → Activated**

For invitations, **Sent** means the email provider accepted the dispatch request; it is not proof of inbox delivery or human reading. Reliable provider delivery evidence is retained as separate delivery-attempt status under Module 13. **Opened** still requires the activation link to be accessed, and no provider-accepted message is labelled Delivered without the required callback evidence.

An invitation may instead become:

- **Delivery failed** — the email could not be delivered.
- **Expired** — the invitation was not used within its permitted lifetime.
- **Cancelled** — an authorized user deliberately invalidated the invitation.

An invitation becomes **Opened** when its activation link is accessed, not merely when an email client loads or previews the message. The related account remains in **Invited** state until the activation flow is fully completed.

### 3.7 Invitation expiration

- An Admin invitation expires 24 hours after issue.
- An Agent invitation expires 24 hours after issue.
- A Customer invitation expires seven days after issue.
- Expiration invalidates the activation token but does not delete or deactivate the underlying account.
- A Customer's profile, plans, collections, and financial history remain intact if an invitation expires.
- An authorized user may issue a new invitation after expiration.

### 3.8 Role-specific activation completion

#### Admin

The invited Admin must verify the email, confirm their identity details, create a password, configure and confirm an authenticator app, save ten recovery codes, and accept access before the account becomes **Active**. The role and initial permissions are set by the inviting Admin and cannot be changed by the invited user during activation.

#### Agent

The invited Agent must verify the email, confirm their identity details, create a password, configure and confirm an authenticator app, and save ten recovery codes before the account becomes **Active**.

#### Customer

The invited Customer must verify the email, confirm their identity details, review and acknowledge the registration fee, and create a password before the account becomes **Active**. Authenticator-app setup is not required for a Customer.

After activation, the system routes each user to the dashboard associated with their user type.

### 3.9 Resending invitations

- An authorized user may resend an invitation that is pending, delivery-failed, or expired.
- Resending creates a new token and immediately invalidates every previous invitation token for the account.
- Resends are limited to one per minute and five per day per account or request source.
- The invitation lifetime restarts when the new invitation is issued.
- Resending does not change the account's role, permissions, assignment, profile, thrift plans, registration-fee snapshot, collections, or financial history.
- The invitation manager can see the latest invitation status and issue time.

### 3.10 Correcting an email before activation

An authorized invitation manager may correct an email address while the account remains **Invited**.

1. The system validates that the corrected email is unique across all accounts.
2. The system invalidates all invitation, activation, and password-reset links issued to the previous email.
3. The system sends a new invitation to the corrected email.
4. Where delivery is possible, the system notifies the previous email that its invitation was cancelled.
5. The system records the previous email reference, corrected email reference, acting user, reason, and time in the audit log.

After activation, an email change must use the authenticated email-change or assisted-recovery workflow.

### 3.11 Cancelling an invitation

An authorized invitation manager may cancel an invitation before activation.

- Cancellation immediately invalidates all outstanding invitation and activation links.
- The invited account cannot activate from a cancelled invitation.
- The system records the cancelling user, time, and reason.
- Cancelling a Customer invitation removes login activation access but does not delete the Customer profile, plans, registration fee, collections, or financial history.

### 3.12 Duplicate-account handling

An email address identifies only one account across the system. When an internal user enters an email that already exists:

| Existing account state                 | Required behaviour                                                                   |
| -------------------------------------- | ------------------------------------------------------------------------------------ |
| Invited                                | Offer an authorized user the appropriate resend, correction, or cancellation actions |
| Active                                 | Prevent creation of another account                                                  |
| Suspended                              | Direct the authorized user to account management rather than creating a duplicate    |
| Deactivated                            | Require the reactivation workflow rather than creating a duplicate                   |
| Existing account has another user type | Prevent creation and do not silently change the existing role                        |

The system must also prevent creation of a Customer when the normalized phone number already belongs to another Customer.

### 3.13 Customer registration fee in invitations

The Customer invitation integrates with the future Fees module without owning fee calculation, payment, or accounting logic.

1. When an Agent creates a Customer, the system obtains the currently applicable registration fee from the configured fee rules.
2. The system saves the fee amount and fee-configuration reference as an immutable snapshot on the invitation.
3. Later changes to the configured registration fee do not change the amount presented by an already-issued invitation.
4. The invitation email displays the business name, Customer name, assigned Agent, registration fee amount, a brief fee explanation, and activation link.
5. The activation screen displays the same registration fee before password creation completes.
6. The Customer must acknowledge the displayed registration fee as part of activation.
7. The system records the fee amount, configuration reference, presentation time, acknowledgement time, and invitation reference.

Customer account activation is not blocked by registration-fee payment. If unpaid, the registration fee remains outstanding and is managed by the Fees module.

The Authentication module does not calculate, recognize as revenue, collect, waive, refund, reverse, or reconcile the registration fee. Those behaviours will be defined in the Fees module.

### 3.14 Invitation security and auditing

- Generate invitation tokens using a cryptographically secure random source.
- Store only a secure hash of each token.
- Bind each token to one account, user type, email, and activation purpose.
- Make each token single-use and enforce the role-specific expiration period.
- Invalidate tokens when the account email, user type, status, or invitation changes.
- Never send a temporary or permanent password in an invitation.
- Do not expose account status or user type on an invalid-link page.
- Prevent invitation tokens from entering logs, analytics, referrer information, or third-party resources.
- Require the complete role-specific activation flow before establishing an authenticated session.

The audit log must record the account creator; invitation sender; send, delivery, opening, expiration, resend, correction, cancellation, and activation events; initial role and Admin permissions; registration-fee snapshot and acknowledgement for Customers; and attempted use of invalid or consumed invitation tokens. Authentication secrets must not be recorded.

## 4. Sign-In Experience

### 4.1 Shared login

The login experience is a single page that accepts an email address and password.

After successful authentication:

- A Customer is routed to the Customer dashboard.
- An Agent is routed to the Agent collection dashboard.
- An Admin is routed to the Admin dashboard.

The interface must not reveal whether an entered email address belongs to an account, which role it has, or whether it is suspended. Failed authentication should use a generic message such as:

> We could not sign you in with those details.

### 4.2 Account identifiers

Email address is the unique sign-in identifier for every Customer, Agent, and Admin. The authoritative normalization rule is: trim surrounding whitespace, validate the remaining address, and compare the complete address case-insensitively. Do not remove dots, remove or rewrite plus-address suffixes, or apply provider-specific alias rules. Preserve the user's accepted spelling for display while using the normalized comparison value for uniqueness, sign-in, invitations, changes, and reservations.

Every Customer must also have a unique phone number. Phone numbers must be normalized to international format before uniqueness checks. The phone number is Customer identity and contact data but is not a Version 2 sign-in identifier.

### 4.3 Password requirements

- Permit long passphrases and password-manager-generated passwords.
- Require a minimum of 15 characters when a password is used as the only authentication factor.
- A shorter minimum, never below 8 characters, may be permitted only where MFA is always required for sign-in.
- Permit spaces and common printable characters.
- Reject known compromised or commonly used passwords.
- Do not require arbitrary mixtures of uppercase letters, numbers, and symbols.
- Do not force periodic password changes without evidence of compromise.
- Store only a salted, computationally expensive password hash; never store recoverable plaintext passwords.
- Allow password managers, paste, and browser autofill.

### 4.4 Email-address changes for active accounts

The pre-activation correction flow in Section 3.10 applies only to invited accounts. After activation, an email address can be changed only through this authenticated flow or assisted recovery.

#### 4.4.1 Shared email-change flow

1. The authenticated user opens **Security → Change email address**.
2. The user enters the proposed new email address.
3. The system normalizes the address, verifies that it can be used, and temporarily reserves it.
4. The user completes fresh authentication:
    - A Customer enters their current password.
    - An Agent enters their current password and a valid authenticator code.
    - An Admin enters their current password and a valid authenticator code.
5. The system sends a unique authorization link to the current email address.
6. The system sends a different verification link to the proposed email address.
7. Both links must be confirmed within 30 minutes.
8. Only after both confirmations succeed does the system replace the account email.
9. The system revokes all active sessions and trusted-device authorizations.
10. The system invalidates all outstanding password-reset, invitation, activation, and recovery links for the account.
11. The system sends the required security notifications and records the change in the audit log.
12. The user signs in again using the new email address.

Changing the email address does not change the user's password, authenticator configuration, user type, permissions, assigned Agent, account relationships, balances, transactions, or financial history.

#### 4.4.2 Pending email change

Until both confirmations succeed:

- The current email remains the account's sign-in, notification, and recovery address.
- The proposed email cannot be used to sign in or recover the account.
- Only one pending email change may exist for an account.
- Starting a new email change invalidates and replaces the previous request.
- The proposed email remains reserved and cannot be claimed by another account.
- Cancellation or expiration releases the proposed email reservation.
- The authenticated user may cancel the request from security settings.
- The authorization email sent to the current address provides a way to cancel the request.

#### 4.4.3 Role-specific notifications

| Account changed | Additional notification                                    |
| --------------- | ---------------------------------------------------------- |
| Customer        | Notify the assigned Agent after completion                 |
| Agent           | Notify active Admins with the relevant security permission |
| Admin           | Notify all active Admins                                   |

Security notifications should identify the affected account and event without unnecessarily exposing the complete previous and new email addresses.

#### 4.4.4 Access restrictions

- An Agent cannot directly change an active Customer's email address.
- An Admin cannot directly overwrite another active user's email address.
- A user who cannot access the current email must use assisted recovery.
- An invited account must use the pre-activation correction flow in Section 3.10.
- A suspended or deactivated account cannot use self-service email change.
- An Admin or Agent in mandatory MFA setup must complete authenticator enrolment before changing email.
- A completed email change cannot be reversed through an email link. Suspected unauthorized changes must enter assisted recovery.

#### 4.4.5 Email-change security controls

- Email addresses remain unique across the system.
- If a proposed email cannot be used, display a generic validation message without identifying the existing account or user type.
- Generate separate cryptographically random tokens for current-email authorization and new-email verification.
- Store only secure hashes of confirmation tokens.
- Bind each token to one account, one proposed change, one email address, and one confirmation purpose.
- Make each token single-use and expire it after 30 minutes.
- Limit email-change requests to three per account every 24 hours.
- Do not place credentials, account status, role, or unnecessary personal information in confirmation messages.
- Prevent confirmation tokens from entering logs, analytics, referrer information, or third-party resources.

#### 4.4.6 Email-change audit requirements

The audit log must record:

- The requesting user and affected account.
- References to the previous and proposed email addresses.
- Request, authorization, verification, cancellation, expiration, and completion events.
- The result of fresh authentication without recording the password or authenticator code.
- Relevant device and request context.
- Session, trusted-device, and outstanding-token revocation.
- Required notifications and their delivery result.

## 5. Multi-Factor Authentication and Step-Up Authentication

### 5.1 Admin

Multi-factor authentication using an authenticator app is mandatory for every Admin.

Recovery codes must be issued when the authenticator app is enrolled.

An Admin must complete fresh step-up authentication before:

- Adding, suspending, or removing an Admin.
- Changing Admin permissions.
- Changing authentication or recovery details.
- Changing security-sensitive business configuration.

Since the 8 October 2026 catalogue change in Section 8.5, Admin invitations, Admin permission changes and business-setting publication no longer repeat fresh authentication; they rely on the MFA-confirmed session, the exact permission and their own confirmation. Changing the Admin's own authentication or recovery details still requires it.

### 5.2 Agent

Multi-factor authentication is mandatory for every Agent when establishing a new session. After email and password are accepted, the Agent must enter an authenticator code unless a valid, previously established trusted-device authorization satisfies the possession factor under Section 8.2.

An Agent may mark a successfully authenticated device as trusted. Routine collection recording during a valid trusted-device session must not require another authenticator code for every collection. This keeps the primary field workflow fast.

Fresh authentication should be required before changing the Agent's password, email address, authenticator app, or recovery settings.

### 5.3 Customer

Customers authenticate with their verified email address and password. Authenticator-app MFA is not required for Customers.

Fresh password authentication is required before changing a password, email address, phone number, or recovery information.

### 5.4 Authenticator standard

Version 2 uses a standard time-based one-time password authenticator compatible with common authenticator applications.

- Codes contain six digits.
- Codes change every 30 seconds.
- Verification may allow no more than one adjacent time step in either direction to accommodate reasonable clock differences.
- A code may be accepted only once within its validity window.
- Each Admin or Agent account has one active authenticator at a time.

### 5.5 Mandatory MFA enrolment

If an Admin or Agent has valid credentials but no authenticator configured, successful password verification must route them to mandatory MFA enrolment instead of their dashboard.

1. The system creates a short-lived MFA enrolment session.
2. The system generates a unique authenticator secret.
3. The user scans the QR code or enters the manual setup key in their authenticator app.
4. The user enters a valid six-digit code from the app.
5. The system activates MFA only after successfully verifying that code.
6. The system generates ten single-use recovery codes.
7. The recovery codes are displayed once for downloading, printing, or secure storage.
8. The user confirms that the recovery codes have been saved.
9. The user completes authentication and enters their role-specific dashboard.

The Admin or Agent cannot skip enrolment or access protected application features before it is complete. If the enrolment is abandoned or expires before confirmation, the unconfirmed secret is invalidated and cannot authenticate the account.

### 5.6 Authenticator states

An authenticator has one of the following states:

| State                | Meaning                                                                      |
| -------------------- | ---------------------------------------------------------------------------- |
| Not configured       | The account has no confirmed authenticator                                   |
| Pending confirmation | A secret was generated but has not been confirmed with a valid code          |
| Active               | The authenticator can satisfy MFA challenges                                 |
| Replacement pending  | A new authenticator is being configured while the current one remains active |
| Revoked              | The authenticator can no longer satisfy MFA challenges                       |
| Recovery required    | The user cannot complete normal authenticator verification or replacement    |

### 5.7 Authenticator verification

After the system accepts an Admin's or Agent's email and password:

1. The user enters a current authenticator code. An Agent with a valid trusted-device authorization may use that device authorization instead.
2. The system verifies the authenticator code, its time window, and whether it has already been accepted, or verifies the Agent's trusted-device authorization.
3. On success, the system establishes the authenticated session and routes the user to the appropriate dashboard.
4. An Agent who used an authenticator code may establish a trusted-device authorization according to Section 8.2.

Authenticator attempts must use progressive rate limiting. After repeated failures, the system ends the current login attempt and requires the user to restart authentication rather than permanently suspending the account. A later successful authentication clears the failed-authenticator counter.

### 5.8 Authenticator replacement

An Admin or Agent who still controls their current authenticator may replace it without Admin approval.

1. The authenticated user selects **Replace authenticator**.
2. The system requires the current password.
3. The system requires a valid code from the current authenticator.
4. The system creates a replacement-pending secret and displays its QR code and manual setup key.
5. The user confirms the new authenticator with a valid code.
6. The system atomically activates the new authenticator and revokes the old authenticator.
7. The system revokes all other sessions and trusted-device authorizations.
8. The system invalidates all existing recovery codes and issues ten new codes.
9. The system displays the new recovery codes once and requires acknowledgement.
10. The system sends an email security notification and records the replacement in the audit log.

The current authenticator remains active until the replacement is successfully confirmed. An abandoned or expired replacement must not disable the current authenticator.

If the current authenticator is unavailable, the user must use a recovery code or assisted recovery instead of this normal replacement flow.

### 5.9 Recovery-code management

- Each Admin or Agent receives ten recovery codes after authenticator enrolment or replacement.
- Each code is unique to one account and can be used only once.
- The system stores only a secure hash of each code.
- Recovery codes are displayed only once and must never be included in email or logs.
- A used code is permanently consumed.
- The system warns the user when two or fewer unused codes remain.
- Regenerating recovery codes requires fresh password and authenticator verification.
- Regeneration invalidates all previously issued recovery codes and produces ten new codes.
- Recovery-code use and regeneration trigger an email security notification and an audit event.
- A recovery code cannot establish a trusted-device authorization by itself.

### 5.10 Lost-authenticator flow

When an Admin or Agent has lost their authenticator but still has an unused recovery code:

1. The user selects **Try another method**.
2. The user supplies an unused recovery code.
3. The system verifies the recovery code within the active login or recovery context.
4. The user declares the existing authenticator lost and enters mandatory authenticator replacement.
5. The user configures and confirms a new authenticator.
6. The system revokes the previous authenticator and all previous recovery codes.
7. The system revokes other sessions and trusted-device authorizations.
8. The system issues and displays ten new recovery codes.
9. The system sends an email security notification and records the event in the audit log.

If the user has no valid recovery code, they must use the assisted-recovery flow in Section 7.8.

### 5.11 Admin controls and separation of duties

- An Admin cannot view another user's authenticator secret or recovery codes.
- An authorized Admin may revoke an Agent's authenticator only through account suspension or approved assisted recovery.
- Admins and Agents cannot disable mandatory MFA on their own accounts.
- An Admin who controls their password and current authenticator may replace the authenticator without another Admin's approval.
- An Admin who cannot use the current authenticator or a recovery code must follow the high-security Admin recovery process.
- The final active Admin must use the approved emergency recovery process when ordinary recovery factors are unavailable.

### 5.12 Authenticator-secret protection

Authenticator secrets must remain available to the authentication service for code verification and therefore require controls different from password storage.

- Encrypt authenticator secrets at rest.
- Keep encryption keys separate from application data.
- Restrict decryption access to the authentication service and required operational processes.
- Never log, email, export, or expose an authenticator secret after enrolment.
- Prevent the QR-code and setup pages from loading third-party resources.
- Mark enrolment and replacement responses as non-cacheable.
- Prevent authenticator secrets or QR codes from entering analytics, error reports, or session-replay tools.
- Remove unconfirmed secrets when their enrolment or replacement session expires.

### 5.13 Authenticator notifications and audit

The system must send an email security notification and create an audit event when:

- Authenticator enrolment completes.
- Authenticator replacement starts, completes, is cancelled, or expires.
- An authenticator is revoked.
- A recovery code is used.
- Recovery codes are regenerated.
- Excessive invalid authenticator codes are submitted.
- Assisted recovery resets an authenticator.

Audit entries must identify the actor, affected account, time, result, reason, and relevant device context without storing the authenticator secret, QR code, submitted one-time code, or recovery code.

## 6. Account States

Every account must have an explicit access state.

| State              | Meaning                                                                                             |          Can sign in?           |
| ------------------ | --------------------------------------------------------------------------------------------------- | :-----------------------------: |
| Invited            | Account exists but activation is incomplete                                                         |               No                |
| MFA setup required | Admin or Agent credentials are valid, but authenticator setup is incomplete                         |           Setup only            |
| Active             | Activation is complete and access is allowed                                                        |               Yes               |
| Temporarily locked | One or more authentication methods are temporarily restricted after suspicious or repeated failures | Not through the affected method |
| Suspended          | An authorized Admin has disabled access                                                             |               No                |
| Deactivated        | Access has ended, but historical attribution is retained                                            |               No                |

Deleting a user account must not delete Customer records, collections, approvals, audit events, or any other financial history associated with that user.

Suspending or deactivating an Agent does not automatically reassign their Customers. The Admin must explicitly complete the reassignment workflow.

The system must prevent suspension or deactivation of the final active Admin.

## 7. Password and Access Recovery

### 7.1 Password-reset request

All user types begin from the same **Forgot password** entry point.

1. The user enters their email address.
2. The system displays the same result whether the email belongs to an active, invited, suspended, deactivated, or nonexistent account:

    > If an account exists for this email, we've sent password reset instructions.

3. If the account is eligible, the system sends a single-use password-reset link to its verified email address.
4. The link expires 15 minutes after it is issued.
5. Requesting a new reset link immediately invalidates every previous reset link for that account.
6. Opening a valid link displays the password-reset flow appropriate to the account's user type.

The reset request and public response must not reveal whether the account exists, its user type, its status, or whether an email was sent.

### 7.2 Customer password reset

Customers do not require MFA to reset a password.

1. The Customer opens the valid email reset link.
2. The Customer enters and confirms a new password that meets the password requirements.
3. The system changes the password and completes the reset actions in Section 7.5.
4. The Customer returns to the shared login page and signs in with the new password.

Possession of the verified email account and valid reset token is sufficient for the Customer reset flow.

### 7.3 Agent password reset

An Agent must prove possession of both the verified email account and an existing second factor.

1. The Agent opens the valid email reset link.
2. The Agent enters a valid code from their configured authenticator app.
3. If the authenticator app is unavailable, the Agent may use one unused recovery code instead.
4. The Agent enters and confirms a new password that meets the password requirements.
5. The system changes the password and completes the reset actions in Section 7.5.
6. The Agent signs in again with the new password and authenticator-app MFA.

A password reset does not remove, replace, or bypass the Agent's configured authenticator. If neither the authenticator app nor a recovery code is available, the Agent must leave the normal reset flow and use assisted recovery.

### 7.4 Admin password reset

An Admin must prove possession of both the verified email account and an existing second factor.

1. The Admin opens the valid email reset link.
2. The Admin enters a valid code from their configured authenticator app.
3. If the authenticator app is unavailable, the Admin may use one unused recovery code instead.
4. The Admin enters and confirms a new password that meets the password requirements.
5. The system changes the password and completes the reset actions in Section 7.5.
6. The Admin signs in again with the new password and authenticator-app MFA.

The password reset does not change the Admin's permissions or authenticator configuration. Other active Admins with the relevant security permission must be notified that the Admin's password was reset.

The final active Admin may use the normal email-plus-authenticator or email-plus-recovery-code flow. If that Admin has lost access to the verified email, authenticator app, and recovery codes, the account requires the separate emergency recovery procedure.

### 7.5 Successful reset actions

After any successful password reset, the system must:

- Revoke every active session belonging to the user.
- Revoke every trusted-device authorization belonging to the user.
- Invalidate all outstanding password-reset links for the account.
- Clear failed-password counters and any temporary lock caused by failed password attempts.
- Preserve the user's role, permissions, account relationships, and financial attribution.
- Preserve the existing authenticator configuration for an Agent or Admin.
- Record the completed reset in the authentication audit log.
- Send a password-change security notification to the verified email address.
- Return the user to the shared login page rather than automatically authenticating them.

### 7.6 Password-reset special cases

| Account or request state                  | Required behaviour                                                                                                 |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Invited account                           | Password reset must not activate the account. The user must request or receive a new invitation.                   |
| Suspended account                         | The password may be reset, but the account remains suspended and cannot sign in.                                   |
| Deactivated account                       | The password may be reset, but the account remains deactivated and cannot sign in.                                 |
| Temporarily locked by password failures   | A successful reset clears the temporary password-failure lock.                                                     |
| MFA setup required                        | The password may be reset, but the Agent or Admin must still complete authenticator setup before dashboard access. |
| Expired, invalid, or previously used link | The system shows a neutral error and provides a way to request another link.                                       |
| Newer reset requested                     | Every older reset link is invalid.                                                                                 |
| Account email changed                     | Reset links issued for the previous email address are invalid.                                                     |
| Account role or permissions changed       | The reset does not restore previous access or permissions.                                                         |

### 7.7 Password-reset security controls

- Generate reset tokens using a cryptographically secure random source.
- Store only a secure hash of each reset token.
- Bind each token to one account and one password-reset purpose.
- Accept a token only once and only before its expiration.
- Limit reset emails to one per minute and five per hour for the same account or request source.
- Apply abuse controls without changing the generic public response.
- Do not place passwords, authenticator codes, recovery codes, or account status information in email messages.
- Do not log reset tokens, passwords, authenticator codes, or recovery codes.
- Ensure the reset page does not leak its token through third-party content or referrer information.

### 7.8 Assisted recovery

Assisted recovery is separate from password reset. It applies when a user cannot satisfy the normal recovery factors, such as losing access to their verified email or, for an Agent or Admin, both the authenticator app and recovery codes.

Assisted recovery must never allow an Agent or Admin to create, view, or assign another user's permanent password. Approval authorizes the system to issue a single-use recovery link; the recovering user creates their own credentials.

#### 7.8.1 Recovery scenarios

| Access lost                                  | Required recovery route                                    |
| -------------------------------------------- | ---------------------------------------------------------- |
| Password only                                | Normal password reset                                      |
| Authenticator app only                       | Use an unused recovery code                                |
| Email only, while still signed in            | Change the email after fresh password and MFA verification |
| Email and password                           | Assisted recovery                                          |
| Authenticator app and recovery codes         | Assisted recovery                                          |
| Email, authenticator app, and recovery codes | High-security assisted recovery                            |

#### 7.8.2 Customer assisted recovery

When a Customer has lost access to their verified email:

1. The Customer contacts their currently assigned Agent.
2. The Agent verifies the Customer's identity in person using the Customer record and the business's approved verification procedure.
3. The Agent submits an assisted-recovery request containing the proposed new email address and verification notes.
4. An authorized Admin reviews and approves or rejects the request.
5. On approval, the system revokes the Customer's existing sessions, password, reset links, and trusted-device authorizations.
6. The system sends a single-use recovery activation link to the new email address.
7. The Customer verifies the new email address and creates a new password.
8. The system sends a security notification to the previous email address.
9. The system records the request, review, decision, activation, and completion in the audit log.

The assigned Agent may initiate the recovery but cannot approve it or set the Customer's password.

#### 7.8.3 Agent assisted recovery

When an Agent cannot use the normal password and MFA recovery paths:

1. An authorized Admin verifies the Agent against their identity and employment record.
2. The Admin initiates an assisted-recovery request and records the verification performed.
3. On approval, the system revokes the Agent's sessions, trusted devices, password, outstanding reset links, authenticator binding, and recovery codes.
4. The system sends a single-use recovery activation link to the Agent's verified new email address.
5. The Agent verifies the email and creates a new password.
6. The Agent configures and confirms a new authenticator app.
7. The system issues new recovery codes.
8. The system notifies active Admins with the relevant security permission.
9. The system records the complete recovery lifecycle in the audit log.

The Admin authorizing recovery cannot set or learn the Agent's new password or authenticator secret.

#### 7.8.4 Admin assisted recovery

An Admin who still controls their email and either their authenticator app or a recovery code uses the normal password-reset flow. High-security assisted recovery is required when those factors are unavailable.

1. Another Admin with Admin-management permission initiates the recovery request.
2. The recovering Admin's identity is verified outside the affected account using the business's approved verification procedure.
3. When two eligible Admin approvers are available, two different Admins must approve the recovery.
4. On final approval, the system revokes the recovering Admin's sessions, trusted devices, password, reset links, authenticator binding, and recovery codes.
5. The system sends a single-use recovery activation link to the verified new email address.
6. The recovering Admin verifies the email and creates a new password.
7. The recovering Admin configures and confirms a new authenticator app.
8. The system issues new recovery codes.
9. The system notifies all active Admins.
10. The system prevents the recovered Admin from adding or removing Admins or changing Admin permissions for 24 hours after recovery completes.
11. The system records the complete recovery lifecycle and every approval in the audit log.

The recovering Admin cannot approve their own request. Each approval must come from a distinct authenticated Admin with the required permission.

#### 7.8.5 Final Admin emergency recovery

Because Version 2 has no platform-level super administrator, the final active Admin requires a pre-established offline recovery method.

- During initial business setup, the system generates a high-entropy, single-use business emergency recovery key.
- The key is shown once and must be stored offline by the business.
- The system stores only a secure hash of the key.
- Emergency recovery requires both the key and access to the seeded Admin email address.
- A successful use immediately invalidates the key, all existing Admin sessions, the existing password, authenticator binding, recovery codes, and outstanding reset links.
- The Admin must create a new password, configure a new authenticator app, and receive new recovery codes before dashboard access.
- The system generates a replacement emergency recovery key after recovery and displays it once.
- Emergency recovery and replacement-key generation must be recorded in the audit log without recording either key.

If the final Admin has no other Admin available and has lost the registered email, authenticator, recovery codes, and emergency recovery key, the system must not allow automated account recovery.

#### 7.8.6 Assisted-recovery states

An assisted-recovery request follows this lifecycle:

**Requested → Verification required → Awaiting approval → Approved → Awaiting activation → Completed**

A request may instead become:

- **Rejected** — an authorized reviewer declines the request.
- **Cancelled** — an authorized user cancels the request before completion.
- **Expired** — the request or its activation link exceeds its allowed lifetime.

Every state transition must record the actor, timestamp, reason, affected account, previous and proposed email references, and approval information without storing authentication secrets.

#### 7.8.7 Assisted-recovery security rules

- Do not automatically lock an account merely because someone submitted a recovery request.
- Revoke credentials and sessions only after the required approval is complete.
- Send notifications to both the previous and new email addresses when an email change is approved.
- Make recovery activation links single-use and time-limited.
- Invalidate all older reset, invitation, and recovery links when recovery is approved.
- Prevent a requester, recovering user, or approver from fulfilling more than one role where separation of duties is required.
- Preserve the user's role, approved permissions, relationships, and historical attribution unless an authorized action separately changes them.
- Rate-limit recovery requests and approval attempts.

## 8. Session Management

### 8.1 Session durations and device limits

| User type | Inactivity timeout | Maximum session lifetime | Maximum concurrent devices |
| --------- | -----------------: | -----------------------: | -------------------------: |
| Customer  |             7 days |                  30 days |                          5 |
| Agent     |             1 hour |                 24 hours |                          2 |
| Admin     |         30 minutes |                 24 hours |                          1 |

Intentional authenticated user activity may extend the inactivity deadline but never extends the maximum session lifetime. Background polling, notification delivery, an open browser tab, or automatic refresh does not count as user activity.

### 8.2 Agent trusted devices

After completing email, password, and authenticator verification, an Agent may explicitly select **Trust this device for 30 days**.

- A trusted-device authorization allows password plus the previously bound device token to satisfy Agent MFA during its 30-day lifetime.
- Trusting a device does not create a 30-day authenticated session. Every Agent session still expires after one hour of inactivity or 24 hours in total.
- Trusted-device authorization is never enabled automatically.
- The interface must advise the Agent not to trust a shared or public device.
- A recovery code cannot create a trusted-device authorization.
- A suspicious login or material change in device context requires the authenticator app again.
- Password reset, email change, authenticator replacement, assisted recovery, suspension, deactivation, or confirmed compromise revokes all trusted-device authorizations.

Admins do not receive an MFA-bypass trusted-device option.

### 8.3 Admin single-device rule

An Admin may have only one active device session.

If an Admin completes password and MFA verification while another Admin session is active, the system must show the existing session and require the Admin to explicitly revoke it before continuing. The system must not silently revoke an existing Admin session merely because a new login was attempted.

After confirmation, the previous session is revoked, the new session is established, and an email security notification and audit event are created.

### 8.4 Concurrent-device management

- The system shows active devices with a recognizable device or browser name, approximate location, first sign-in time, and last activity time.
- Complete IP addresses must not be displayed to ordinary users.
- When a device limit is reached, the user must explicitly revoke an existing device before a new session is established.
- **Sign out this device** revokes the selected session.
- **Sign out all other devices** preserves the current session and revokes the remaining sessions and trusted-device authorizations as applicable.
- The server, not the browser, is authoritative for device and session limits.

### 8.5 Fresh-authentication windows

- An Admin's password-and-MFA verification remains fresh for 10 minutes for Admin management, permission changes, authentication settings, recovery settings, security-sensitive business configuration, and the high-risk financial or lifecycle actions explicitly designated by an owning Version 2 module.
- An Agent's password-and-MFA verification remains fresh for 10 minutes for authentication and recovery-setting changes.
- A Customer's password verification remains fresh for 10 minutes for account and recovery-setting changes.
- Ordinary browsing or session activity does not extend the fresh-authentication window.

Authentication owns the verification mechanics, factors, timestamp and non-extendable 10-minute duration. An owning module may require fresh authentication only for a specifically named action and must use this shared result rather than define another duration or credential flow. The initial Admin action catalogue includes Admin/permission management; Agent suspension, access restoration, offboarding and reactivation; fee-rule publication and sensitive fee/deduction/refund/business-draw actions; withdrawal decisions, pre-execution revocation and exceptional expiry resolution; reversal approval; and business-setting publication, scheduled-change cancellation, emergency disable and rollback. Changing this catalogue requires coordinated updates to Authentication and the owning module.

**Catalogue change, 8 October 2026 (product decision).** Admins no longer repeat fresh authentication for Admin invitations and their resend, correction and cancellation; Admin permission changes; staff assisted-recovery requests and decisions; business-setting publication and scheduled-change cancellation; financial-period opening, closing and reopening; and fee actions (registration-fee publication and retirement, fee waiver, correction and refund, savings application and prepared fee attempts). These actions still require an active, MFA-confirmed Admin session, the exact Admin permission, and their own reason, before-and-after review, confirmation and version checks. The window still applies to Agent suspension, access restoration, offboarding and return; withdrawal decisions and pre-execution revocation; reversal approval and rejection; cash and bank payout execution, returns and payout-destination decisions; business draws and cash fee refunds; evidence and collection-method publication; ledger-incident resolution; Customer recovery review; and every Customer, Agent and Admin self-service authentication, recovery, email and phone change.

### 8.6 Last-page resume cookie for Admins and Agents

The system must preserve the most recently visited eligible page for an Admin or Agent so they can return to it after signing in again.

- On every eligible page visit, the system updates a time-limited resume cookie with that page's internal URL, replacing the previously saved URL for that user and browser.
- The resume cookie expires 24 hours after the most recent eligible page visit.
- Normal session expiration or an ordinary explicit sign-out does not immediately remove the resume cookie.
- The cookie must contain only an internal relative path and approved, non-sensitive query parameters.
- Authentication pages, invitation pages, password-reset pages, MFA and recovery pages, logout routes, error pages, forbidden pages, state-changing actions, and external URLs are never eligible resume destinations.
- Passwords, tokens, Customer personal information, transaction details, and other secrets must never appear in the saved URL.
- The cookie must be securely signed or authenticated against tampering and bound to the account and user type without exposing a readable account identifier.
- Use `Secure`, `HttpOnly`, and appropriate `SameSite` cookie controls.

After successful login, the system must:

1. Read the saved resume destination for that browser.
2. Verify its signature, expiry, account binding, user type, internal origin, route validity, and the user's current permission to access it.
3. Redirect the user to the saved page if every validation succeeds.
4. Otherwise discard the saved destination and redirect to the user's default post-login page.

Default post-login destinations are:

| User type | Default destination        |
| --------- | -------------------------- |
| Customer  | Customer dashboard         |
| Agent     | Agent collection dashboard |
| Admin     | Admin dashboard            |

If another user signs in from the same browser, a resume cookie belonging to the previous user must be ignored and replaced. Permission removal, Customer reassignment, deleted routes, or newly restricted pages must also cause fallback to the appropriate default page.

A security-driven sign-out, account recovery, account suspension, deactivation, or confirmed compromise clears the resume cookie. A normal sign-out preserves it until its 24-hour expiration.

### 8.7 Session revocation and authorization refresh

All sessions and trusted-device authorizations must be revoked after:

- Password reset.
- Completed email change.
- Authenticator replacement.
- Assisted or emergency recovery.
- Account suspension or deactivation.
- Confirmed account compromise.

When an Admin's permissions change, active authorization must immediately use the new permission set. Removed permission must not remain available until the next login.

When a Customer is reassigned, the previous Agent must immediately lose access to that Customer and the new Agent must immediately receive the permitted scope. Authorization must be evaluated by the server for every protected request.

### 8.8 Session storage and cookie security

- Store authoritative session state on the server.
- Store only opaque identifiers in authentication cookies.
- Use `Secure`, `HttpOnly`, and appropriate `SameSite` cookie controls.
- Never store session or trusted-device tokens in browser local storage.
- Store only secure hashes of session and trusted-device tokens.
- Rotate session identifiers after password verification, MFA, recovery, and privilege changes.
- Detect session or refresh-token reuse and revoke the affected session family.
- Apply CSRF protection to state-changing browser requests.
- Never rely on a client-stored role, permission, assignment, or account status as proof of access.

### 8.9 Session-expiration experience

- Warn the user shortly before an active session reaches its inactivity or maximum-lifetime deadline.
- Displaying the warning does not extend the session.
- After expiration, redirect the user to the shared login page.
- Do not record a financial action if authentication expires before its submission is authorized.
- When a contribution or financial request is retried after reauthentication, use an idempotency key to prevent duplicate transactions.
- After successful reauthentication, use the validated resume destination or the user's default post-login page.

### 8.10 Sign-out behaviour

- **Sign out** revokes the current authenticated session.
- **Sign out all other devices** preserves the current session and revokes all others.
- **Sign out everywhere** revokes all sessions and trusted devices and requires a new MFA login from an Admin or Agent.
- Closing a browser does not replace explicit sign-out on a shared device.

### 8.11 Session audit events

The system must record session creation, renewal, expiration, and revocation; successful and failed fresh-authentication attempts; trusted-device creation, use, expiration, and revocation; new-device sign-ins; concurrent-device limit events; device-specific and global sign-out; resume-cookie validation failures; suspicious session or token reuse; and permission or assignment changes applied to active access.

## 9. Protection Against Authentication Abuse

### 9.1 Separate failure counters

The system must track failures separately for:

- Password sign-in.
- Authenticator codes.
- Recovery codes.
- Password-reset requests.
- Invitation and activation codes.
- Assisted-recovery attempts.

Limits must consider the account, normalized email, IP address, device context, and overall request source. The system must not rely on IP limits alone because legitimate users may share a network.

### 9.2 Password-failure thresholds

| Failed password attempts | Required response                                                              |
| -----------------------: | ------------------------------------------------------------------------------ |
|    1–4 within 15 minutes | Reject with the generic login error                                            |
|                        5 | Apply a one-minute cooldown                                                    |
|                      6–9 | Apply progressively longer delays of up to five minutes                        |
|       10 within one hour | Apply a 15-minute login lock and email the account owner                       |
|       20 within 24 hours | Apply a one-hour login lock and flag the activity for Admin or security review |

The public response remains:

> We could not sign you in with those details.

The response and observable behaviour must not reveal whether the account exists, which user type it has, or whether it is currently locked.

### 9.3 Authenticator-code failures

- Permit five authenticator-code attempts within one login attempt.
- After five failures, terminate the login attempt and require the user to restart with email and password.
- Ten failed authenticator-code attempts within one hour trigger a 15-minute MFA cooldown.
- Send an email security notification when the MFA cooldown begins.
- Treat an invalid, expired, or replayed TOTP code as a failed attempt.
- Clear the authenticator failure counter only after a successful complete password-and-MFA login.
- Failed MFA must never automatically disable, remove, or replace the configured authenticator.

### 9.4 Recovery-code failures

- Permit five recovery-code attempts within one login or recovery session.
- Five failures terminate the current recovery-code attempt.
- Ten recovery-code failures within one hour trigger a one-hour recovery-code cooldown.
- Send an email security notification when the cooldown begins.
- Do not invalidate legitimate unused recovery codes merely because invalid codes were attempted.
- A recovery-code cooldown does not permit MFA bypass. The user must wait for expiry or complete assisted recovery.

### 9.5 Bot and request-source protection

After repeated failures, the system may require an accessible bot-detection challenge before accepting another attempt. The challenge must provide an accessible alternative and does not replace password, authenticator, recovery-code, or identity verification.

Source-level monitoring must detect:

- One source attempting access to many accounts.
- Many sources attacking one account.
- Credential stuffing.
- Password spraying.
- Repeated invalid or replayed authenticator codes.
- Automated password-reset, invitation, activation, or assisted-recovery requests.

Existing reset, invitation, email-change, and recovery rate limits remain independently enforceable.

### 9.6 Temporary-lock behaviour

A temporary authentication lock:

- Blocks new login attempts for the affected authentication method.
- Does not change the account to suspended or deactivated.
- Does not remove the user's role, permissions, assignment, or records.
- Does not automatically revoke legitimate active sessions.
- Records a `locked_until` time, lock category, and reason.
- Automatically expires when its duration ends.

If the activity indicates probable account compromise rather than ordinary mistakes, the system may separately revoke active sessions and trusted-device authorizations and require account recovery.

### 9.7 Unlock methods

A temporary lock may be cleared by:

- Waiting until the `locked_until` time passes.
- Successfully completing the normal password-reset flow, for a password-failure lock.
- An authorized Admin manually unlocking a Customer or Agent after identity verification.
- Another Admin with the required security permission manually unlocking an Admin after identity verification.
- The final active Admin completing the normal or emergency recovery process.

A manual unlock must not reset a password, remove or replace MFA, activate an invited account, restore a suspended or deactivated account, restore revoked permissions, or change account relationships.

### 9.8 Counter-reset rules

- A successful complete login clears the applicable password-failure counter.
- Successful MFA clears the applicable authenticator-code counter.
- A successful password reset clears password-failure counters and locks but does not clear MFA or recovery-code restrictions.
- Completed assisted recovery clears authentication-failure counters after the recovered account is activated.
- Suspension and deactivation are unaffected by failure-counter resets or temporary-lock expiry.
- A partially completed login does not clear earlier failure counters.

### 9.9 User notifications and Admin visibility

The account owner receives an email when:

- A 15-minute or one-hour login lock begins.
- An MFA or recovery-code cooldown begins.
- An authorized Admin manually unlocks the account.
- Sessions are revoked because probable compromise was detected.

An authorized Admin may view:

- Lock category, reason, start time, and expiry.
- Approximate request source and relevant device context.
- Number and type of recent failed attempts.
- Notification delivery result.
- Whether manual unlock is permitted.

Admins must never see submitted passwords, authenticator codes, recovery codes, activation codes, reset tokens, session tokens, or authenticator secrets.

### 9.10 Authentication-abuse audit requirements

The system must record the authentication mechanism attempted; success or failure; applied delay, challenge, cooldown, or lock; affected account and request context; lock start, expiry, and reason; automatic and manual unlocks; Admin performing a manual unlock; session revocation caused by suspected compromise; required notifications and their delivery result; and detected distributed-attack patterns.

Authentication secrets and submitted codes must not be recorded. All authentication traffic must use HTTPS.

## 10. Audit Requirements

The system must record:

- Account creation and the creating user.
- Invitation and activation events.
- Successful and failed sign-in events.
- MFA enrolment, replacement, and removal.
- Password-reset requests and completions.
- Assisted-recovery requests, verification, decisions, state transitions, and completions.
- Admin recovery approvals and the identities of each approver.
- Emergency recovery-key generation, use, invalidation, and replacement without recording the key.
- Changes to verified phone numbers and email addresses.
- Account locking, unlocking, suspension, and deactivation.
- Session revocation.
- Session and trusted-device creation, renewal, expiry, device-limit enforcement, and sign-out.
- Resume-cookie validation failures and post-login fallback decisions.
- Admin permission changes.
- Step-up authentication for Admin permission and security-sensitive configuration changes.

Audit entries must contain a timestamp, user or account reference, event type, result, and relevant device or request context without storing authentication secrets.

## 11. Functional Requirements

### AUTH-001 — Unified sign-in

The system shall provide one sign-in experience for Customers, Agents, and Admins and route authenticated users according to their user type.

### AUTH-002 — Closed registration

The system shall not allow public self-registration.

### AUTH-003 — First Admin activation

The seeded first Admin shall replace any temporary password and complete mandatory authenticator-app enrolment before receiving dashboard access.

### AUTH-004 — Admin invitation

An Admin with the required permission shall be able to create and invite another Admin without selecting that user's permanent password.

### AUTH-005 — Agent invitation

An Admin shall be able to register an Agent and trigger a secure Agent activation flow.

### AUTH-006 — Customer invitation

An Agent shall be able to create a Customer and trigger a secure Customer activation flow.

### AUTH-007 — Role enforcement

The server shall enforce the authenticated user's role and permissions on every protected request.

### AUTH-008 — Admin and Agent MFA

The system shall require authenticator-app MFA from every Admin and Agent. An Admin must complete fresh authentication before changing their own authentication or recovery settings and before the Admin actions that remain in the Section 8.5 catalogue; Admin permission changes left that catalogue on 8 October 2026.

### AUTH-009 — Recovery

The system shall provide one password-reset entry point without revealing account existence, user type, or account status.

### AUTH-010 — Session revocation

The system shall revoke active sessions after suspension, deactivation, password reset, or confirmed account compromise.

### AUTH-011 — Authentication audit

The system shall audit account and authentication lifecycle events without recording authentication secrets.

### AUTH-012 — Historical attribution

Suspension, deactivation, or credential changes shall not remove the user's historical actions or financial attribution.

### AUTH-013 — Role-specific reset verification

The system shall allow a Customer to reset their password with a valid email reset link and shall additionally require an authenticator or recovery code from an Agent or Admin.

### AUTH-014 — Reset-token lifecycle

A password-reset token shall be single-use, expire after 15 minutes, and become invalid when a newer token is issued, the account email changes, or the password reset completes.

### AUTH-015 — Post-reset revocation

A successful password reset shall revoke the user's sessions and trusted devices, invalidate outstanding reset tokens, clear password-failure locks, preserve roles and permissions, and require a new login.

### AUTH-016 — Reset notifications and audit

The system shall notify the account owner after a successful password reset, notify appropriately permitted Admins when an Admin password is reset, and audit reset requests and completions without storing authentication secrets.

### AUTH-017 — Customer assisted recovery

The system shall allow an assigned Agent to initiate Customer recovery after identity verification and shall require an authorized Admin to approve it before the Customer can activate a new email and password.

### AUTH-018 — Agent assisted recovery

The system shall allow an authorized Admin to recover an Agent account by revoking its existing access factors and requiring the Agent to verify an email, create a password, configure a new authenticator app, and receive new recovery codes.

### AUTH-019 — Admin assisted recovery

The system shall require an Admin-management permission to initiate or approve another Admin's recovery, prohibit self-approval, and require two distinct approvers when two eligible approvers are available.

### AUTH-020 — Post-recovery restriction

The system shall prevent a recovered Admin from adding or removing Admins or changing Admin permissions for 24 hours after assisted recovery completes.

### AUTH-021 — Final Admin emergency recovery

The system shall provide the final Admin with a single-use offline emergency recovery key, store only its hash, require access to the seeded Admin email when it is used, invalidate it after use, and issue a replacement after recovery.

### AUTH-022 — Recovery lifecycle and audit

The system shall track assisted recovery through its defined states, preserve separation of duties, notify affected parties, and audit every action without recording recovery secrets.

### AUTH-023 — TOTP compatibility

The system shall support six-digit, 30-second TOTP authenticators and shall prevent reuse of an accepted code within its validity window.

### AUTH-024 — Confirmed enrolment

The system shall activate an authenticator only after the user confirms it with a valid code and shall invalidate abandoned or expired unconfirmed secrets.

### AUTH-025 — Recovery codes

The system shall issue ten single-use recovery codes, store only their hashes, display them once, warn when two or fewer remain, and invalidate the previous set when codes are regenerated.

### AUTH-026 — Authenticator replacement

The system shall require the user's password and current authenticator to replace an accessible authenticator, keep the old authenticator active until confirmation succeeds, and revoke old authentication material after replacement.

### AUTH-027 — Lost authenticator

The system shall allow a user with a valid recovery code to replace a lost authenticator and shall route a user without a valid recovery code to assisted recovery.

### AUTH-028 — Authenticator-secret protection

The system shall encrypt authenticator secrets at rest, restrict access to the authentication service, prevent secrets from entering logs or third-party tooling, and securely invalidate expired or revoked secrets.

### AUTH-029 — Authenticator abuse protection

The system shall rate-limit failed authenticator attempts, reject replayed codes, end an abused login attempt without permanently suspending the account, and notify the user when excessive failures are detected.

### AUTH-030 — Authenticator audit

The system shall notify affected users and audit authenticator enrolment, replacement, revocation, recovery-code use, recovery-code regeneration, and recovery without recording authentication secrets.

### AUTH-031 — Invitation ownership

The system shall allow only the defined creating and managing user types to create, resend, correct, or cancel Admin, Agent, and Customer invitations.

### AUTH-032 — Invitation lifecycle

The system shall track invitation delivery, opening, activation, failure, expiration, and cancellation separately from the invited account's access state.

### AUTH-033 — Invitation expiration

Admin and Agent invitations shall expire after 24 hours, Customer invitations shall expire after seven days, and expiration shall not delete the underlying account or its records.

### AUTH-034 — Invitation resend

The system shall allow an authorized user to resend an eligible invitation, invalidate all previous tokens, restart its expiry period, preserve account data, and enforce resend limits.

### AUTH-035 — Pre-activation correction and cancellation

The system shall allow an authorized invitation manager to correct an invited account's email or cancel its invitation while invalidating outstanding access links and preserving required records.

### AUTH-036 — Duplicate-account prevention

The system shall enforce a unique email across all accounts, a unique phone number across Customers, and reactivation or invitation-management workflows instead of creating duplicate accounts.

### AUTH-037 — Customer registration-fee snapshot

The system shall attach the applicable registration-fee amount and configuration reference to a Customer invitation as an immutable snapshot that is not changed by later fee-configuration updates.

### AUTH-038 — Registration-fee presentation and acknowledgement

The system shall display the snapshotted registration fee in the Customer invitation and activation flow, record the Customer's acknowledgement, and allow account activation while payment remains outstanding.

### AUTH-039 — Invitation security and audit

The system shall protect invitation tokens as single-use, role-bound, email-bound, time-limited secrets and audit the complete invitation lifecycle without recording credentials or raw tokens.

### AUTH-040 — Active-account email change

The system shall allow an active user to request a new email address after role-appropriate fresh authentication and shall require authorization through the current email and verification through the proposed email before completing the change.

### AUTH-041 — Pending email reservation

The system shall allow only one pending email change per account, reserve the proposed unique email until completion, cancellation, or expiration, and keep the current email authoritative until the change completes.

### AUTH-042 — Email-change completion

A completed email change shall revoke sessions and trusted devices, invalidate outstanding access and recovery links, preserve the user's role and account data, and require a new login with the new email.

### AUTH-043 — Email-change access boundaries

The system shall prevent Agents and Admins from directly overwriting another active user's email and shall route a user without access to their current email through assisted recovery.

### AUTH-044 — Email-change security

The system shall use separate, single-use, purpose-bound confirmation tokens for the current and proposed emails, expire them after 30 minutes, limit requests to three per account every 24 hours, and prevent account enumeration or token leakage.

### AUTH-045 — Email-change notifications and audit

The system shall send role-appropriate security notifications and audit every email-change request, confirmation, cancellation, expiration, completion, and related revocation without recording authentication secrets.

### AUTH-046 — Session durations

The system shall enforce the defined inactivity, maximum-lifetime, and concurrent-device limits, including a 24-hour maximum session lifetime for Agents and Admins and one concurrent Admin device.

### AUTH-047 — Agent trusted devices

The system shall allow an Agent to explicitly trust a device for 30 days after full password and authenticator verification without extending any individual Agent session beyond its one-hour inactivity or 24-hour maximum limit.

### AUTH-048 — Admin single-device enforcement

The system shall require an Admin who signs in while another Admin session is active to explicitly revoke the existing session before establishing the new session.

### AUTH-049 — Fresh authentication

The system shall enforce one non-extendable 10-minute fresh-authentication window for the security-sensitive, lifecycle and financial actions in Section 8.5 and any coordinated owning-module addition to that catalogue.

### AUTH-050 — Admin and Agent page resume

On each eligible Admin or Agent page visit, the system shall store the most recent internal destination in a secure, account-bound, role-bound resume cookie that expires after 24 hours.

### AUTH-051 — Safe post-login redirect

After login, the system shall validate the saved destination's integrity, expiry, account binding, user type, route, origin, and current authorization before redirecting and shall otherwise use the role's default post-login page.

### AUTH-052 — Immediate authorization changes

The system shall immediately apply Admin permission changes and Customer reassignment to active access without waiting for session expiry or another login.

### AUTH-053 — Secure session storage

The system shall maintain authoritative session state on the server, use secure opaque browser cookies, protect state-changing requests, rotate identifiers after authentication changes, and detect token reuse.

### AUTH-054 — Safe financial resumption

The system shall not record a financial action after its session expires and shall use an idempotency key when that action is retried after authentication.

### AUTH-055 — Session audit

The system shall audit session, device, trusted-device, fresh-authentication, sign-out, resume-cookie failure, suspicious-token, permission-refresh, and assignment-refresh events without recording session secrets.

### AUTH-056 — Separate authentication counters

The system shall maintain separate failure counters and restrictions for passwords, authenticator codes, recovery codes, reset requests, invitations, activation, and assisted recovery across account and request-source contexts.

### AUTH-057 — Progressive password protection

The system shall apply the defined password delays and temporary locks at five, ten, and twenty failed attempts while preserving a generic public response.

### AUTH-058 — Authenticator attempt protection

The system shall end a login after five failed authenticator codes, apply a 15-minute MFA cooldown after ten failures within one hour, reject replayed codes, and preserve the configured authenticator.

### AUTH-059 — Recovery-code attempt protection

The system shall end a recovery-code attempt after five failures, apply a one-hour cooldown after ten failures within one hour, and preserve all legitimate unused recovery codes.

### AUTH-060 — Temporary-lock isolation

A temporary authentication lock shall block only the affected new authentication path, expire automatically, preserve account state and access data, and leave legitimate active sessions intact unless probable compromise is separately detected.

### AUTH-061 — Controlled unlock

The system shall support automatic expiry, password-reset clearing of password locks, and permission-controlled manual unlock after identity verification without modifying passwords, MFA, account status, roles, permissions, assignments, or records.

### AUTH-062 — Abuse monitoring and notification

The system shall monitor account-level, source-level, and distributed authentication abuse and send the defined email notifications for locks, cooldowns, manual unlocks, and compromise-driven session revocation.

### AUTH-063 — Lockout visibility and audit

The system shall provide authorized Admins with non-secret lockout context and audit failures, delays, challenges, locks, unlocks, compromise actions, notifications, and detected attack patterns without storing submitted secrets.

## 12. Acceptance Criteria

This module is operational when:

1. The seeded first Admin can sign in with their seeded credentials and is required to configure authenticator-app MFA before entering the dashboard.
2. A permitted Admin can invite another Admin and assign initial permissions.
3. An Admin can register an Agent, who can activate their password and configure authenticator-app MFA.
4. An Agent can create a Customer, who can verify their email address and create a password.
5. Each user can sign in through the same login page and reach the correct dashboard.
6. A suspended or deactivated user immediately loses access on all active sessions.
7. Password recovery does not disclose whether an account exists.
8. An Admin must reauthenticate before changing Admin permissions. _(Changed by the 8 October 2026 catalogue decision in Section 8.5: permission changes now require an active MFA-confirmed Admin session, `admins.manage`, a reason, review and confirmation, but no repeated fresh authentication.)_
9. Role and permission restrictions remain enforced when protected endpoints are called directly.
10. Authentication and account-management events appear in the audit log without exposing secrets.
11. A password-reset request always returns a generic response, regardless of account existence, user type, or status.
12. Only the newest unexpired reset link works, and it can be used only once within 15 minutes of issue.
13. A Customer can reset with the email link, while an Agent or Admin must additionally supply a valid authenticator or recovery code.
14. A successful reset revokes every session and trusted device and requires the user to sign in again.
15. Password reset does not activate an invited account or restore a suspended or deactivated account.
16. Password reset preserves roles, Admin permissions, assignments, authenticator configuration, and historical financial attribution.
17. An assigned Agent can initiate but cannot approve a Customer recovery request, and the Customer creates their own new password after Admin approval.
18. An authorized Admin can recover an Agent account without creating or learning the Agent's new password or authenticator secret.
19. An Admin cannot initiate and approve their own recovery, and two distinct approvals are required when two eligible Admin approvers are available.
20. Assisted recovery revokes all affected credentials, sessions, trusted devices, reset links, authenticator bindings, and recovery codes before new access is activated.
21. A recovered Admin cannot add or remove Admins or change Admin permissions during the 24-hour post-recovery restriction.
22. The final Admin can recover using the offline emergency key and seeded email, and the used key is invalidated and replaced.
23. Assisted-recovery state transitions, verification evidence, decisions, approvers, notifications, and completion appear in the audit log without exposing secrets.
24. An account is not locked or modified merely because an unapproved assisted-recovery request was submitted.
25. An Admin or Agent cannot enter a dashboard until a newly enrolled authenticator is confirmed with a valid code.
26. An abandoned or expired enrolment cannot authenticate the account.
27. A TOTP code cannot be accepted more than once within its validity window.
28. Replacing an accessible authenticator requires the current password and authenticator, and a failed replacement leaves the current authenticator active.
29. Successful authenticator replacement revokes the old authenticator, other sessions, trusted devices, and previous recovery codes.
30. Ten recovery codes are shown once, stored only as hashes, consumed individually, and replaced as a complete set when regenerated.
31. A lost-authenticator flow accepts one valid recovery code, requires confirmation of a replacement authenticator, and issues new recovery codes.
32. A user without a valid authenticator or recovery code cannot bypass MFA and is routed to assisted recovery.
33. Authenticator secrets and QR codes do not appear in logs, analytics, caches, error reports, or third-party resources.
34. Authenticator lifecycle events and excessive invalid-code attempts produce the required notifications and audit events without exposing secrets.
35. Only an Admin with Admin-management permission can create or manage an Admin invitation.
36. Only an authorized Admin can create an Agent invitation, and Customers cannot be assigned to an Agent before that Agent becomes active.
37. Only an Agent can create a Customer, while the assigned Agent or an authorized Admin can manage the resulting invitation.
38. Admin and Agent invitation tokens expire after 24 hours, Customer invitation tokens expire after seven days, and expiration preserves the account and related records.
39. Resending an invitation invalidates all older tokens, restarts expiry, preserves account data, and respects the configured rate limits.
40. Correcting an invited account's email validates uniqueness, invalidates links for the previous email, sends a new invitation, and creates an audit record.
41. Cancelling a Customer invitation prevents login activation without deleting the Customer profile, plans, fees, collections, or financial history.
42. The system prevents duplicate accounts for an existing email and duplicate Customers for an existing normalized phone number.
43. A Customer invitation and activation screen display the same immutable registration-fee snapshot and record the Customer's acknowledgement.
44. A Customer can activate before paying the registration fee, and the outstanding fee remains available to the Fees module without being calculated or processed by authentication.
45. Invitation tokens are single-use, securely stored, bound to the intended account, user type, email, and purpose, and excluded from logs and third-party tooling.
46. An active Customer must confirm the current password and both email links before an email change completes.
47. An active Agent or Admin must confirm the current password, authenticator code, and both email links before an email change completes.
48. The current email remains authoritative and the proposed email remains reserved while a change is pending.
49. An expired, cancelled, replaced, or partially confirmed email-change request cannot change the account email and releases its reservation when appropriate.
50. A successful email change revokes sessions, trusted devices, reset links, invitation links, activation links, and recovery links and requires sign-in with the new email.
51. An email change preserves the password, authenticator, user type, permissions, assignments, balances, transactions, and historical attribution.
52. An Agent or Admin cannot directly overwrite another active user's email, and a user without access to the current email is routed to assisted recovery.
53. Email-change requests are limited to three per account every 24 hours, and each confirmation token expires after 30 minutes and can be used only once.
54. Customer, Agent, and Admin email changes produce the required role-specific notifications and complete audit history without exposing secrets.
55. Customer sessions expire after seven days of inactivity or 30 days in total and permit no more than five concurrent devices.
56. Agent sessions expire after one hour of inactivity or 24 hours in total and permit no more than two concurrent devices.
57. Admin sessions expire after 30 minutes of inactivity or 24 hours in total and permit only one concurrent device.
58. A new Admin login cannot silently replace an active Admin session; the Admin must explicitly revoke the existing device before continuing.
59. A trusted Agent device can satisfy the possession factor for 30 days but does not extend an individual session beyond its limits.
60. Fresh-authentication status expires after 10 minutes, is not extended by ordinary session activity, and is reused only for an action in the coordinated catalogue in Section 8.5.
61. Each eligible Admin or Agent page visit replaces the saved resume destination for that account and browser, and that destination expires after 24 hours.
62. A valid saved destination is restored after login only when it is internal, safe, account-bound, role-appropriate, and currently authorized.
63. An expired, invalid, unauthorized, cross-user, external, or sensitive saved destination redirects the user to their default dashboard.
64. A normal sign-out preserves the resume destination, while a security-driven sign-out, recovery, suspension, deactivation, or confirmed compromise clears it.
65. Admin permission removal and Customer reassignment immediately change active access enforced by the server.
66. An expired session cannot authorize a financial action, and a retry after login cannot create a duplicate transaction.
67. Session and resume cookies use the required security controls and do not expose tokens, account identifiers, sensitive query data, or external redirect targets.
68. Session, device, trusted-device, redirect validation, authorization refresh, and suspicious-token events appear in the audit log without exposing secrets.
69. Password attempts receive the defined one-minute, progressive, 15-minute, and one-hour restrictions at their respective thresholds without revealing account existence or lock state.
70. Five invalid authenticator codes end the current login attempt, and ten within one hour cause a 15-minute MFA cooldown without removing the authenticator.
71. Five invalid recovery codes end the current attempt, and ten within one hour cause a one-hour cooldown without consuming legitimate codes.
72. Password, authenticator, recovery-code, reset, invitation, activation, and assisted-recovery counters and restrictions operate independently.
73. A temporary lock blocks the affected new authentication path but preserves the role, permissions, assignment, account state, records, and legitimate active sessions.
74. Waiting for expiry, completing the applicable recovery flow, or an authorized manual unlock clears only the intended restriction.
75. A password reset clears password-failure restrictions but does not clear authenticator or recovery-code restrictions.
76. Suspended, deactivated, and invited accounts do not become active when a counter resets or a temporary lock expires.
77. Manual unlock requires identity verification and authorization and cannot change passwords, MFA, permissions, assignments, or account status.
78. The account owner receives the required lock, cooldown, unlock, and compromise notifications.
79. Authorized Admins can see lock category, reason, timing, approximate source, attempt summary, and notification result without seeing submitted secrets.
80. Distributed attacks and automated authentication abuse are detected, rate-limited, and audited without relying only on IP-address restrictions.

## 13. Confirmed Product Decisions

- Customer email addresses are unique.
- Customer phone numbers are unique.
- Passwords are the routine sign-in secret for every user type.
- Email is the only activation, notification, and self-service recovery delivery channel for the initial release.
- Authenticator-app MFA is mandatory for Admins and Agents.
- Customers do not require MFA for routine sign-in.
- The seeded first Admin may initially exist without an authenticator. After their password is accepted, they must configure and confirm an authenticator app before entering the Admin dashboard.
- Any Admin or Agent account without completed authenticator setup is routed to mandatory MFA enrolment after password verification and cannot access its dashboard until setup is complete.
- Customer assisted recovery is initiated by the assigned Agent and approved by an authorized Admin.
- 8 October 2026: Admin invitations, Admin permission changes, staff assisted recovery, business-setting publication and cancellation, financial periods and Admin fee actions no longer repeat fresh authentication (Section 8.5).
- Agent assisted recovery is controlled by an authorized Admin.
- Admin assisted recovery requires two authorized Admin approvals when two eligible approvers are available.
- A recovered Admin is restricted from Admin-management and permission changes for 24 hours.
- The final Admin receives a single-use offline emergency recovery key during initial setup.
- Version 2 uses one active, six-digit, 30-second TOTP authenticator per Admin or Agent account.
- Authenticator replacement is self-service when the user can verify their password and current authenticator.
- Each Admin or Agent receives ten single-use recovery codes.
- A user who loses an authenticator must use a recovery code to replace it or proceed through assisted recovery.
- Admin and Agent invitations expire after 24 hours; Customer invitations expire after seven days.
- Invitation resends invalidate earlier links and are limited to one per minute and five per day per account or request source.
- Email addresses are unique across all user accounts, and Customer phone numbers are unique across Customers.
- Customer invitations include an immutable snapshot of the applicable registration fee.
- Customers acknowledge the registration fee during activation, but fee payment does not block account activation.
- Registration-fee calculation, collection, accounting, waiver, refund, reversal, and reconciliation remain responsibilities of the Fees module.
- Active-account email changes require fresh authentication and separate confirmation through both the current and proposed email addresses.
- The current email remains authoritative until both confirmations succeed, and successful completion revokes sessions, trusted devices, and outstanding access or recovery links.
- Active users who cannot access their current email must use assisted recovery rather than an administrative overwrite.
- Agent and Admin sessions have a maximum lifetime of 24 hours.
- Admins may have only one concurrent device session; Agents may have two and Customers may have five.
- Agent trusted-device authorization lasts 30 days but does not extend an individual session beyond its defined limits.
- Admin and Agent page visits update a secure last-page resume cookie that expires after 24 hours.
- After login, an invalid or expired saved page falls back to the role's default dashboard.
- Authentication failures use separate counters and progressive temporary restrictions rather than permanent account lockout.
- Ten password failures within one hour cause a 15-minute login lock; twenty within 24 hours cause a one-hour lock and security review flag.
- Five invalid authenticator or recovery-code attempts end the current attempt; repeated failures trigger the defined temporary cooldowns.
- Authorized Admins may manually unlock accounts after identity verification without changing credentials, permissions, assignments, or account status.

## 14. Security References

- [NIST SP 800-63B — Authentication and Authenticator Management](https://pages.nist.gov/800-63-4/sp800-63b.html)
- [RFC 6238 — TOTP: Time-Based One-Time Password Algorithm](https://www.rfc-editor.org/info/rfc6238/)
- [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
- [OWASP Multifactor Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html)
