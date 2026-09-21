# Customer and Agent Management — Detailed Requirements

**Product version:** 2.0  
**Module status:** Confirmed baseline  
**Source:** [Version 1 PRD](../../PRD.md) and the Version 2 identity and authorization modules  
**Depends on:** [User Types and Access Model](./01-user-types.md), [Authentication and Account Access](./02-authentication.md), [Roles, Permissions, and Authorization](./03-roles-and-permissions.md)

## 1. Purpose and Intended Scope

This module defines how Customer and Agent records are created, maintained, assigned, suspended, reassigned, and eventually archived or deactivated.

Thrift plans, collections, withdrawals, and reporting depend on knowing:

- Which Customers exist.
- Which Agents are active.
- Which Agent currently owns each Customer relationship.
- Whether those records are operationally eligible for new activity.

This module also covers the PRD's next P0 requirements after authentication: saver registration and saver profiles. Version 2 uses **Customer** in place of saver and **Agent** in place of collector.

This file contains a detailed draft developed from the original module breakdown. Existing decisions from Modules 01–03 remain binding. Newly proposed product decisions remain identified for review; documentation completion does not imply implementation, test completion, or final product approval. Section 17 defines the proposed initial scope, indexed functional requirements, and release acceptance criteria.

## 2. Customer Profile

### 2.1 Specification status

The profile-field specification below is a **draft for review**. Email uniqueness across all accounts and phone uniqueness across Customers are established requirements from Authentication. New field limits, ID formats, optional-field structures, and Agent phone rules are proposals until confirmed.

Field validation does not grant permission to edit a field. Section 4 defines the draft field-level authority and sensitive-change workflows.

### 2.2 Customer fields

| Field                     | Required?        | Validation and ownership                                                                                                                                                                                                                                          |
| ------------------------- | ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Full name                 | Yes              | One display-name field; 1–150 characters after trimming. Support Unicode names, single-word names, spaces, apostrophes, and hyphens. Do not require separate first and last names or an ASCII-only format.                                                        |
| Email address             | Yes              | Valid email syntax; maximum 254 characters. Normalize and compare using Section 2.3. Unique across Customer, Agent, and Admin accounts, including inactive accounts. Verification belongs to Authentication.                                                      |
| Phone number              | Yes              | Parse and store in international format using Section 2.3. Unique across all Customer profiles, including inactive and archived Customers.                                                                                                                        |
| Customer ID               | System-generated | Immutable public reference using the proposed format in Section 2.4. Never entered by the registering Agent.                                                                                                                                                      |
| Assigned Agent            | System-managed   | Reference an existing Agent. Initial assignment is the creating Agent; later changes use the reassignment workflow. Assignment eligibility belongs to the lifecycle rules.                                                                                        |
| Registration date         | System-generated | Server timestamp when the Customer record is created; stored in UTC and displayed in the configured business timezone. Cannot be backdated through a profile form.                                                                                                |
| Operational status        | System-managed   | Proposed default: Active. Uses the status catalogue and dedicated transitions in Section 5. Separate from account and invitation states.                                                                                                                          |
| Address                   | No               | Free-text address, maximum 500 characters; retain meaningful line breaks. Do not require a postal code.                                                                                                                                                           |
| Gender                    | No               | Proposed choices: Female, Male, Other, Prefer not to say. Omission is allowed and is distinct from an explicit choice.                                                                                                                                            |
| Occupation                | No               | Free text, maximum 100 characters.                                                                                                                                                                                                                                |
| Profile photo             | No               | Optional image upload using Section 2.6. No photograph is required to register or activate.                                                                                                                                                                       |
| Next of kin               | No               | One optional structured contact using Section 2.5.                                                                                                                                                                                                                |
| Notes                     | No               | Plain text, maximum 2,000 characters; retain meaningful line breaks. Internal operational notes; visibility and edit authority follow Section 4.                                                                                                                  |
| Internal reference number | No               | Business-entered reference, 1–50 characters when supplied. Proposed allowed characters: letters, digits, hyphens, underscores, and slashes. Unique across Customer profiles after trimming and case-insensitive comparison. Separate from the system Customer ID. |
| Created by / updated by   | System-generated | Actor references captured from the authenticated operation. Never accepted from ordinary profile-form input.                                                                                                                                                      |
| Last updated timestamp    | System-generated | Server timestamp for the most recent committed profile change.                                                                                                                                                                                                    |

Balances, contributions, withdrawals, fees, deductions, and plan summaries are read-only values supplied by their owning modules. They are not editable profile fields.

### 2.3 Shared validation and normalization

These rules apply to Customer and Agent profiles unless a field explicitly specifies otherwise:

- Trim leading and trailing whitespace from text fields. Treat an empty optional text field as absent; reject an empty required field. Validate lengths after normalization and reject overlong input rather than silently truncating it.
- Preserve the entered spelling and capitalization of names and descriptive text. Reject control characters except permitted line breaks in multiline fields. Treat descriptive text as plain text, not executable markup.
- Use Authentication's shared email-normalization rule across registration, sign-in, invitations, profile changes, and email reservations: trim surrounding whitespace, validate, and compare the complete address case-insensitively without removing dots, rewriting plus-address suffixes, or applying provider-specific alias rules. Preserve accepted display spelling separately from the normalized comparison value.
- Parse phone numbers using a selected country and normalize to international format before comparison. Proposed default country: Nigeria (+234); support another country when explicitly selected. Spaces, parentheses, and hyphens may be accepted in input. Reject numbers that cannot be parsed as valid numbers for the selected country; do not validate every country with one fixed digit count.
- A phone number passing format validation is not proof of ownership. This section does not introduce SMS verification or phone sign-in; sensitive phone-change requirements remain with Section 4 and Authentication.
- Enforce required fields, formats, length limits, and uniqueness on the server. Client-side validation provides immediate feedback but cannot replace these checks.
- Enforce uniqueness at commit time so simultaneous submissions cannot create duplicates. Updates exclude the record being edited from duplicate comparison. Suspended, deactivated, inactive, and archived records continue to reserve their unique identifiers.
- An email already belonging to an account follows Authentication's duplicate-account handling. Do not create a second profile or silently convert the existing role. A duplicate warning must not disclose another Customer's details to an Agent without access to that Customer.
- A validation failure must leave the attempted profile creation or update uncommitted. Show a field-specific error, preserve permitted non-secret input, and require correction before retrying.

### 2.4 Customer and Agent IDs

Proposed public-reference formats:

- Customer: `CUS-000001`.
- Agent: `AGT-000001`.

Each reference is generated by the server, unique within its entity type, immutable, and never reused after archival or deactivation. Numeric sequences use at least six digits and expand beyond six digits when necessary. Gaps are acceptable; references do not promise a continuous count.

Public references identify records for search and support. They do not authorize access; every lookup still applies the current role and assignment scope.

### 2.5 Next-of-kin contact

Proposed structure for the single optional Customer next-of-kin contact:

| Field        | Required when a contact is supplied? | Validation                                                                                                                         |
| ------------ | ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| Full name    | Yes                                  | Same name rules as the Customer full name.                                                                                         |
| Relationship | Yes                                  | Free text, 1–50 characters; for example, spouse, sibling, parent, or friend.                                                       |
| Phone number | Yes                                  | Same international-format validation as other phone fields; not unique. A shared family contact may be used by multiple Customers. |
| Address      | No                                   | Maximum 500 characters.                                                                                                            |

If every contact field is empty, save no contact. If any contact field is supplied, validate all required contact fields together. A next-of-kin contact is not a login account and does not receive access, withdrawal authority, or ownership of the Customer's savings through this record.

### 2.6 Profile-photo validation

Proposed limits for Customer and Agent profile photos:

- Accept JPEG, PNG, and WebP images, with a maximum uploaded size of 5 MB.
- Validate actual file contents and successful image decoding rather than trusting the filename or declared content type. Reject corrupt images and unsupported formats.
- Accept dimensions from 100 × 100 to 4,096 × 4,096 pixels. Explain the size or dimension limit when rejecting an upload.
- Process accepted images to remove embedded metadata and produce a display image while preserving aspect ratio. A square crop may be offered but is not required.
- Apply profile-access rules to stored photos; possession of a photo reference must not bypass authorization.
- A rejected photo must not overwrite an existing photo. The user may remove the failed upload and save the other valid profile fields without a photo.

### 2.7 Profile-validation acceptance criteria

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. A Customer can be registered with only their name, email, and phone entered by the Agent; IDs, attribution, registration time, and assignment are supplied by the system.
2. Names containing non-ASCII characters, apostrophes, hyphens, or a single word are accepted within the length limit.
3. Equivalent normalized emails cannot create separate accounts; dots and plus suffixes are not silently removed.
4. Equivalent local and international representations of the same Customer phone number are rejected as duplicates.
5. Existing inactive or archived Customers retain their unique phone and internal references, and deactivated accounts retain their email identities.
6. Two concurrent submissions for the same unique value produce at most one successful creation; the other receives a safe validation error.
7. Optional fields can be omitted; a partially supplied next-of-kin contact receives errors for its missing required fields.
8. Invalid or oversized photos are rejected without replacing an existing photo, and a profile can be saved without a photo.
9. Profile forms cannot supply or overwrite system-generated IDs, actor references, timestamps, assignments, or financial totals.
10. An Agent cannot discover another Agent's Customer identity through a duplicate-field error.

## 3. Customer Creation

### 3.1 Specification status and ownership

This creation, failure, and retry specification is a **draft for review**. Agent-only Customer creation, initial self-assignment, uniqueness, Customer activation independent of fee payment, invitation security, and no partial denied mutations are established requirements from Modules 01–03. The expanded atomic-registration boundary, creation-attempt tracking, fee-version checks, and delivery-retry policy below are proposed details.

Only an eligible Agent under Section 9 may create a Customer; an Admin with `customers.manage` may manage an existing Customer but cannot create one. Authentication owns account/invitation mechanics and delivery. Fees owns registration-fee applicability, the resulting fee obligation, and accounting. Agent Registration in Section 8 uses the shared failure/retry rules here without Customer assignment or registration-fee records.

### 3.2 Customer creation workflow

1. The eligible Agent enters the Customer's required and optional fields. Validate them under Section 2 and show field-specific errors without creating a profile.
2. Obtain the applicable registration-fee amount, currency, configuration reference/version, and any eligibility result from Fees. Display the applicable fee in the registration preview; this does not replace the Customer's later acknowledgement during activation.
3. Create a unique creation-attempt reference for this confirmed submission. Duplicate clicks, transport retries, and an uncertain response reuse that reference and the same normalized submission.
4. At commit, re-check the Agent's account/operational eligibility, field validation, email/phone/reference uniqueness, and the fee configuration used in the preview.
5. Commit the core records in Section 3.3 together, using server-generated IDs, attribution, and timestamps. The Customer is operationally Active, the account Invited, and the invitation Pending delivery.
6. Only after commit may Authentication dispatch the durable queued invitation. Return **Customer registered** with the Customer reference and separate account/invitation states, rather than implying the email was delivered or the Customer activated.
7. The assigned Agent may perform permitted operations while the account remains Invited, subject to Customer and financial-module rules. The Agent never creates or learns the Customer's permanent password.

### 3.3 Atomic-registration boundary

The following records form one committed Customer registration:

- Customer profile and unique public ID, linked to one Invited Customer authentication account.
- Initial effective assignment to the creating Agent and its assignment history/version.
- Initial operational-status history and original creation attribution.
- Authentication-owned invitation metadata and immutable registration-fee snapshot.
- The Fees-owned registration-fee obligation/applicability record, linked to the same snapshot. Fees decides whether an amount is due; a configured zero fee must be explicitly valid rather than inferred from a service failure. This linkage does not recognize revenue or collect payment through Authentication.
- Durable invitation-delivery work, the successful creation-attempt binding, and the material creation audit event.

Either the whole boundary commits or none of its business records do. A transactional or equivalent coordinated implementation may be used, but an intermediate account, assignment, fee obligation, or profile must not become independently usable. A failed attempt may retain its diagnostic/security audit event and attempt reference; it must not leave a partial business record or runnable invitation job. Public-ID sequence gaps are acceptable.

Agent registration commits its Agent profile, one Invited Agent account, Inactive operational-status history, invitation metadata/delivery work, creation-attempt binding, and creation audit event together. It creates no Customer assignment or Customer registration-fee obligation. MFA is configured later by the Agent through Authentication.

Actual email dispatch is outside the atomic boundary and must never run before commit. An invitation job surviving a server restart must still reference a committed account and the currently valid invitation generation before sending.

### 3.4 Failure outcomes

| Failure                                                                                                                                    | Required outcome and next step                                                                                                                                                                                                                               |
| ------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Missing/invalid fields or rejected photo                                                                                                   | No core registration commits. Preserve permitted non-secret input and show specific errors. The user may correct the input or explicitly remove the failed optional photo before a new confirmed attempt; do not silently omit supplied invalid information. |
| Duplicate normalized email, Customer phone, or optional internal reference                                                                 | No new account, profile, assignment, fee record, or delivery job. Use the safe duplicate handling in Section 3.6 rather than automatically merging records.                                                                                                  |
| Creating Agent becomes Inactive, locked/ineligible for initial assignment, Suspended, Deactivated, or otherwise unauthorized before commit | Fail without creating business records or sending an invitation. Require current eligible authority before any fresh submission.                                                                                                                             |
| Admin loses `agents.manage` or account access during Agent registration                                                                    | Fail without creating the Agent or delivery job.                                                                                                                                                                                                             |
| Registration-fee rules missing, invalid, or unavailable                                                                                    | Block Customer creation. Do not assume a zero amount, omit the snapshot, create a fee-less profile, or send an invitation with a placeholder. Retry only after valid authoritative rules are available.                                                      |
| Fee configuration changes after preview                                                                                                    | Commit nothing; show that the applicable fee changed and require review and a new confirmed attempt. Do not charge or present a fee the Agent's confirmation no longer describes.                                                                            |
| Core persistence, coordinated fee/account creation, or required audit/delivery-work persistence fails                                      | Abort the core boundary. No invitation is dispatched. Show a retryable error only when non-commit is known; otherwise treat the outcome as uncertain below.                                                                                                  |
| Timeout, lost response, interrupted browser, or server restart with commit outcome unknown                                                 | Show **Registration outcome not yet confirmed**. Resolve the existing attempt or retry it with the same reference; do not submit a new attempt until the previous outcome is known.                                                                          |
| Invitation provider rejects delivery after core commit                                                                                     | Keep the committed registration and fee snapshot. Authentication records Delivery failed; show **Registered — invitation delivery failed** and authorized resend/correction actions. Do not delete the profile or undo its fee obligation.                   |
| Invitation provider times out and dispatch outcome is unknown                                                                              | Registration remains successful. Track the uncertain delivery attempt without claiming delivery or a creation failure; keep invitation status Pending delivery until Authentication can reconcile it or an authorized resend replaces it.                    |
| Other operational notification fails                                                                                                       | Preserve registration. Track delivery separately; a notification retry must not recreate the account or profile.                                                                                                                                             |

Optional photo processing must finish successfully before it becomes part of a confirmed core registration. An unattached temporary upload is not an operational Customer/Agent record and must be cleaned up under the upload policy after a definitively failed or abandoned attempt. A valid temporary upload can be reused for the same attempt while resolving an uncertain outcome.

### 3.5 Creation-attempt tracking and retries

- Bind each creation-attempt reference to the authenticated initiating actor, configured business, Customer/Agent operation type, and normalized submission fingerprint. Do not allow another actor or operation to reuse it. The reference is not a sign-in credential or proof of record access.
- Two concurrent submissions with the same reference produce at most one core registration. The other receives the same authorized committed result or an in-progress response; it cannot start a second creation while the first outcome is unknown.
- Reusing a reference with changed input is a conflict, not an edit to an existing record. After a definitive failure, correcting fields or reviewing a changed fee requires a new confirmed attempt. Ordinary profile changes after successful creation use their own authorized edit workflow.
- A retry after successful commit returns the existing creation result under current account and record-read authorization. It does not refresh invitation expiry, generate a new token, assess another fee, alter assignment/status, or send another email. Delivery state may have progressed and is reported separately as its current state.
- If the Customer has since been reassigned, the original Agent cannot retrieve their profile through the old attempt. Deny the read without leaking Customer details or creating a replacement record. An Inactive Agent who still has permitted read access may resolve their already committed result; this does not permit new creation.
- The UI retains the attempt reference while resolving an uncertain submission, including after reload, without persisting credentials or unnecessary profile data in browser storage. **Check registration** looks up that actor's attempt and current authorized result. A server-authoritative definitive non-commit permits a fresh confirmed submission; absence from an early or stale read does not prove failure.
- Keep successful attempt-to-record bindings durable for the record's lifetime so delayed duplicate transport submissions cannot recreate it. Retention of diagnostic attempt details follows the eventual audit/privacy policy. An in-progress or uncertain attempt cannot expire into permission to create a duplicate.
- Separate attempts and actors still face server uniqueness constraints at commit. Email/phone uniqueness is not a substitute for attempt tracking, and an email match alone is not permission to return or modify an existing record.

### 3.6 Duplicate identity and resumed work

Apply Authentication's duplicate-account rules and Section 2's privacy requirements:

- For an existing Invited account, offer resend, invited-email correction, or cancellation only to its authorized invitation manager. Do not create another profile or copy the original fee snapshot into a second obligation.
- For an existing Active account, prevent creation and offer **Open existing record** only when the actor currently has access to it.
- For Suspended or Deactivated accounts, route authorized users to the applicable access-management workflow; registration cannot restore account access.
- For an archived Customer, use Section 6 restoration through an authorized Admin. Do not register the returning person as a new Customer or free their identity values.
- If the email belongs to another role, block creation without role conversion. If an entered email and phone belong to different existing Customers, reject the attempted identity combination; do not merge or reassign either record.
- If the matching record is outside the Agent's scope, show a generic unavailable-identity message without the existing person's name, Agent, status, balances, or record link. Admin assistance must use the existing management/reassignment authority rather than claim that a new creation succeeded.

### 3.7 Invitation delivery retries and user recovery

Creation-attempt retry and invitation resend are different operations. The former resolves one registration; the latter is an Authentication action on an existing account.

- Proposed automatic policy: one initial dispatch and at most two retries within 15 minutes for transient failures of the same issued invitation-delivery event. Use provider duplicate-prevention support when available; do not promise exactly one email when provider acceptance is uncertain.
- Automatic retries retain the same committed account, fee snapshot, invitation generation, and issue/expiry times. They do not create a new activation challenge, extend invitation lifetime, or become fresh Customer/Agent registrations. Repeated email dispatch, if unavoidable, contains the same currently valid challenge rather than separate valid invitations.
- Definite non-retryable delivery errors or confirmed retry exhaustion record Delivery failed. If provider acceptance remains genuinely unknown, display **Delivery could not be confirmed** as delivery-attempt information and keep the appropriate unresolved Pending delivery state rather than asserting the email never arrived.
- Before dispatch or retry, Authentication checks current invitation validity and account state. Suppress expired/cancelled/replaced generations and invitations made unusable by suspension, deactivation, or completed activation. Reassignment alone preserves the valid challenge and uses the current Agent presentation under Section 12.
- An authorized explicit resend follows Authentication Section 3.9: only the permitted pending, delivery-failed, or expired invitation states, one resend per minute and five per day per account or request source, a new challenge invalidating all previous challenges, and restarted expiry. The fee snapshot and core registration remain unchanged.
- An authorized invited-email correction follows Authentication Section 3.10. It invalidates prior links and issues a new invitation for the same account; it is not a corrected create request or a second fee assessment.
- Management screens show separate creation, account, and invitation outcomes with latest issue time and safe delivery diagnostics. Never expose activation tokens, passwords, or provider secrets. Keep the existing record available while staff resolve email delivery; Customer activation still waits for Authentication completion, and Agent operational readiness still waits for Section 9 approval.

### 3.8 Creation-failure and retry acceptance criteria

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. Successful Customer creation commits one profile, account, assignment, valid fee snapshot/applicability record, status history, audit event, and durable invitation job; Agent creation commits its corresponding fee-free boundary.
2. A pre-commit failure at any required boundary leaves no partial usable business record and dispatches no email, while preserving safe failure diagnostics.
3. Missing fee rules fail closed, an explicitly configured zero fee is accepted, and a changed preview fee requires reconfirmation before creation.
4. Concurrent duplicate clicks and a lost successful response resolve through the same creation attempt without duplicate records, fees, assignments, tokens, or creation-triggered emails.
5. Changed payloads conflict with the existing attempt; uncertain outcomes do not enable a new creation before authoritative resolution.
6. Separate concurrent attempts with equivalent unique identity values permit at most one registration and reveal no out-of-scope identity details.
7. Permission, Agent readiness, and account changes during creation are enforced at commit without partial records or queued privileged work.
8. Post-commit invitation failure preserves the registration and fee snapshot, and an uncertain delivery response is not presented as failed registration.
9. Automatic delivery retries reuse the existing challenge and expiry; explicit authorized resends rotate challenges and obey Authentication limits without recreating profiles or fees.
10. Invited-email correction and archived/account reactivation use their dedicated workflows rather than duplicate registration or role conversion.
11. Successful replay checks current read scope; reassignment prevents the original Agent from reading the Customer through a prior attempt reference.
12. Optional upload failure is explicit, abandoned unattached uploads are cleaned up, and delivery diagnostics contain no authentication secrets.

## 4. Customer Profile Management

### 4.1 Specification status and scope

This editing specification is a **draft for review**. Existing role prohibitions, assignment scope, email-change safeguards, and Customer fresh-password requirements remain binding. The proposed division of editable fields, name-confirmation workflow, and Agent self-service profile rules require confirmation before implementation.

The matrices below govern edits to existing records. Initial entry during Customer or Agent registration follows Sections 2, 3, 7, and 8. An edit requires a valid active account, current resource access, the required permission where applicable, and eligibility under the record's operational-status rules. A field marked editable is not an exemption from those checks.

An **assigned Agent** means the Customer's current effective Agent, not an Agent who previously registered or collected from the Customer. An **authorized Customer Admin** is an active Admin with `customers.manage`; an **authorized Agent Admin** has `agents.manage`. Admin baseline read access does not confer edit authority.

### 4.2 Customer field-editing matrix

| Field                                                         | Customer themselves                                                            | Assigned Agent                                                                                                           | Authorized Customer Admin                                                                               |
| ------------------------------------------------------------- | ------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------- |
| Full name                                                     | Change with fresh password and a required reason                               | Correct directly before activation with a reason; after activation, propose a correction requiring Customer confirmation | Same correction rules as assigned Agent                                                                 |
| Email address                                                 | Authentication's secure email-change workflow after activation                 | Pre-activation invitation correction only; cannot directly overwrite after activation                                    | Pre-activation invitation correction only; cannot directly overwrite after activation                   |
| Phone number                                                  | Dedicated phone-change workflow with fresh password after activation           | Correct before activation with a reason; after activation, direct the Customer to self-service                           | Same correction rules as assigned Agent; no direct post-activation overwrite                            |
| Address                                                       | Edit or clear                                                                  | Edit or clear                                                                                                            | Edit or clear                                                                                           |
| Gender                                                        | Edit or clear                                                                  | Edit or clear                                                                                                            | Edit or clear                                                                                           |
| Occupation                                                    | Edit or clear                                                                  | Edit or clear                                                                                                            | Edit or clear                                                                                           |
| Profile photo                                                 | Add, replace, or remove                                                        | Add, replace, or remove                                                                                                  | Add, replace, or remove                                                                                 |
| Next of kin                                                   | Add, edit, or remove the complete contact                                      | Add, edit, or remove the complete contact                                                                                | Add, edit, or remove the complete contact                                                               |
| Internal notes                                                | Hidden; cannot edit                                                            | View, edit, or clear                                                                                                     | View, edit, or clear                                                                                    |
| Internal reference number                                     | View only                                                                      | Set, correct, or clear with a reason                                                                                     | Set, correct, or clear with a reason                                                                    |
| Customer ID, registration date, created-by attribution        | View only                                                                      | View only                                                                                                                | View only                                                                                               |
| Updated-by attribution and last updated timestamp             | View only                                                                      | View only                                                                                                                | View only                                                                                               |
| Assigned Agent                                                | View only                                                                      | View only                                                                                                                | Separate reassignment workflow requiring `customers.reassign`, not `customers.manage`                   |
| Customer operational status                                   | View only                                                                      | View only under this proposal                                                                                            | Separate status-transition workflow requiring `customers.manage`; prerequisites follow Sections 5 and 6 |
| Account state, invitation state, role, security settings      | Own permitted security settings through Authentication; other states read-only | Only the invitation and recovery actions explicitly granted by Authentication                                            | Only the actions granted by Authentication and the relevant Admin permission                            |
| Balances, plans, financial history, registration-fee snapshot | View permitted values only                                                     | Use the owning module's authorized workflow                                                                              | Use the owning module's authorized workflow; no financial edit through a profile form                   |

Routine edits to address, gender, occupation, photo, and next of kin take effect immediately after validation; they do not introduce an approval queue or require fresh authentication beyond the valid session. Staff must make those changes at the Customer's request or to correct recorded information. The Customer receives an operational notification after a staff change.

Optional fields may be cleared by an authorized editor. Required name, email, and phone fields may not be cleared. Removing a next-of-kin contact is an explicit action; an incomplete contact is not treated as a removal.

### 4.3 Name correction and confirmation

- Before activation, the assigned Agent or an authorized Customer Admin may correct the Customer's full name with a required reason. The correction does not alter the Customer ID, registration date, assignment, invitation fee snapshot, or financial history. Authentication must use the current profile name when presenting identity details.
- After activation, a Customer may change their own full name after fresh password authentication and providing a reason. Notify the current assigned Agent and record the change in the audit history.
- A staff-proposed post-activation correction records the current name, proposed name, requesting actor, reason, issue time, and profile version. The effective name remains unchanged until the Customer confirms in their authenticated account with fresh password authentication.
- Proposed initial scope: one pending name correction per Customer, expiring after seven days. The Customer may accept or reject it; the requesting actor may cancel it only while still authorized to manage that Customer. A replacement proposal cancels the previous one. Notifications do not themselves authorize a name change.
- At acceptance, re-check the Customer's access, the requesting actor's current authority and assignment, the stored profile version, and field validation. A staff suspension, permission loss, Customer reassignment away from the requesting Agent, or intervening name change invalidates the proposal. Stale acceptance cannot overwrite a newer name.
- A Customer self-service name change cancels any pending staff proposal. Rejected, expired, cancelled, or invalidated proposals leave the name unchanged and are auditable.
- A Customer unable to authenticate must complete the applicable Authentication recovery or reactivation workflow before confirming a post-activation name change. This module does not provide a staff bypass.

### 4.4 Email and phone changes

**Email:** Apply [Authentication Section 3.10](./02-authentication.md#310-correcting-an-email-before-activation) for invited-account corrections and [Section 4.4](./02-authentication.md#44-email-address-changes-for-active-accounts) after activation. An ordinary profile save must never update the active account email. If the user cannot access the current email, use the existing assisted-recovery workflow and its security permissions.

**Customer phone before activation:** An assigned Agent or authorized Customer Admin may correct the phone with a required reason. Normalize it, enforce Customer-wide uniqueness at commit, and audit the change. No Customer password or SMS verification is required while the account remains invited. Re-check the account state at commit; an account that has activated must use the post-activation workflow.

**Customer phone after activation:**

1. The Customer opens a dedicated phone-change action, enters the new number and country, and completes fresh password authentication using Authentication's freshness window.
2. The system validates and normalizes the proposed number and shows the current and proposed numbers for confirmation.
3. Confirmation atomically replaces the phone after checking current account access, profile version, and Customer-wide uniqueness again.
4. Notify the Customer through their verified email and the current assigned Agent through operational notifications. Audit the actor, previous and new value references, time, and result; never record the password.

No SMS challenge is introduced in this proposal. The number remains contact data rather than a verified sign-in or recovery factor. A phone change does not change email, credentials, sessions, assignment, or finances, and does not require an invitation resend. Staff cannot use a normal profile form or a generic recovery request to bypass this rule.

### 4.5 Agent field-editing matrix

| Field                                                                 | Agent themselves                                                                          | Authorized Agent Admin                                                                                                                   |
| --------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Full name                                                             | View only; request a correction from an authorized Admin                                  | Correct with a required reason; notify the Agent                                                                                         |
| Email address                                                         | Authentication's secure email-change workflow after activation                            | Pre-activation invitation correction only; no direct post-activation overwrite                                                           |
| Phone number                                                          | Dedicated self-service change after activation with fresh password and authenticator code | Correct before activation with a required reason; no direct post-activation overwrite                                                    |
| Address                                                               | Edit or clear                                                                             | Edit or clear                                                                                                                            |
| Profile photo                                                         | Add, replace, or remove                                                                   | Add, replace, or remove                                                                                                                  |
| Employment or engagement date                                         | View only                                                                                 | Set, correct, or clear with a required reason                                                                                            |
| Internal notes                                                        | Hidden; cannot edit                                                                       | View, edit, or clear                                                                                                                     |
| Agent ID, created-by attribution, created timestamp                   | View only                                                                                 | View only                                                                                                                                |
| Updated-by attribution and last updated timestamp                     | View only                                                                                 | View only                                                                                                                                |
| Operational status                                                    | View only                                                                                 | Separate Active/Inactive transition requiring `agents.manage` under Section 9; suspension and offboarding follow Section 10              |
| Account state, role, MFA, recovery, and sessions                      | Own permitted security settings through Authentication; other states read-only            | Only the actions granted by Authentication and the relevant Admin permission; `agents.manage` does not grant assisted-recovery authority |
| Assigned-Customer counts, collection totals, reconciliation summaries | Read permitted derived values only                                                        | Use owning modules; no overwrite through the Agent profile                                                                               |

An Agent cannot edit another Agent's profile. Agent self-service address and photo edits are proposed additions for their own profile only. An Admin without `agents.manage` may view the Agent record under baseline Admin access but cannot update it.

The proposed Agent phone-change workflow follows the Customer sequence in Section 4.4, using Agent-wide uniqueness under Section 7, fresh password plus authenticator verification, and a completion notification to the Agent's verified email and active Admins with `security.operations.manage`. A trusted-device session alone does not satisfy this proposed step-up requirement. No SMS verification or new recovery factor is introduced.

### 4.6 Visibility and protected fields

- Customer internal notes are visible to the current assigned Agent and active Admins under their authorized record access. Agent internal notes are visible only to active Admins. Neither appears in Customer or Agent self-service responses, notifications, or statements. Audit-log visibility continues to require `audit.view`.
- Staff edits to notes require a reason; replacing or clearing notes preserves the previous value in appropriately protected audit history. Do not store passwords, authenticator secrets, recovery codes, or payment credentials in profile notes.
- Public references, creation attribution, registration or creation timestamps, roles, assignments, and financial values cannot be changed through general profile updates. System-managed update metadata is generated only for committed changes.
- A permitted profile correction changes current profile data without rewriting historical transaction attribution, financial postings, or previously issued statements. Historical records follow their owning modules' snapshot rules.
- Archived Customer profiles are read-only under the proposed status model. Restore eligibility through the eventual archival/restoration workflow before editing operational profile fields. Authentication security operations remain governed independently by account-access rules.
- Suspended and deactivated users cannot use self-service edits. Staff authority to edit operational information on such records remains subject to the lifecycle matrix; it never grants permission to overwrite an activated account's email or phone.

### 4.7 Save, concurrency, and notification rules

- Use explicit server-side field allowlists for each actor and workflow. Reject an update containing protected or unauthorized fields as a whole; do not silently discard them while saving the rest.
- Save permitted ordinary profile edits atomically. Dedicated email, phone, name-confirmation, reassignment, and status-transition actions are separate operations with their own prerequisites.
- Re-check account access, Admin permission, effective assignment, operational eligibility, validation, and the profile version at commit. A stale edit must receive a conflict response and reload current values before retrying; it cannot silently overwrite another editor's changes.
- Denial or validation failure creates no partial update, pending proposal, financial effect, or successful-change notification. Record security-relevant denials under the audit rules.
- An unchanged normalized submission is a no-op: do not advance update metadata or issue a change notification. Do not require a reason or new confirmation for an unchanged field.
- Customer changes notify the assigned Agent except where Authentication owns a more specific notification rule. Staff changes to Customer personal fields notify the Customer after activation; pre-activation corrections are visible in the invitation/profile workflow. Changes confined to internal notes or references do not expose staff-only contents to the Customer.
- Admin changes to Agent-visible fields notify the Agent through the applicable verified-email or operational channel. Changes confined to Agent internal notes do not notify the Agent with those contents.
- Notifications contain only information permitted for the recipient. Queue delivery after the change commits; a delivery failure does not roll back the profile or invite repeated changes. Authentication continues to own security-notification delivery and content.

### 4.8 Field-editing acceptance criteria

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. A Customer can edit their own permitted ordinary fields, clear optional values, and cannot access internal notes or edit a different Customer.
2. An assigned Agent can edit permitted fields only while the assignment remains effective; historical creation attribution grants no continuing access.
3. Admin read access without `customers.manage` or `agents.manage` cannot save the corresponding profile edits.
4. A post-activation staff name proposal leaves the effective name unchanged until Customer confirmation; rejection, expiry, reassignment of the requesting Agent, or a newer name change prevents posting.
5. Customer post-activation phone changes require fresh password authentication; Agent phone changes require the proposed password and authenticator step-up. Duplicates leave the previous number unchanged.
6. Invited-account name, email, and phone corrections follow their dedicated rules; concurrent activation prevents use of a stale pre-activation correction form.
7. Active-account email changes use Authentication's existing secure flow, and neither Customer nor Agent management permissions allow direct overwrites.
8. Agent self-service cannot change their name, engagement date, internal notes, role, status, or another Agent's profile.
9. A request mixing allowed address edits with an unauthorized ID, assignment, or financial-field edit fails without saving either change.
10. Concurrent permitted saves cannot overwrite a newer profile version, and permission, account-state, or assignment changes are enforced at commit.
11. A committed edit records appropriate attribution and audit history; notification failures do not reverse the edit or expose private note contents.
12. Profile editing does not recalculate balances, rewrite financial transactions, or grant next-of-kin access.

## 5. Customer Operational Statuses

### 5.1 Specification status and meaning

This status specification is a **draft for review**. The separation of operational status from authentication state and the prohibition on deleting financial history are already established. The four-status catalogue, activity matrix, transition rules, and financial-hold behaviour below are proposed product decisions.

| Operational status | Meaning                                                                                                                                |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| Active             | Participating in normal thrift activity; eligible for new plans and collections subject to the owning modules' rules.                  |
| Inactive           | Participation is paused. No new plans or collections, but existing savings may be settled and permitted records maintained.            |
| Restricted         | An explicit business hold blocks new savings activity and payout. Records remain visible, and authorized corrective work may continue. |
| Archived           | The relationship has ended after obligations are settled. Operational and financial records remain read-only.                          |

Proposed default: a newly created Customer is **operationally Active** while their authentication account is **Invited**. The assigned active Agent may perform permitted operations without waiting for Customer login activation. No balance or fee-payment prerequisite is introduced by the Active default; the owning financial modules define their applicable gates.

Operational status must be displayed separately from account state and invitation progress. For example, **Customer status: Active / Account: Invited** and **Customer status: Restricted / Account: Active** are valid combinations. A Customer must never be permanently deleted after financial activity exists.

### 5.2 Authority and account-access independence

- Only an active Admin with `customers.manage` may change Customer operational status. Customers and Agents may ask an authorized Admin to review the status but cannot perform the transition themselves. This proposal does not introduce a separate status-request approval queue.
- Operational status changes do not activate, suspend, deactivate, unlock, or recover the authentication account; revoke sessions; cancel invitations; change roles; or reassign the Customer. Those actions require their separate authorized workflows.
- An authentication account that allows access continues to allow the Customer to view their own records in all four operational statuses. A Restricted or Archived Customer does not lose visibility of their savings history merely because business activity is blocked.
- A suspended or deactivated Customer cannot sign in, irrespective of operational status. Restoring operational status to Active does not restore account access. An invited Customer still uses Authentication's activation rules; activation does not remove an operational restriction or restore an archived relationship.
- The acting Agent or Admin must have usable account access and current authority for every operation. The Customer's account state governs their own login, not the assigned Agent's authority to operate an eligible Customer record. Operational eligibility and security controls remain independent checks.

### 5.3 Activity matrix

An allowed operation below still requires the existing role, scope, permission, balance, and owning-module prerequisites. This matrix grants no new financial capability to Customers or Admins.

| Operation                                                              | Active                          | Inactive                                             | Restricted                                                           | Archived                                                                                    |
| ---------------------------------------------------------------------- | ------------------------------- | ---------------------------------------------------- | -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| View authorized profile, balances, history, and available statements   | Allowed                         | Allowed                                              | Allowed                                                              | Allowed                                                                                     |
| Edit permitted ordinary profile fields, notes, or internal reference   | Allowed                         | Allowed                                              | Allowed                                                              | Blocked; restore first                                                                      |
| Complete an eligible operational name correction                       | Allowed                         | Allowed                                              | Allowed                                                              | Blocked; restore first                                                                      |
| Customer's own Authentication email/phone/security workflows           | Account rules apply             | Account rules apply                                  | Account rules apply                                                  | Account rules apply                                                                         |
| Create, renew, or amend thrift-plan terms                              | Assigned Agent only             | Blocked                                              | Blocked                                                              | Blocked                                                                                     |
| Record contributions, including catch-up, partial, or advance payments | Assigned Agent only             | Blocked; reactivate first                            | Blocked; lift restriction first                                      | Blocked; restore first                                                                      |
| Initiate a withdrawal request                                          | Assigned Agent only             | Assigned Agent only; existing savings may be settled | Blocked                                                              | Blocked                                                                                     |
| Approve or post a withdrawal/payout                                    | Authorized workflow             | Authorized workflow                                  | Blocked while restriction remains                                    | Blocked                                                                                     |
| Reject an existing withdrawal request                                  | Authorized reviewer             | Authorized reviewer                                  | Authorized reviewer                                                  | No unresolved request should remain at archival                                             |
| Initiate, review, and post an eligible corrective reversal             | Owning workflow                 | Owning workflow                                      | Owning workflow; restriction is not a substitute for reversal review | Blocked; restore first                                                                      |
| Assess new discretionary fees or deductions                            | Owning workflow                 | Blocked                                              | Blocked                                                              | Blocked                                                                                     |
| Apply an already agreed plan or settlement fee when due                | Owning workflow                 | Owning workflow; no new charge policy                | Blocked while restriction remains                                    | Blocked                                                                                     |
| Reconcile previously recorded Agent collections                        | Owning workflow                 | Owning workflow                                      | Owning workflow                                                      | No unresolved Customer obligation should remain at archival; historical read access remains |
| Reassign the Customer without changing other records                   | Admin with `customers.reassign` | Same                                                 | Same; restriction remains                                            | Same; archival remains                                                                      |

Closing or settling an existing plan is permitted for Active and Inactive Customers under the plan and settlement rules. Restricted Customers may not financially settle a plan while the hold remains. Non-financial completion or closure gates remain with Thrift Plans and must not bypass blocked financial actions.

Corrective reversals for Restricted Customers remain possible because a hold must not prevent correction of an erroneous record. They still require Agent initiation, an eligible original transaction, and Admin review under `reversals.review`; a status change or `customers.manage` grant cannot post a correction. Other adjustments and refunds require an explicit owning-module workflow and cannot use a general status exception.

### 5.4 Allowed transitions

Every transition requires `customers.manage`, a reason, a current profile version, and explicit confirmation. No status changes are scheduled or inferred automatically in initial scope.

| From               | To         | Prerequisites and effect                                                                                                                                                                                                          |
| ------------------ | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Creation           | Active     | System default during authorized Agent creation; records the creation event rather than an Admin status change.                                                                                                                   |
| Active             | Inactive   | Participation pause; may retain an existing balance, plan, and pending settlement work. Apply the activity matrix immediately.                                                                                                    |
| Inactive           | Active     | Assigned Agent must currently be eligible to service the Customer. Show existing plans and obligations before confirmation; do not create a plan automatically.                                                                   |
| Active or Inactive | Restricted | Required business-hold reason. Immediate hold does not wait for pending work or a zero balance. Record the preceding status.                                                                                                      |
| Restricted         | Active     | Explicitly lift the hold with a resolution reason; assigned Agent must currently be eligible. Pending financial work does not automatically resume or post.                                                                       |
| Restricted         | Inactive   | Explicitly lift the hold with a resolution reason while retaining the participation pause.                                                                                                                                        |
| Active or Inactive | Archived   | All archival gates in Section 6 must pass at commit, including settlement of unresolved financial obligations.                                                                                                                    |
| Archived           | Inactive   | Proposed restoration entry point: retain identity and all history; an existing eligible Agent assignment or a separately authorized reassignment is required. No new collections until a subsequent Inactive → Active transition. |

Restricted → Archived and Archived → Active/Restricted are not direct transitions in this proposal. First resolve the restriction or restore the archived relationship to Inactive, then apply the appropriate transition. Lifting a restriction requires an explicit choice of Active or Inactive; do not silently restore the preceding state.

An unchanged status is a no-op rather than a new transition. Restricting a Customer never requires an active replacement Agent; assignment availability cannot prevent an urgent business hold. A hold on business activity is distinct from an urgent authentication-access suspension.

### 5.5 Existing plans, daily collections, and pending requests

- A status change preserves balances, contributions, fees, deductions, plans, transaction attribution, and assignment history. It does not reverse a transaction, forgive an obligation, reserve funds, or generate a financial posting.
- Inactive, Restricted, and Archived Customers are excluded from the actionable daily collection list and eligible expected-collection totals from the effective transition time. Admins can find them through explicit status filters, including a separate view of blocked or paused collection work. Overall balance and liability reports continue to include all outstanding balances, regardless of status.
- Status changes do not rewrite previous daily totals, remove existing thrift-card entries, erase missed days, or silently alter a plan's dates or contribution schedule. Historical status intervals must be retained so dated reports can apply status eligibility at the relevant time. Collections already recorded remain in actual collection totals.
- Reactivation does not create payments or automatically mark slots paid, missed, or skipped. Thrift Plans and Collections must define schedule continuation and catch-up allocation using the preserved plan and status history; this module only decides whether new activity is currently permitted.
- Becoming Inactive does not cancel existing withdrawals or reversals; permitted settlement and corrective workflows may continue. It does not automatically complete or renew an existing plan.
- Becoming Restricted blocks new withdrawal initiation, approval, and payout immediately, including previously approved requests that have not posted. Preserve request state, attribution, and any existing reserved funds; show the hold rather than silently rejecting or releasing the request. The Withdrawals module will define its exact hold and reservation states. An authorized reviewer may explicitly reject a request through that module.
- Lifting a restriction does not automatically approve or pay a held withdrawal. Revalidate scope, balance, authorization, and current eligibility through the owning module before progressing it.
- Pending operational name corrections remain eligible in Inactive and Restricted statuses under Section 4. Archival cancels uncompleted name proposals and blocks operational profile edits. It does not cancel Authentication's invitations or recovery workflows.
- Reassignment preserves the current Customer operational status. Reconciliation of historical collections remains attributable to the original Agent and must not be cancelled by a Customer participation pause or restriction.

### 5.6 Transition workflow, audit, and notifications

1. The authorized Admin opens the dedicated Customer status action and selects an allowed target status.
2. The system displays the current operational status, account state separately, current Agent, balances, active plans, and pending work the Admin is permitted to view.
3. The Admin enters a required internal reason of 1–500 characters after trimming and a required Customer-facing explanation of 1–500 characters. The explanation must not expose internal investigation notes or third-party information.
4. The confirmation shows the before-and-after status and concrete effects, including blocked collections or payouts and any failed archival prerequisites.
5. At commit, re-check Admin account access, `customers.manage`, Customer version, allowed transition, Agent eligibility where required, and all transition gates. The status, status-history entry, audit event, and any required cancellation of operational proposals commit together.
6. After commit, notify the Customer and current assigned Agent of the effective status and Customer-facing explanation. Notify the Customer through operational notifications when usable and their recorded email; Authentication continues to own email verification and invitation security. Delivery failure is visible to authorized staff but does not undo or repeat the transition.

Each history entry contains Customer, previous and new statuses, acting Admin or creation actor, server effective timestamp in UTC, reason, Customer-facing explanation, and result. Internal reasons are visible to Admins through authorized management history; detailed audit access still requires `audit.view`. Customers and Agents see the effective status, permitted consequences, and Customer-facing explanation rather than the internal reason.

Statuses take effect immediately at commit. Protected financial actions, approvals, payouts, and background jobs must check current status immediately before posting. If a restriction commits first, a competing prohibited posting fails without financial effect; if the financial posting commits first, preserve it in history rather than pretending the later restriction reversed it. Archival gates and postings must use equivalent concurrency safeguards so an obligation cannot be created between validation and archival.

### 5.7 Customer-status acceptance criteria

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. Agent creation defaults the Customer to operationally Active and authentication Invited; login activation is not needed for permitted Agent collection operations.
2. Only an active Admin with `customers.manage` can perform a status transition; Customers, Agents, and baseline-read Admins cannot.
3. Inactive Customers cannot receive new plans or contributions but may settle existing savings through the authorized withdrawal workflow.
4. Restricted Customers retain permitted read and profile access while new collections, withdrawal initiation, withdrawal approval, and payout are blocked.
5. An eligible reviewed reversal and historical collection reconciliation remain possible during restriction without bypassing their separate permissions.
6. A status change does not change account access, credentials, sessions, assignment, financial balances, or existing financial postings.
7. Existing unposted withdrawals are visibly held during restriction; lifting it does not automatically approve, release, or pay them.
8. A Customer with an unresolved financial obligation cannot be archived; an archived operational profile is read-only and retains all history.
9. Restoration uses Archived → Inactive with an eligible assignment, retains the same identity, and does not reactivate authentication access or create a plan.
10. A stale status form, lost Admin permission, or competing financial posting cannot bypass transition gates or overwrite a newer status.
11. Status-filtered collection eligibility uses effective status history, preserves historical actual collections, and never removes balances from total Customer liabilities.
12. Notifications do not disclose internal reasons, and delivery failure does not undo a committed status change.

## 6. Customer Archival and Restoration

### 6.1 Specification status and authority

This archival and restoration specification is a **draft for review**. Preservation of financial history, separate account-access state, explicit reassignment, and the Archived → Inactive restoration path remain consistent with the preceding modules and Section 5. The detailed settlement gates and record-handling rules below are proposed decisions.

Only an active Admin with `customers.manage` may archive or restore an existing Customer. Customers and Agents may ask that Admin to review the relationship but cannot perform either action. No second-Admin approval, automatic archival, scheduled restoration, or bulk action is introduced in initial scope.

Archival ends operational participation; it does not delete the Customer or authenticate on their behalf. Use the dedicated status-transition workflow in Section 5.6, with its required internal reason, Customer-facing explanation, confirmation, version checks, audit, and notifications.

### 6.2 Archival prerequisites

The Customer must be Active or Inactive. A Restricted Customer must first have the hold explicitly resolved under Section 5; a zero balance alone is not sufficient to remove the restriction or authorize archival.

All gates below must pass using authoritative owning-module state immediately before commit:

| Gate                           | Required condition                                                                                                                                                                                                                                                                                                                                                                              |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Thrift plans                   | No plan remains open for contributions or settlement, including active or paused plans. All existing cycles must be formally closed or cancelled through the plan workflow; reaching the contribution count is not sufficient if payout or fee settlement is outstanding. Any unactivated draft arrangement must be cancelled before archival. Exact plan-state names remain with Thrift Plans. |
| Savings and reserved funds     | The Customer's total savings liability and reserved withdrawal funds are exactly zero under the ledger's monetary precision. No positive balance owed to the Customer, negative balance discrepancy, unallocated payment, or refundable unapplied amount remains. Do not round, overwrite, deduct, or write off a balance to pass the gate.                                                     |
| Withdrawals and payouts        | No requested, under-review, held, approved-but-unpaid, partially completed, or otherwise unresolved withdrawal/payout remains. The owning workflow must complete or reject/cancel it and settle any reservation or payout exception.                                                                                                                                                            |
| Reversals and adjustments      | No pending review, approved-but-unposted reversal, unresolved adjustment, or correction dispute capable of changing the Customer's ledger remains. A closed request must have its required posting or rejection recorded, not merely disappear from a work list.                                                                                                                                |
| Fees, deductions, and refunds  | No outstanding registration fee, agreed plan fee, deduction settlement, refundable charge, or Customer-related refund obligation remains. Payment, waiver, cancellation, or correction must be authorized and finalized in the owning module. Archival itself cannot waive a charge or create a settlement posting.                                                                             |
| Collections and reconciliation | No unresolved collection, allocation, reconciliation discrepancy, or settlement exception affecting this Customer's financial record remains. Unrelated work for other Customers under the same Agent does not block archival.                                                                                                                                                                  |
| Pending financial work         | No queued or in-progress job remains that can post a financial obligation for this Customer. Complete or explicitly cancel it through its owning workflow; disabling its notification is not cancellation.                                                                                                                                                                                      |

The archive preview lists every failed gate and links authorized staff to its owning workflow. Each failure explains the outstanding condition without exposing records outside the viewer's access. If authoritative validation is unavailable, fail closed and show that eligibility could not be verified; an unavailable module is not treated as a zero balance or an empty queue.

Archival does not require the current Agent to be operationally eligible once all gates pass. Retain that assignment and attribution even if the Agent is unavailable. Any work needed to satisfy the gates still requires an eligible authorized actor and, where necessary, a separately authorized reassignment.

An expired, cancelled, delivery-failed, or unused invitation does not itself block archival, and neither does an independent Authentication recovery request. Those states do not prove that the financial gates have passed. Unpaid registration fees do block archival until the Fees module records an authorized settlement or waiver, even when the Customer never activated their login.

### 6.3 Effects of archival and retained access

- Set operational status to Archived and record the actor, effective server timestamp, preceding status, reason, and explanation. Preserve the Customer ID, account link, original registration date, identity fields, internal reference, plans, thrift cards, transactions, statements, assignment history, and historical status intervals.
- Cancel pending operational name-correction proposals as described in Section 4. Preserve their cancellation history. Ordinary operational profile fields, internal notes, and internal references become read-only; staff cannot use archival as permission to erase prior information.
- Block new plans, collections, withdrawals, fees, deductions, and financial corrections while archived. All owning-module mutations and jobs must enforce the Archived status at commit. A later correction requires restoration first and then the dedicated authorized financial workflow.
- Do not change authentication state, revoke sessions, release email/phone uniqueness, cancel invitations, or invalidate security requests. A Customer with usable account access may continue viewing their own retained records and using Authentication's permitted account-maintenance workflows. Activation of an existing invitation does not restore operational participation.
- The current assigned Agent retains only the scoped read access allowed by Section 9. Admins retain baseline business-wide read access, subject to field privacy and separate audit/export permissions. Archival never makes a private profile, photo, or statement public.
- Exclude the Customer from normal active directories and actionable collection lists by default; provide an explicit Archived filter and scoped search access. Financial and historical reports retain prior activity. Lifetime totals and historical statements are not reset or regenerated with altered transactions.
- Retain the effective Agent assignment. An Admin with `customers.reassign` may separately reassign an archived Customer under Section 5.3, preserving Archived status. `customers.manage` alone cannot do so.
- Proposed initial policy: created Customer profiles are archived rather than permanently deleted, including records with no financial history. Mistaken registration uses correction or archival after the applicable gates; it does not free the email, phone, public ID, or internal reference for a new identity.

Retention, masking, and any legally required erasure or anonymization policy remain with the owning privacy/audit requirements. This module defines no permanent-delete shortcut or retention expiry.

### 6.4 Restoration prerequisites and workflow

Restoration is appropriate when the same Customer returns to service or an archived record needs an authorized operational or financial correction. It must reuse the existing identity rather than register a duplicate.

1. An Admin with `customers.manage` opens the archived Customer and selects **Restore to Inactive**.
2. Show retained identity, original registration date, archive event, account state, current Agent and eligibility, existing closed plans, and any newly discovered discrepancies the Admin is permitted to view.
3. Require an eligible current Agent assignment under Section 9.2. If the assigned Agent is unavailable, an Admin with `customers.reassign` must first perform the separate archived-Customer reassignment. The restoring Admin cannot inherit that authority from `customers.manage`.
4. Require the internal reason and Customer-facing explanation from Section 5.6. Explain that restoration will not restore login access, reopen an old plan, or resume collection activity.
5. At commit, re-check actor access, `customers.manage`, Archived status, profile and assignment versions, and current assigned-Agent eligibility. Set the Customer to Inactive and append the restoration/status/audit history atomically.
6. Notify the Customer and current Agent after commit under Section 5.6. Delivery failure does not repeat the restoration or revert the Customer to Archived.

The zero-balance and closed-work gates are prerequisites for archival, not repeated prerequisites for restoration. If an investigation discovers a historical discrepancy, record the issue and restore to Inactive so the owning module can resolve it under its permitted workflow; do not conceal it or rewrite the archive event to make the historical gate appear valid.

Restoration preserves the original Customer ID, registration date, account relationship, lifetime totals, transactions, statements, invitation fee snapshot, and assignment history. Record a separate restored-at timestamp and actor for every restoration event. Multiple archival/restoration episodes are retained in order rather than overwriting the first archive date.

Restoration does not automatically recreate cancelled name proposals, replay queued financial jobs, reopen closed cycles, renew plans, assess a second registration fee, waive existing obligations, or change the Customer's account state. Any future fee policy on returning Customers must be explicitly defined in Fees; this workflow cannot invent a charge or alter the original snapshot.

To resume participation, an Admin must separately complete Inactive → Active under Section 5. An eligible assigned Agent then creates or renews a plan under Thrift Plans where needed. A suspended or deactivated Customer account requires its separate authorized access-restoration workflow; operational restoration does not bypass it.

### 6.5 Failure, concurrency, and later-discovered issues

- Archival and all competing financial mutations must coordinate on current Customer state so a contribution, fee, reservation, or obligation cannot commit between gate validation and archival. If the financial action commits first, recompute the gates; if archival commits first, the prohibited mutation fails with no posting.
- Restoration must coordinate with assignment and Agent-status changes. An Agent who becomes Inactive, locked, Suspended, or Deactivated before restoration commits is no longer an eligible assignee; reject the restoration without a partial status or assignment change.
- Repeated requests for an already Archived Customer or a restoration already committed are safe no-ops or return the current state. They must not duplicate history, notifications, fee snapshots, or financial jobs. A retry of an old restoration cannot reactivate a Customer who was subsequently archived again; enforce the expected version.
- Failed validation, permission loss, or stale versions leave the effective status and financial records unchanged. Any separate reassignment already completed remains a valid independent event; restoration failure does not silently undo it.
- If an issue is discovered after archival, authorized staff may open a linked investigation record through the appropriate owning workflow. No corrective financial posting or archived-profile overwrite is allowed until restoration. The original archive event, subsequent discovery, restoration, and correction remain separately attributable.

### 6.6 Archival and restoration acceptance criteria

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. Only an active Admin with `customers.manage` can archive or restore, and a Restricted Customer cannot bypass explicit hold resolution.
2. An open or paused plan, non-zero liability, reserved funds, unpaid registration fee, unresolved payout, pending correction, or Customer-related reconciliation exception blocks archival.
3. Closing an unrelated Customer's work is not required, and the current Agent's unavailability alone does not block an otherwise eligible archive.
4. Unavailable validation fails closed; a zero-looking display balance cannot substitute for authoritative ledger and obligation checks.
5. Archival preserves identity, uniqueness reservations, account state, assignments, financial records, historical statements, and read access within existing scopes.
6. Archived operational fields and finances are read-only; permitted Authentication maintenance remains separate, and invitation activation does not restore participation.
7. Restoration returns the same record to Inactive with an eligible current Agent. A restoring Admin without `customers.reassign` cannot assign a replacement Agent.
8. Restoration retains closed cycles and the original fee snapshot without charging a new registration fee, replaying work, or restoring login access automatically.
9. A later-discovered discrepancy may be addressed after restoration without erasing the original archive event or bypassing financial permissions.
10. Concurrent obligations cannot pass archival gates, and concurrent Agent ineligibility cannot pass restoration checks.
11. Repeated or stale actions cannot duplicate events or override a later archival episode; notification failure does not roll back a successful action.
12. Profiles without financial history use the same proposed archival path rather than permanent deletion or identity reuse.

## 7. Agent Profile

The Agent profile is an operational record linked to its authentication account. This field specification is a **draft for review** and uses the shared rules in Sections 2.3, 2.4, and 2.6.

| Field                             | Required?        | Validation and ownership                                                                                                                                                        |
| --------------------------------- | ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Full name                         | Yes              | Same 1–150-character name rules as Customers.                                                                                                                                   |
| Email address                     | Yes              | Same email validation and normalization as Customers; unique across all accounts. Authentication owns verification and active-account changes.                                  |
| Phone number                      | Yes, proposed    | Same international-format validation as Customers. Proposed rule: unique across Agent profiles, including deactivated Agents, but not across Customer and next-of-kin contacts. |
| Agent ID                          | System-generated | Immutable public reference; proposed format `AGT-000001`.                                                                                                                       |
| Address                           | No               | Free text, maximum 500 characters.                                                                                                                                              |
| Profile photo                     | No               | Same upload rules as Customer photos.                                                                                                                                           |
| Employment or engagement date     | No               | Valid calendar date, without a time component. Past or future dates are allowed; this records the engagement date and does not automatically activate or deactivate access.     |
| Operational status                | System-managed   | Proposed values: Active and Inactive, defaulting to Inactive. Section 9 defines readiness and transitions separately from authentication states.                                |
| Notes                             | No               | Plain text, maximum 2,000 characters. Internal notes visible only to active Admins; editing requires `agents.manage` under Section 4.                                           |
| Created by / updated by           | System-generated | Actor references from the authenticated operation.                                                                                                                              |
| Created / last updated timestamps | System-generated | Server timestamps stored in UTC. Creation time is separate from the employment or engagement date.                                                                              |

The proposed Agent phone-uniqueness rule does not make the phone number a sign-in identifier or introduce phone verification. Confirm this policy before implementation.

Agent registration must succeed without optional fields. Under the proposed uniqueness policy, equivalent normalized Agent numbers cannot register separate Agents, while a Customer or next-of-kin contact may share an Agent's number. Assigned-Customer counts, collection totals, and reconciliation summaries are derived values and cannot be edited through the profile.

## 8. Agent Registration

The Admin-controlled Agent registration workflow applies the atomic creation, failure, identity-conflict, attempt-tracking, and delivery-retry rules in Section 3:

1. An active Admin with `agents.manage` enters the Agent's identity and optional profile details under Section 7. No Customer registration-fee lookup is required.
2. Validate the fields, system-wide email uniqueness, and proposed Agent phone uniqueness. The Admin confirms one creation attempt; repeat clicks and uncertain responses reuse its reference.
3. Re-check authority at commit and atomically create the server-generated Agent ID/profile, linked Invited account, Inactive operational history, invitation/delivery work, attempt binding, and audit event under Section 3.3.
4. Return **Agent registered** with separate account/invitation states. Authentication dispatches the activation email after commit. Failed delivery preserves registration and exposes only authorized resend/correction actions.
5. The Agent verifies their email, creates their own password, completes mandatory MFA, and saves recovery codes under Authentication. The inviting Admin never creates or learns those credentials.
6. Account activation alone does not make the Agent operationally eligible. Under Section 9's proposal, an Admin with `agents.manage` explicitly changes the Agent from Inactive to Active after activation and mandatory MFA completion.
7. Registration, invitation delivery, account activation, and operational activation remain distinct auditable outcomes. Activation does not create the Agent again or assess a Customer fee.

## 9. Agent Lifecycle

### 9.1 Specification status and separate state dimensions

This Agent-status and eligibility specification is a **draft for review**. Account activation, mandatory MFA, immediate suspension effects, current-assignment scope, and historical attribution remain established requirements from Modules 01–03. The two operational statuses, explicit Admin readiness step, Inactive activity limits, and transition rules below are proposed decisions.

Maintain two separate state dimensions rather than copying authentication states into the Agent profile:

| Dimension                    | Values and owner                                                                                             |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Agent operational status     | **Active** or **Inactive**, owned by Agent Management.                                                       |
| Authentication account state | Invited, MFA setup required, Active, Temporarily locked, Suspended, or Deactivated, owned by Authentication. |

**Operationally Active** means the business has authorized the Agent to perform Customer work, subject to usable account access and current scope. **Operationally Inactive** means the Agent is unavailable for new Customer work, such as during leave, initial onboarding, or offboarding preparation. Inactive is not a security suspension and does not itself revoke account access or sessions.

Proposed default: a new Agent is operationally **Inactive** with an **Invited** account. After the Agent completes activation and mandatory MFA, their account may be Active while their operational status remains Inactive. An Admin with `agents.manage` must explicitly approve readiness through the Inactive → Active transition. An employment date, successful sign-in, invitation resend, or recovery completion never performs that transition automatically.

The UI displays both values, for example **Agent status: Inactive / Account: Active**, and derives an eligibility indicator with an explanation such as **Not eligible: onboarding incomplete** or **Not eligible: account suspended**. Eligibility is computed from current state, not stored as an independently editable boolean.

### 9.2 Eligibility rules

An Agent may perform a permitted Customer operation only when all applicable checks pass:

1. Their role is Agent, account activation is complete, and mandatory MFA enrolment is complete.
2. Authentication permits the current request and session. A trusted device can satisfy only the existing session rules; it does not override status, scope, or dedicated step-up requirements.
3. Their operational status is Active.
4. For an existing Customer, the Agent is the current effective assignee at commit. For Customer creation, the new assignment is to the creating Agent.
5. The Customer operational status permits the requested action under Section 5, and the relevant plan, balance, request, and financial-module prerequisites pass.

Receiving a new or reassigned Customer additionally requires an **Active authentication account** and **Active operational status** at assignment commit. Invited, MFA-setup, temporarily locked, suspended, deactivated, or operationally Inactive Agents cannot be selected as eligible assignment recipients. Candidate lists must explain unavailability without allowing stale selections to bypass the final check.

Authentication's temporary-lock exception remains intact: a lock affecting a new authentication path does not invalidate a legitimate existing session unless Authentication separately revokes it. An operationally Active Agent may continue permitted assigned-Customer work through such a valid session, but does not receive new assignments while their account is marked Temporarily locked. A lock must not be treated as a full account suspension.

An Agent whose account is Suspended or Deactivated has no application access even if the operational record still says Active. This combination must show as ineligible and cannot authorize collections. Account-access restrictions are evaluated before operational readiness.

### 9.3 Agent activity matrix

The matrix below assumes Authentication permits the current session and request. Invited accounts have no application access; MFA-setup accounts have setup-only access; Suspended and Deactivated accounts have none. Every allowed activity retains its current scope and owning-module requirements.

| Activity                                                                                      | Operationally Active                                         | Operationally Inactive                                                 |
| --------------------------------------------------------------------------------------------- | ------------------------------------------------------------ | ---------------------------------------------------------------------- |
| View own Agent profile, account settings, and addressed notifications                         | Allowed                                                      | Allowed                                                                |
| Edit own permitted address and photo                                                          | Allowed                                                      | Allowed                                                                |
| Use own approved email, phone, password, MFA, session, and recovery workflows                 | Authentication and Section 4 rules                           | Same rules                                                             |
| View currently assigned Customer profiles, plans, balances, and history                       | Allowed                                                      | Read-only access remains while the assignment remains effective        |
| View own collection activity and reconciliation status                                        | Allowed within current scope                                 | Same scoped read access                                                |
| Create a Customer and initial assignment                                                      | Allowed only when eligible to receive the initial assignment | Blocked                                                                |
| Receive a new or reassigned Customer                                                          | Allowed only with an Active account                          | Blocked                                                                |
| Edit assigned-Customer profile fields, notes, or internal references                          | Section 4 rules                                              | Blocked                                                                |
| Initiate an operational Customer name correction                                              | Section 4 rules                                              | Blocked                                                                |
| Manage a Customer invitation, including resend or correction                                  | Authentication rules                                         | Blocked                                                                |
| Create, renew, amend, or close a Customer plan                                                | Plan and Customer-status rules                               | Blocked                                                                |
| Record contributions, including late, partial, or advance payments                            | Collections and Customer-status rules                        | Blocked                                                                |
| Initiate a withdrawal or transaction-reversal request                                         | Owning-module and Customer-status rules                      | Blocked                                                                |
| Initiate Customer assisted recovery                                                           | Authentication verification rules                            | Blocked                                                                |
| Provide evidence for previously recorded collection or reconciliation work                    | Only if the owning module explicitly authorizes it           | Same limited evidence workflow; no new collection or financial posting |
| Approve withdrawals, reversals, or recovery; change Customer assignment; manage another Agent | Prohibited                                                   | Prohibited                                                             |

Inactivity does not broaden historical access. After Customer reassignment, the former Agent loses Customer access immediately in either operational status. They retain historical attribution, not a right to the Customer's profile or transaction details. Own collection and reconciliation summaries follow Module 03's scope and the owning modules' privacy rules.

An Inactive Agent may still sign in and complete their own account maintenance when account access permits it. To end all access immediately, use the separate authorized account-suspension action; marking an Agent Inactive is not sufficient.

### 9.4 Allowed operational transitions

Only an active Admin with `agents.manage` may change another user's Agent operational status. Agents cannot activate themselves or set themselves Inactive. Admin baseline read access is insufficient. Every transition uses a dedicated action, a required reason, explicit confirmation, and the current Agent version.

| From     | To       | Requirements and effects                                                                                                                                                                                                                     |
| -------- | -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Creation | Inactive | System default during authorized registration. No assignments or Customer work before account activation and explicit operational activation.                                                                                                |
| Inactive | Active   | Account state must be Active; activation and MFA enrolment must be complete, with no open offboarding case. Show current assignments and unresolved responsibilities, and require Admin confirmation that the Agent is ready to resume work. |
| Active   | Inactive | May take effect immediately regardless of Customer count, unsettled collections, or account suspension. Preserve assignments and attribution; block new Customer mutations and receiving assignments.                                        |

Selecting the current operational status is a no-op. No automatic, scheduled, or bulk transitions are introduced in initial scope. Pending work does not universally block operational activation; explicit owning-module or offboarding gates remain applicable and cannot be bypassed by an Admin readiness confirmation.

Customer reassignment requires `customers.reassign` independently of `agents.manage`. The status-changing Admin may identify Customers requiring replacement, but cannot reassign them without that separate grant. Suspending or deactivating an account remains a separate action under Section 10 and Authentication; account-access restoration and assisted recovery retain their own authorization and verification requirements.

### 9.5 Assignment continuity and pending work

- Inactivity, suspension, and account deactivation do not automatically end or transfer Customer assignments. Preserve the assignment history and show Admins every assigned Customer whose Agent is currently ineligible, including Active, Inactive, Restricted, and Archived Customers.
- Customers retain their own operational status, plans, balances, and login access. For example, an Active Customer does not become Inactive because their Agent takes leave. Flag the service interruption until an eligible Agent is explicitly assigned or the current Agent resumes eligibility.
- Customers without an eligible assigned Agent are excluded from the actionable Agent collection workspace. Admin collection views must distinguish **Customer activity paused** from **assigned Agent unavailable**. Existing obligations and previously recorded collections remain in reports; do not silently remove those Customers from liability totals or rewrite their plan schedules.
- Active → Inactive blocks further Customer work immediately, including forms opened beforehand and queued Agent mutations that have not committed. Invalidate pending staff-name proposals from that Agent because they no longer have operational edit authority under Section 4.
- Previously initiated withdrawals, reversals, recovery requests, and reconciliation work keep their original attribution. Inactivity or suspension does not automatically reject, reverse, approve, or post them. Authorized Admin review may continue where the owning module permits it; further Agent-side Customer work requires an eligible currently assigned Agent.
- Transfer of pending task responsibility follows Sections 10 and 12; owning request modules define their detailed state and evidence mechanics. Reassignment cannot claim that the replacement Agent originally recorded another Agent's collection.
- Inactive → Active restores only currently permitted scope. It does not reclaim Customers already reassigned, renew plans, replay blocked collections, resume queued mutations, approve held requests, or change Customer statuses.
- Account recovery does not override operational Inactive. Account suspension preserves operational status; restoring account access may make an operationally Active Agent eligible again, so the account-restoration confirmation must show that consequence. An Admin may separately set the Agent Inactive when further business-readiness review is required.

### 9.6 Transition workflow, safeguards, and notifications

1. An Admin with `agents.manage` opens the operational-status action and chooses an allowed target.
2. Show current operational status, account state, derived eligibility and blocking reasons, assigned-Customer counts, and pending responsibilities the Admin is authorized to view.
3. Require an internal reason and Agent-facing explanation, each 1–500 characters after trimming. Keep internal investigation details out of the Agent-facing explanation.
4. Show the before-and-after status and consequences. For Inactive, explicitly state that login access and assignments remain and that all-access suspension is a separate action.
5. At commit, re-check the acting Admin's access and permission, Agent version, account and MFA prerequisites for activation, and current transition rules. Commit the status, history, audit event, and invalidation of that Agent's pending Customer name proposals atomically.
6. Notify the affected Agent and active Admins with `agents.manage` of the new status and permitted explanation after commit. Customer-service notifications are required for non-archived Customers left with an unavailable Agent; provide a business contact or next-step explanation without exposing personnel or security reasons. Detailed delivery-channel policy remains with Section 15.

Transition history identifies the Agent, previous and new operational statuses, acting Admin or creation actor, server effective timestamp in UTC, reason, and result. Internal reasons are available to authorized Admin management views; detailed audit access still requires `audit.view`. Notification delivery failures remain visible to authorized staff and do not roll back or repeat a committed transition.

Operational readiness, assignment, Customer status, and current authorization must be checked immediately before any protected Agent mutation commits or background job executes. A competing transition to Inactive or account suspension prevents an uncommitted collection or assignment from posting. A collection that committed first remains a historical transaction and is not reversed by the later status change. Candidate selection, a cached dashboard, or a trusted-device token cannot substitute for these checks.

### 9.7 Agent-status and eligibility acceptance criteria

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. Agent registration defaults to operationally Inactive and account Invited; account activation and MFA completion alone do not authorize Customer work.
2. Only an active Admin with `agents.manage` can confirm operational activation or inactivity. An Agent cannot change their own status.
3. New assignments require both an Active account and Active operational status at commit; a stale candidate selection is rejected safely.
4. An Inactive Agent with usable account access can view permitted records and maintain their own account but cannot create Customers, collect, edit Customer fields, or initiate Customer requests.
5. A temporarily locked authentication path preserves legitimate existing-session rights under Authentication while blocking new assignment receipt under this proposal.
6. Suspended and Deactivated accounts cannot access the application irrespective of operational status; inactivity alone does not revoke sessions.
7. A status change preserves Customer assignments, Customer statuses, balances, plans, pending-request attribution, and historical collections.
8. Reassignment immediately removes the former Agent's Customer access in either operational status; reactivation does not reclaim that Customer.
9. Pending name proposals from an Agent becoming Inactive cannot later change a Customer name; existing financial requests follow their separate review rules.
10. Agent or Customer unavailability is visible in collection views without removing outstanding liabilities or rewriting historical records.
11. Concurrent operational, account-access, and assignment changes are enforced before collection or assignment commit; denied mutations have no partial financial effect.
12. Recovery and account-access restoration cannot silently change operational status, and notifications do not disclose internal personnel reasons.

## 10. Agent Suspension and Offboarding

### 10.1 Specification status and authority

This suspension and offboarding specification is a **draft for review**. Immediate suspension effects, session and trusted-device revocation, explicit reassignment, granular permissions, and retained attribution are established requirements from Modules 01–03. The offboarding workflow, completion gates, reactivation safeguards, and additional fresh-authentication requirement below are proposed decisions.

An active Admin with `agents.manage` may suspend, restore access to, or deactivate an Agent and manage their offboarding case. Customer reassignment separately requires `customers.reassign`. Reconciliation requires `reconciliation.manage`; fee/deduction settlement and withdrawal/reversal review retain their own permissions. Agent assisted recovery, security-case review, and manual temporary-lock clearance remain with `security.operations.manage`.

Proposed safeguard: suspension, access restoration, starting/cancelling offboarding, and final deactivation require the acting Admin's password-and-MFA verification within the preceding 10 minutes. This extends the fresh-authentication policy to these Agent-management actions; it is not an existing requirement of Module 03. No second-Admin approval is introduced. Authorized status management does not let staff set or learn the Agent's credentials.

### 10.2 Immediate suspension

1. The Admin opens the dedicated **Suspend account** action, reviews the Agent's current operational and account states, and enters the internal reason and Agent-facing explanation, each 1–500 characters after trimming.
2. Show that suspension ends all application access immediately, preserves Customer assignments and financial history, and creates a service interruption for assigned Customers.
3. After fresh authentication and confirmation, re-check `agents.manage`, current account/version, and transition eligibility; atomically set account state to Suspended, record the event, invalidate pending Customer name proposals initiated by the Agent, and revoke sessions and trusted-device authorizations. Clear the resume cookie under Authentication's security-sign-out rules.
4. Block all application requests and uncommitted Agent mutations immediately. Authentication must prevent old invitation, activation, or recovery links from bypassing the Suspended state; passwords, MFA bindings, and recovery codes are not replaced by the suspension action itself.
5. Preserve the Agent's operational status, current assignments, original transaction attribution, balances, financial requests, and unresolved cash responsibilities. Flag every affected Customer for Admin review and explicit reassignment where needed.

Suspension may occur before activation or during normal service and does not require settled cash, completed reconciliation, zero balances, or replacement Agents. A Customer-service backlog must never prevent urgent access removal. Deactivated accounts remain Deactivated rather than being relabelled Suspended; repeating an existing suspension is a safe no-op.

An Inactive operational status alone does not revoke access. The suspension action is distinct from the participation pause in Section 9 and from automatic temporary authentication locks.

### 10.3 Restoring a suspended Agent's account access

- An Admin with `agents.manage` records why the suspension is resolved, completes the proposed fresh-authentication check, and confirms the account-access restoration separately from operational activation.
- Derive the destination from retained activation progress: a previously fully activated account may return to Active, an unactivated account returns to Invited, and an account still requiring mandatory MFA receives only the applicable setup access. Do not label incomplete onboarding fully Active or silently clear an unrelated temporary lock.
- Show the resulting operational eligibility before confirmation. If the Agent's retained operational status is Active and account prerequisites are satisfied, account restoration can restore Customer-work eligibility. If it is Inactive, Section 9's separate readiness transition is still required.
- An open offboarding case blocks account restoration until that case is explicitly cancelled under Section 10.6. Unresolved suspension-related security restrictions also block restoration until the authorized security workflow resolves them; `agents.manage` cannot close a security case or perform assisted recovery.
- Previously revoked sessions and trusted devices remain revoked. The Agent must sign in again under Authentication, including MFA. Restoration does not reclaim reassigned Customers, replay blocked mutations, or resume cancelled proposals.
- Notify the Agent and authorized management staff after commit and preserve suspension and restoration as separate history events. Password reset, successful recovery, temporary-lock expiry, or manual unlock cannot restore the suspended account without this management action.

### 10.4 Starting and tracking offboarding

Offboarding is a management workflow for ending an Agent's engagement; it is not a third operational status or a duplicate authentication state.

Proposed initial case states are **In progress**, **Completed**, and **Cancelled**. Maintain at most one open case per Agent. Readiness for completion is derived from the current gates, not a checkbox that overrides owning-module state.

Starting offboarding requires `agents.manage`, a reason, Agent-facing explanation, explicit confirmation, and the proposed fresh authentication. The start action expressly combines two independently displayed effects: set operational status to Inactive and suspend account access. Commit the case, these state changes, revocations, name-proposal invalidations, and audit/history events together. If the account is already Suspended, retain that state and its earlier event; a Deactivated Agent has no new offboarding case to start.

The Agent loses access at the start rather than waiting for completion. Existing assignments remain until explicit reassignment. Handovers and evidence supplied by the departing Agent are collected outside their suspended application access and recorded by an authorized staff member through the owning workflow, with the source and recorder identified. Do not restore access merely to clear a checklist.

The case records the Agent, original account and operational states, initiating Admin, start time, internal reason, explanation, assigned case owner with `agents.manage`, and references to blocking responsibilities. Ownership may be transferred only to another active Admin with `agents.manage`, with a reason and audit event. Evidence and financial decisions remain in their owning modules rather than being copied into an editable offboarding settlement total.

### 10.5 Final deactivation gates and completion

An Admin with `agents.manage` may complete the case only when authoritative checks confirm all of the following:

| Gate                                            | Required condition                                                                                                                                                                                                                                                                                                                        |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Access and readiness                            | Account remains Suspended and operational status Inactive while the case is open. No activation, account-access restoration, or readiness action may bypass the case.                                                                                                                                                                     |
| Customer continuity                             | Every currently assigned non-archived Customer has been explicitly reassigned to an eligible replacement Agent. This includes Active, Inactive, and Restricted Customers, not only Customers collecting today. No assignment is silently ended and no Customer is left without an effective assignee.                                     |
| Archived Customers                              | Existing archived-Customer assignments may remain for historical continuity. Show them separately; future Customer restoration requires an eligible assignment under Section 6. Retaining them grants the Deactivated Agent no access.                                                                                                    |
| Agent-held money and reconciliation             | The Agent's collection submissions, cash/transfer remittance obligations, shortages, overages, and other Agent settlement discrepancies are finalized under Reconciliation. Transferring a Customer or marking a checklist complete does not settle money or move historical collection attribution.                                      |
| Pending financial requests                      | Withdrawals, reversals, refunds, and other pending Customer financial responsibilities are either resolved through their owning workflows or formally handed over to the eligible current assignee. Preserve the original initiating/recording actor, approver history, request state, and existing reservations.                         |
| Customer recovery and invitation work           | Outstanding Customer recovery responsibilities are transferred to the eligible current assignee or explicitly resolved by authorized security staff. Invitation-management responsibility follows current assignment; preserve invitation states and tokens under Authentication rather than issuing duplicates because the Agent leaves. |
| Agent-specific security or business obligations | Any identified Agent recovery/security case or other recorded responsibility has an authorized resolution or accountable business owner. A financial debt or remittance discrepancy cannot be declared resolved solely by assigning an owner; it must pass the reconciliation gate above.                                                 |
| Queued work                                     | No pending Agent-authored job can perform new Customer mutations using old authority. Owning modules cancel or otherwise safely invalidate those jobs; valid pending Admin review is retained with its authorized current owner.                                                                                                          |

Pending Customer requests do not all have to finish before the Agent leaves if the owning module supports a documented responsibility transfer. Agent cash liabilities must be resolved before final deactivation. Detailed request handover mechanics remain with Section 12 and the owning modules; if a safe handover is not defined or cannot be verified, the gate fails rather than silently treating it as complete.

The case displays failed gates and links staff to the relevant authorized workflow. Validation unavailable means completion is unavailable. `agents.manage` does not permit the case owner to reassign Customers, approve withdrawals/reversals, forgive fees, settle reconciliation, or resolve security cases without the separate required permissions.

Once all gates pass, require a final reason, proposed fresh authentication, and a confirmation preview. Re-check actor authority, Agent/case versions, replacement-Agent eligibility, and the full gates at commit. Atomically set the account to Deactivated, keep operational status Inactive, mark the case Completed, record effective deactivation and completion timestamps, and enforce Authentication's session/trusted-device/token safeguards. Completion does not delete the Agent profile, release unique identity values, change roles, or post financial transactions.

If any gate fails, keep the account Suspended and the case In progress. No override for unresolved money or stranded Customers is introduced in this proposal; urgent access removal has already happened at suspension. Deactivation is performed through this case even for an invited Agent who never worked, with inapplicable financial checks verified as empty rather than skipped by a generic delete action.

### 10.6 Cancelling offboarding and a later return

- An Admin with `agents.manage` may cancel an In progress case with a reason and the proposed fresh authentication. Retain its history and leave the Agent Inactive with their account Suspended. Cancellation does not restore access, reverse completed reassignments, reopen resolved requests, or undo settlement postings.
- Resuming service after cancellation requires the separate account-access restoration in Section 10.3 and operational activation in Section 9. Any security or financial prerequisite identified by those workflows remains applicable.
- A Completed case is not cancelled or overwritten. If the same Agent is engaged again, use a dedicated **Reactivate existing Agent** action with `agents.manage`, a return reason, proposed fresh authentication, and a review of earlier deactivation and current restrictions. Retain the same Agent ID, account relationship, identity reservations, creation attribution, and historical transactions.
- Reactivation keeps operational status Inactive. Restore only the account state supported by retained activation/MFA progress, using the same derivation as Section 10.3. Authentication must require approved recovery or renewed onboarding if credentials or MFA are no longer usable; `agents.manage` does not waive those safeguards.
- Notify the returning Agent to complete the required sign-in, activation, or recovery steps. After account prerequisites are satisfied, an Admin separately confirms operational readiness. No previous Customer assignment is reclaimed automatically; retained archived assignments remain archived, and future assignments use current eligibility checks.
- Record the return as a new lifecycle event linked to the completed case. Re-engagement does not charge Customer registration fees, alter Agent collection attribution, forgive old obligations, or erase the previous end-of-engagement record.

### 10.7 Safeguards, audit, and notifications

- Permission, Agent status, account state, case state, assignment, and financial-gate checks use current versions at commit. Starting offboarding or suspending first must prevent competing uncommitted Agent collections and assignments from posting; a transaction committed first remains in history and is included in settlement checks.
- A replacement Agent becoming ineligible before final deactivation must invalidate the applicable continuity gate. A stale completion preview cannot declare Customers safely handed over merely because they were eligible when the case opened.
- Retries of suspension, case creation, completion, cancellation, or reactivation must not duplicate events or override a newer case/account state. An old cancellation or restoration form cannot reopen a subsequently completed or newly started offboarding episode.
- Audit every attempted and committed access transition, case ownership change, handover reference, failed completion gate, cancellation, and return. Identify the actor, affected Agent, previous/new states, case reference, effective time, reason, and result. Do not log credentials, MFA secrets, activation tokens, or recovery codes.
- Agent-visible notifications use the permitted explanation. Notify active Admins with `agents.manage` of management outcomes; security details go only to appropriately authorized staff. Notify non-archived Customers affected by service interruption or reassignment with the business contact or replacement details, without disclosing personnel allegations, internal reasons, or cash investigations.
- Queue notifications after commit. Delivery failure is visible to authorized staff but does not restore access, repeat a reassignment, reopen a case, or reverse completion. A Suspended or Deactivated Agent receives permitted lifecycle notices through their recorded contact channel rather than application access.

### 10.8 Suspension and offboarding acceptance criteria

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. An authorized suspension revokes application access, sessions, trusted devices, and resume state immediately even with unresolved collections or no replacement Agent.
2. Suspension preserves assignments, operational status, balances, request attribution, and historical transactions; it does not reset credentials or make an automatic financial posting.
3. Access restoration requires `agents.manage` and the proposed fresh authentication, respects activation/MFA progress and security restrictions, and cannot bypass an open offboarding case.
4. Starting offboarding creates one case, sets operational status Inactive, and suspends access atomically; pending work does not prevent this start.
5. Final deactivation is blocked while any non-archived Customer lacks an eligible replacement or the departing Agent has unresolved collection/remittance liabilities.
6. Archived assignments may be retained without continued Agent access, and a later Customer restoration requires an eligible current assignee.
7. Formal handover preserves original actors and request/reservation state; an unsupported transfer fails its gate rather than deleting or fabricating completion.
8. An Admin with only `agents.manage` cannot reassign Customers, settle reconciliation, approve financial requests, or perform Agent assisted recovery.
9. Successful completion deactivates the account and preserves all identity/history; failed or unavailable gate checks keep the Agent Suspended and case open.
10. Cancellation leaves access Suspended and readiness Inactive and does not undo reassignments or financial resolutions.
11. A returning Agent reuses the existing identity, completes the required account/MFA steps, and remains Inactive until explicit readiness confirmation; no former Customer is reclaimed automatically.
12. Concurrent and repeated actions cannot bypass settlement, replacement eligibility, newer lifecycle states, or privacy rules in notifications.

## 11. Customer Assignment

Every Customer must have exactly one active Agent assignment at a time.

Here, active assignment means the current effective relationship, not that the assigned Agent is operationally Active. Agent unavailability preserves the relationship while making the affected operations ineligible. Archival also retains an effective assignment unless an authorized reassignment changes it.

The assignment record must contain:

- Customer.
- Assigned Agent.
- Effective timestamp.
- Assigned by.
- Assignment reason.
- Assignment status.
- End timestamp where applicable.
- Assignment version or an equivalent concurrency reference.

Retain historical records rather than relying only on an overwriteable `agent_id`. Initial assignment uses the Customer creation timestamp, identifies the creating Agent as actor, and records Customer registration as its reason. Reassignment ends the previous relationship exactly when the replacement begins under Section 12; no backdating or deletion may alter transaction attribution.

An Agent must satisfy Section 9.2's assignment-recipient eligibility before receiving a Customer assignment. Later suspension or inactivity preserves the current assignment, blocks the applicable Agent operations, and flags the service interruption without silently reassigning Customers.

## 12. Customer Reassignment

### 12.1 Specification status and authority

This reassignment and consequence specification is a **draft for review**. Admin-only reassignment, `customers.reassign`, immediate scope changes, eligible recipient Agents, unchanged Customer finances, and historical attribution are established requirements from Modules 01–03. Pending-task handover, recovery re-verification, preview, and notification rules below are proposed details.

Only an active Admin with `customers.reassign` may reassign a Customer. `customers.manage` and `agents.manage` do not imply that permission. The source Agent may be Active, Inactive, Suspended, or Deactivated; their consent or availability is not required. The replacement must have an Active account and Active operational status under Section 9.2 at commit.

Reassignment is permitted for Active, Inactive, Restricted, and Archived Customers. It preserves that operational status and the account state: it cannot lift a restriction, restore an archived relationship, activate a login, or start a plan. No future-dated, backdated, bulk, or Agent-self-service reassignment is introduced in initial scope. Choosing the current Agent is a no-op, not a new assignment episode.

### 12.2 Confirmation and atomic handover

1. The Admin selects a Customer and sees the current Agent, Customer operational and account states separately, current assignment version, plans, and pending-work summaries within their access.
2. Select an eligible replacement Agent and enter an internal reason and Customer-facing explanation, each 1–500 characters after trimming.
3. Show the immediate loss/gain of Customer access, the existing balances and plans that will be preserved, pending tasks that will transfer, name proposals that will be cancelled, and collection/reconciliation obligations that will remain with the original actor. Security evidence is visible only with its separate permission; the preview may show a generic recovery-pending indicator.
4. After explicit confirmation, re-check actor account access and `customers.reassign`, Customer and assignment versions, replacement eligibility, current pending-task versions, and the availability of required handover integrations.
5. End the previous assignment and start the replacement at the same server timestamp, retaining both records and incrementing the assignment version. There must be exactly one effective assignment with no gap or overlap.
6. Apply the task-responsibility changes and proposal invalidations below together with assignment/status-neutral history and audit records. Publish notifications only after this handover commits.

Customer-facing task ownership should derive from the current effective assignment where possible. If an owning module also stores an explicit service-owner reference, it must change consistently with the assignment. Handover cannot be reported as successful while pending work remains actionable by the former Agent. If required transfer validation or integration is unavailable, fail the reassignment without a partial assignment or task transfer; urgent account suspension remains independently available.

Responsibility transfer does not require the reassigning Admin to hold withdrawal, reversal, reconciliation, or security-review permissions because it does not approve, reject, post, waive, or validate evidence. Those later decisions retain their own permissions and separation of duties.

### 12.3 Access and preserved records

- The former Agent immediately loses all Customer-resource access, including profile, plans, transactions, statements, photos, Customer-linked notifications, recovery details, invitations, and future mutations. Historical `created_by`, prior assignments, transaction attribution, or a known record ID cannot preserve access.
- The replacement receives the Customer's existing operational profile, internal Customer notes, plans, thrift cards, balances, and history needed for service, subject to Customer status and the owning modules' safeguards. They do not gain the former Agent's private Agent notes, credentials, security secrets, other Customers, or unrestricted Agent performance.
- Keep Customer identity, registration date, email, phone, internal reference, account link, fee snapshot, contribution allocations, plan terms, balances, reservations, and financial posting references unchanged. The system must not issue a new Customer ID or financial transaction to represent the handover.
- Customer and Agent login sessions are not globally revoked by reassignment. Server authorization adopts the assignment immediately; remaining valid sessions cannot bypass the change. When a former Agent's open Customer view detects changed scope, clear it and return to an accessible page under Authentication's resume rules.
- Reports distinguish **current service Agent** from **Agent who recorded the transaction**. Historical collector-performance and remittance attribution use the recording actor; current Customer service and future eligible activity use the effective assignment. Reassignment alone does not move fee earnings or financial liabilities between ledgers.

### 12.4 Consequences for pending work

| Record or workflow                                                                      | Consequence of reassignment                                                                                                                                                                                                                                                                                                                           |
| --------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Existing plans, thrift cards, partial slots, catch-up balances, and advance allocations | Retain terms and allocations. The replacement continues only operations allowed by Customer and plan status; no new cycle or payment is generated.                                                                                                                                                                                                    |
| Withdrawal requests, including approved-but-unpaid or held requests                     | Transfer Agent-side follow-up responsibility to the replacement; preserve original initiator, requested amount, approvals, evidence history, reservation, and current state. Do not approve, reject, duplicate, release, or pay because of reassignment.                                                                                              |
| Reversal requests and other permitted correction work                                   | Transfer current Agent-side follow-up while retaining original transaction and initiator attribution, reason, amounts, and review state. The replacement cannot rewrite the original request or approve it. Any amendment follows the owning module's dedicated rules.                                                                                |
| Unapproved Customer assisted recovery                                                   | Transfer contact responsibility but require the replacement to repeat the approved identity-verification procedure and append their own verification evidence before Admin approval may proceed. Preserve the original request, proposed email, actor, and prior evidence; do not label old verification as performed by the replacement.             |
| Recovery already approved with an activation challenge issued                           | Preserve the approved decision and challenge under Authentication. Customer activation may continue unless the authorized security workflow separately suspends or invalidates it. Current-assignment notifications and follow-up go to the replacement; the former Agent loses access.                                                               |
| Customer self-service email change or other Authentication maintenance                  | Continue under existing account safeguards. Assignment does not authorize either Agent to confirm tokens or learn credentials. Notify the current assigned Agent on completion where Authentication requires it.                                                                                                                                      |
| Customer invitation                                                                     | Transfer invitation-management authority to the replacement. Preserve account/invitation state, validity, expiry, and immutable registration-fee snapshot; do not resend or cancel solely because of reassignment. Previously sent email cannot be edited, but the activation screen and any later authorized resend show the current assigned Agent. |
| Pending staff name correction proposed by the former Agent                              | Cancel with reassignment as the reason; retain the proposal history. The replacement may submit a new proposal under Section 4 rather than accepting or impersonating the previous proposer. An Admin-originated proposal remains subject to its own authority/version checks and may become stale.                                                   |
| Historical collections and Agent remittance/reconciliation                              | Keep the recording Agent and their cash/remittance obligation. Transfer Customer-side service queries to the replacement, not the old Agent's liability. Authorized reconciliation staff retain oversight; resolving a shortage does not occur through reassignment.                                                                                  |
| Ordinary profile-edit forms and uncommitted collections                                 | Former Agent submissions fail current scope checks. Do not replay those forms or silently record a queued collection as the replacement's action. A correction or newly recorded payment requires a separately authorized owning-module operation.                                                                                                    |

Transferred Agent-side work may remain blocked by Customer status. A Restricted Customer's withdrawal hold and an Archived Customer's read-only state survive the handover. A newly assigned eligible Agent does not make that Customer financially eligible.

Withdrawal and reversal reviewers evaluate whether initiation was authorized when recorded, retain the original actor, and verify current task ownership and current posting prerequisites. The original Agent's later loss of assignment is not by itself a reason to cancel a legitimate request or demand duplicate initiation. The replacement may not impersonate or change the historical initiator.

Unapproved recovery re-verification is an explicit additional safeguard: an Admin with `security.operations.manage` cannot approve the transferred request while the current-assignment verification is missing or stale. Another reassignment requires verification by the then-current Agent before approval. The security reviewer may reject or require a new request if evidence or the proposed identity change is unsuitable; `customers.reassign` cannot make that decision.

Exact request-state names, evidence formats, payment completion, and correction eligibility remain with the owning financial/security modules. They must implement these handover invariants without granting the replacement new approval powers.

### 12.5 Reconciliation scope and historical responsibility

The former Agent may retain access to their own permitted collection/reconciliation summaries as established in Module 03, but those outputs must not provide continuing access to the reassigned Customer's profile, transactions, statements, or current balance. Provide only the permitted aggregate or masked Agent-settlement information needed for their own responsibility; detailed Customer evidence is available to authorized Admins and the current assignee within their respective scopes.

The replacement may see Customer transactions needed to explain the balance, including who originally recorded them. That does not expose the former Agent's full settlement account or make the replacement liable for earlier cash. If an unresolved reconciliation item requires departing-Agent evidence, collect it through the limited authorized evidence workflow in Section 9 or through authorized staff while access is suspended, as Section 10 defines.

Offboarding treats the Customer handover as complete only when current assignment and Customer-side task responsibility are consistent. The former Agent's separate reconciliation gate may still fail, so reassignment does not automatically complete offboarding or permit final deactivation.

### 12.6 Notifications and background work

- Notify the Customer of the effective reassignment time, permitted explanation, and replacement Agent's business contact details. Do not expose internal reasons, personal Agent notes, security cases, or remittance allegations.
- Notify the replacement of the new assignment and permitted pending-work summary. Every linked resource is still authorized when opened; the notification itself grants no access.
- The former Agent receives a minimal Agent-management receipt of assignment removal containing the event reference and effective time, without Customer profile/financial data or Customer-resource links. This is a notice addressed to the Agent about their own access change, not continuing access to a Customer-linked notification.
- Customer-detail notifications created earlier for the former Agent must no longer be retrievable through the application after scope changes, and queued deliveries must re-check scope before dispatch. Previously delivered messages grant no continuing resource access. If needed, issue the separate minimal removal receipt. Security notifications continue following Authentication's current-recipient rules.
- Agent-authored background jobs and exports re-check scope at execution, response release, and download. Cancel or deny the former Agent's uncompleted Customer work; do not substitute the replacement's identity. Prepared Customer artifacts require authorized retrieval and must not remain accessible through an old Agent link.
- System-owned operational tasks that remain valid route to the current responsible Agent at execution. Already-authorized Customer security challenges retain their own account-bound validity; do not equate transfer of staff follow-up with a transfer of token ownership.
- Delivery failure does not roll back or retry the assignment mutation. Record notification outcomes separately, and authorize recipients and payloads again before retrying delivery.

### 12.7 Concurrency, audit, and acceptance criteria

Two competing reassignments use the same expected assignment version; at most one commits. A changed recipient status, lost Admin permission, intervening status restoration, or newer task decision invalidates a stale preview and requires reload. Archived restoration and offboarding completion re-check assignment eligibility against the committed handover rather than cached candidate lists.

Collections and other Agent mutations use current assignment at commit. If reassignment commits first, the former Agent's uncommitted operation fails without posting. If collection commits first, preserve it as that Agent's transaction and recompute pending-work context before handover. Never backdate assignment to move an earlier transaction to a different actor.

Audit records contain Customer, ended/new assignment references, source and replacement Agents, acting Admin, effective timestamp, reason, task-owner transfers, cancelled proposals, recovery re-verification requirement, and result. Keep historical request actors distinct from new service owners. Internal reasons and security evidence retain their existing visibility restrictions.

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. Only `customers.reassign` permits reassignment, and recipient eligibility is checked at commit regardless of the source Agent's availability.
2. Exactly one assignment becomes effective without a gap or overlap; Customer/account status and all financial values remain unchanged.
3. The former Agent loses Customer access immediately across API requests, open forms, notifications, jobs, exports, and downloads; historical attribution grants no access.
4. The replacement receives permitted existing history and pending service work without old Agent secrets, unrelated Customers, or expanded approval capabilities.
5. Withdrawals and reversals preserve amounts, states, approval history, actors, and reservations; a reassignment never posts or duplicates them.
6. Unapproved assisted recovery requires current-Agent verification before security approval; previously approved account-bound challenges continue under Authentication's safeguards.
7. Invitation authority and current Agent presentation change without resetting invitation lifetime or the registration-fee snapshot.
8. Former-Agent name proposals are cancelled, stale profile saves fail, and neither is silently replayed as the replacement.
9. Historical collections and cash liabilities remain with the recording Agent; masked own-settlement visibility does not restore Customer access.
10. Restricted and Archived Customer limits survive reassignment, and handover alone cannot complete unresolved Agent offboarding settlement.
11. Missing handover integration or competing assignment/task changes fail atomically, with no partial owner transfer or financial effect.
12. Notifications respect post-handover scope, and delivery/retry failures cannot undo or duplicate the effective assignment.

## 13. Directories, Search, and Filtering

### 13.1 Specification status and screen access

This directory and profile-screen specification is a **draft for review**. Record scope, Admin baseline read access, field privacy, and protected actions remain binding from Module 03 and Sections 4–12. Columns, filters, defaults, section organization, and interaction details below are proposed requirements, not a completed UI implementation.

| Screen             | Customer                                                                                        | Agent with usable account access                                                           | Active Admin                                                       |
| ------------------ | ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ | ------------------------------------------------------------------ |
| Customer directory | No access                                                                                       | Currently assigned Customers only, including read-only access while operationally Inactive | Business-wide Customer records                                     |
| Customer profile   | Own record only                                                                                 | Currently assigned Customer only                                                           | Business-wide read access; edits/actions require their permissions |
| Agent directory    | No access                                                                                       | No cross-Agent directory                                                                   | Business-wide Agent records                                        |
| Agent profile      | Assigned-Agent business contact only on their own Customer profile; no Agent management profile | Own profile only                                                                           | Business-wide read access; edits/actions require their permissions |

An Agent's Customer directory is not a business-wide list with hidden rows. Scope is applied before search, counts, sorting, pagination, filter options, previews, or suggestions are computed. Customers cannot use a directory endpoint to discover other Customers or Agents.

### 13.2 Customer directory

**Default presentation:** show non-archived Customers in the viewer's scope, including Active, Inactive, and Restricted records. Sort newest registration first, with Customer ID as a stable tie-breaker. Provide an explicit Archived filter and an All statuses option that includes Archived records. Neither implies wider access.

| Information        | Desktop presentation                                | Compact/mobile presentation                                                    |
| ------------------ | --------------------------------------------------- | ------------------------------------------------------------------------------ |
| Identity           | Full name, optional photo/fallback, Customer ID     | Name and Customer ID are always visible                                        |
| Contact            | Phone; email available as secondary information     | Phone shown; email available on opening the profile                            |
| Assigned Agent     | Agent name plus unavailable indicator when relevant | Visible for Admin viewers; an Agent's own directory need not repeat their name |
| Operational status | Distinct Active/Inactive/Restricted/Archived label  | Always visible                                                                 |
| Account state      | Separate Authentication state label                 | Always visible separately from operational status                              |
| Current plan       | Plan name/status or No active plan                  | Secondary information; profile provides full detail                            |
| Registration date  | Business-timezone display                           | Secondary information                                                          |
| Actions            | Open profile plus permitted contextual actions      | Accessible action menu; same authorization rules                               |

Filters include:

- Search by name, normalized phone, email, Customer ID, or optional internal reference.
- Operational status and Authentication account state as separate controls.
- Invitation delivery/activation state, using Authentication's invitation catalogue independently of account state.
- Assigned Agent and assigned-Agent eligibility for Admins; an Agent viewer's assignment scope is fixed and cannot be broadened by a filter.
- Active-plan presence: Has active plan or No active plan. Detailed plan-state filters use the Thrift Plans catalogue when available, not invented Customer statuses.
- Registration date range, interpreted in the configured business timezone with inclusive user-selected dates. Invalid or reversed ranges receive a validation error.

Show the matching-result count after current scope and filters. Do not use a global count or empty filter option to reveal Customers outside scope. Historical balances are not recalculated in directory rows; this screen does not require a second independently maintained Customer balance.

### 13.3 Agent directory

**Default presentation:** Admin-only; show non-deactivated Agent accounts with both Active and Inactive operational statuses. Sort newest profile creation first, with Agent ID as a stable tie-breaker. Include invited, MFA-setup, locked, and suspended accounts rather than pretending they are available Agents. Provide explicit Deactivated and All account states filters.

Columns show name/photo fallback, Agent ID, email, phone, operational status, account state, derived eligibility/blocking explanation, current non-archived assigned-Customer count, and profile creation date. Archived assigned-Customer count is shown separately rather than inflating the service workload. Compact rows retain identity, both states, eligibility, and service-Customer count; full contact details are available on the profile.

Filters include name/email/phone/Agent ID search, operational status, account state, invitation state, assignment eligibility, minimum/maximum current non-archived assigned-Customer count, and profile-creation date range. Counts are derived from current effective assignments, including Inactive and Restricted Customers. A date filter on creation is explicitly labelled Registered date; it must not silently use the optional engagement date.

Opening a record is available under Admin baseline access. **Register Agent** and management actions require `agents.manage`; Customer reassignment requires `customers.reassign` separately. The directory is not an eligible-recipient picker: reassignment pickers apply Section 9.2's stricter rules and revalidate at commit.

### 13.4 Shared search, sorting, pagination, and navigation

- Use the shared identity normalization for exact email/phone/reference comparisons and case-insensitive name/reference search. Accept formatted phone input and support partial search without removing scope checks. No cross-scope suggestion or duplicate-match preview is permitted.
- Proposed initial page size: 25 records, with 25/50/100 choices and Previous/Next navigation. Apply scope and filters before pagination. Default sort is creation/registration descending; allow name ascending/descending and date ascending/descending with stable ID tie-breakers.
- Changing search, filters, or sort returns to the first page. Provide Clear filters, restoring the screen's documented default exclusion of archived/deactivated records rather than silently expanding to all records.
- Preserve list search/filter/sort/page state when navigating to a profile and back within the same authorized session. Clear user-specific state on account switch, and revalidate scope after permission or assignment changes. Stale rows and counts must not remain actionable.
- Direct profile URLs and nested section requests apply the same scope as directory navigation. For absent or unauthorized records, show a generic Record unavailable response without confirming another Customer's existence or disclosing their fields.
- A full-row click may open a profile, but contextual buttons must retain their own accessible names and not accidentally trigger row navigation. Keyboard and touch users can reach search, filters, row actions, and pagination.

### 13.5 Customer profile sections

| Section                    | Required contents and scope                                                                                                                                                                                                                                                                                                            |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Header                     | Name, photo/fallback, Customer ID, original registration date, operational status, account state separately, assigned Agent's permitted business contact, and assigned-Agent unavailable indicator.                                                                                                                                    |
| Status and pending actions | Customer-facing explanations for restriction, archival, participation pause, pending name confirmation, or invitation issues when relevant. Internal reasons remain restricted to authorized management views. No banner implies that login activation clears a business hold.                                                         |
| Financial summary          | Lifetime contributions, current available savings balance, total withdrawals, total deductions, total fees charged, and current plan summary from the owning modules. Show reserved funds separately when Withdrawals provides that value. Registration fees follow Fees' accounting policy rather than being subtracted locally.      |
| Personal details           | Name, contact details, address, gender, occupation, next of kin, and optional internal reference under Section 4 visibility. Editable fields follow the matrix; Authentication-owned email/phone actions are separate from the ordinary form.                                                                                          |
| Plans and thrift cards     | Existing active plan(s), paid/partial/pending/missed/advance slots, plan terms, and previous closed/cancelled cycles when the owning features are available. Preserve history for Inactive, Restricted, and Archived Customers.                                                                                                        |
| Recent transactions        | Proposed latest 10 authorized ledger entries with reference, date, type, amount, status, and payment method, plus View all. Include contributions, withdrawals, fees, deductions, reversals, and adjustments; the ledger determines ordering and financial effect. Staff views distinguish the recording actor from the current Agent. |
| Requests and statements    | Permitted existing withdrawal/correction request status and available statements. Customers see their own records but gain no financial initiation or approval capability. Recovery evidence, security tokens, and privileged review controls are not part of ordinary profile responses.                                              |
| Internal notes             | Current assigned Agent and Admins only, including audit-preserving edit behaviour from Section 4; entirely omitted from Customer responses.                                                                                                                                                                                            |
| Relationship history       | Registration, status episodes, assignment changes, and restoration events. Customers see their own permitted explanations; Agents see service history without internal personnel/security reasons; Admins see permitted business history. Detailed audit access remains separate under `audit.view`.                                   |

Financial values come from authoritative ledger/financial-module outputs, including the effect of reversals. Do not recalculate from only the recent transaction list or substitute lifetime gross contributions for available balance. When a source is unavailable, show **Balance unavailable** or the corresponding section error rather than zero. Values and actions that depend on the unavailable source cannot support a financial confirmation until refreshed.

### 13.6 Agent profile sections

- **Header and readiness:** name/photo fallback, Agent ID, original creation date, operational status, account state separately, derived eligibility and blocking reasons. Do not show security secrets or detailed lock-investigation context without the appropriate permission.
- **Personal and engagement details:** contact, address, optional engagement date, and update metadata under Section 4. Agent self-service exposes only their allowed fields and security actions; the engagement date and full-name correction remain Admin-managed.
- **Current assignments:** service-Customer counts broken down by Customer operational status, a separate archived count, and a scoped list linking to currently assigned Customer profiles. Admins view the selected Agent's assignments; an Agent views their own only. Reassignment controls require `customers.reassign` and are absent from Agent self-service.
- **Collections and reconciliation:** permitted own or Admin business-wide summaries supplied by the owning modules, showing historical recording attribution and unresolved responsibilities. Reconciliation decisions require `reconciliation.manage`; the profile cannot edit totals or turn a transferred Customer into a settled cash liability.
- **Invitation and account access:** visible account/invitation progress and latest issue time. Authorized Admins use the existing invitation, suspension, restoration, and recovery workflows; an Agent manages only their own permitted security settings. Completed activation is distinct from operational readiness.
- **Lifecycle and offboarding:** status/lifecycle history, open-case summary, and failed completion gates for Admins with `agents.manage`. Agent self-service receives only permitted lifecycle explanations, not internal case reasons, investigation evidence, or private management notes.
- **Internal notes:** active Admins only; edits require `agents.manage`. Omit this section from Agent responses, not merely from the rendered UI.

Baseline-read Admins can view the Agent's operational profile and permitted summaries without management controls. Internal offboarding details require `agents.manage`, security evidence requires `security.operations.manage`, and full audit views require `audit.view`; no generic history tab bypasses those boundaries.

### 13.7 Contextual actions and blocked-action explanations

| Viewer/context                        | Actions offered when currently eligible                                                                                                                                                                                                                     |
| ------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Customer on own profile               | Edit permitted personal details, dedicated name/phone/security changes, pending-name accept/reject, and access to available own statements. No collection, plan creation, withdrawal initiation, reversal initiation, or business-status control.           |
| Operationally Active assigned Agent   | Add Customer from directory; edit permitted details; eligible invitation/name/recovery actions; plan and contribution actions; withdrawal/reversal initiation through owning modules. Customer status and Agent eligibility still govern each action.       |
| Operationally Inactive assigned Agent | Open scoped records and permitted read views; no Customer-management mutation controls. Own Agent profile maintenance remains available under Section 9.                                                                                                    |
| Admin with baseline read access       | Open Customer/Agent profiles, authorized history, and read-only financial summaries. No Add Customer, collection posting, plan management, or Agent-side request initiation.                                                                                |
| Admin with relevant grants            | Customer edit/status/archive/restore under `customers.manage`; reassignment under `customers.reassign`; Agent registration/readiness/access/offboarding under `agents.manage`; review/security/reconciliation actions only with their separate permissions. |

Hide actions prohibited by role or missing permission. For an action the viewer normally has but the current status, plan, balance, invitation state, or Agent eligibility blocks, show a disabled control with a specific permitted explanation and next step. Archived ordinary edits require restoration; Restricted payouts require hold resolution; delivery failure offers resend only to the current authorized invitation manager and within Authentication limits.

Business-wide or multi-Customer report exports require `reports.export`; any single-record statement/download still follows its owning module and current resource scope. Do not introduce a general directory CSV export or multi-record action under baseline read access.

The UI does not authorize the action: every submitted mutation re-checks the existing server-side rules. If authority changes while a screen is open, remove affected controls, refresh scoped content, and safely explain the denied action without retaining unauthorized Customer data.

### 13.8 Loading, empty, unavailable, and mobile states

- **Loading:** show clear progress without placeholder financial zeros or actionable buttons based on guessed eligibility.
- **No records in scope:** explain the empty scope; offer Add Customer only to an eligible Agent or Register Agent only to an Admin with `agents.manage`. An Agent with no assignments must not see another Agent's counts.
- **No filter matches:** retain the filter values and offer Clear filters. Distinguish this from having no authorized records at all.
- **New profile with no activity:** show No contributions yet, No active plan, and the authoritative zero summary only when the source confirms it. Do not mistake absent financial data for zero.
- **Section failure:** keep independently authorized working sections usable, identify the failed section, and offer Retry. A failed balance, obligation, or eligibility source blocks dependent confirmations; repeating a read does not repeat creation, reassignment, or other mutations.
- **Stale or revoked access:** use the generic unavailable state and return to an accessible list. Do not render last-loaded personal information after discovering that scope was lost.
- **Responsive presentation:** use compact lists/cards or priority columns on mobile, with no required horizontal scrolling for identity, statuses, or primary actions. Profile sections remain readable, and confirmation actions are keyboard/touch accessible.
- **Accessibility:** use text labels as well as status colour, semantic tables/list headings, labelled search/filter inputs, visible focus, field-specific validation, and clear loading/result/error announcements. Menus and dialogs support keyboard dismissal and return focus to their trigger.

### 13.9 Directory and profile-screen acceptance criteria

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. Customer, Agent, and Admin screen access matches Section 13.1, including direct URLs and nested section requests.
2. Agent searches, filter options, suggestions, counts, and pages expose only currently assigned Customers in either operational status.
3. Default directories exclude archived Customers/deactivated Agent accounts without hiding other eligible scoped statuses; explicit filters expose permitted retained records.
4. Operational status, account state, invitation progress, and Agent eligibility are displayed distinctly and cannot be confused as one status.
5. Date ranges, sorting, stable tie-breakers, page-size choices, and list/profile return navigation behave consistently without cross-user state leakage.
6. Customer financial summary contains the PRD-required totals and current plan, sourced authoritatively rather than computed from a recent subset.
7. Private notes, security evidence, and privileged audit/case details are omitted from unauthorized responses, not merely hidden visually.
8. Every offered mutation matches current role, permission, assignment, Customer status, and Agent eligibility; prohibited Admin collection/Customer-creation controls never appear.
9. Current and archived assignment counts are distinct, and historical collection attribution does not move to the Customer's replacement Agent.
10. Empty scope, no matches, loading, unavailable financial values, and section failures have distinct safe states; reads/retries create no business mutations.
11. Reassignment or permission changes invalidate stale data/actions across directory, profile, nested requests, and downloadable resources.
12. Desktop/mobile and keyboard/touch users can identify records, distinguish statuses, navigate sections, and complete permitted actions without colour-only cues or essential horizontal scrolling.

## 14. Authorization Rules

Apply the permissions already established in Module 03.

| Action                            | Required authority                                   |
| --------------------------------- | ---------------------------------------------------- |
| Create Customer                   | Active Agent                                         |
| View Customer                     | Customer themselves, assigned Agent, or active Admin |
| Update assigned Customer          | Assigned Agent, within allowed fields                |
| Manage Customer business-wide     | Admin with `customers.manage`                        |
| Reassign Customer                 | Admin with `customers.reassign`                      |
| Register or manage Agent          | Admin with `agents.manage`                           |
| Create Customer as Admin          | Prohibited                                           |
| Assign Customer to inactive Agent | Prohibited                                           |

Agent assisted recovery remains a security operation requiring `security.operations.manage`, not an ordinary Agent profile-management action.

## 15. Notifications

### 15.1 Specification status, channels, and ownership

This notification specification is a **draft for review**. Recipient scope, post-commit dispatch, separate delivery outcomes, current-assignment checks, and Authentication ownership of activation/security messages remain binding. The event/channel matrix, operational retry policy, delivery visibility, and content limits below are proposed details.

Initial operational channels are **in-app** and **email**. SMS, WhatsApp, browser push, digest emails, and scheduled reminder campaigns are outside this module's initial scope. In-app messages require usable account access; an Invited, Suspended, or Deactivated person may receive only an authorized minimal email notice, not application access through its link.

Authentication owns invitations, token generation/rotation, identity verification, password/MFA/recovery messages, email-change confirmations, and their security-notification policy. This module supplies business-event context and management-screen visibility without sending a competing activation challenge or duplicating a security email. Fees and financial-request modules continue to own their financial notification contents and outcomes.

### 15.2 Event, recipient, and channel matrix

| Event                                                                                                   | Recipients and default delivery                                                                                                                                                                                                     | Content and action                                                                                                                                                                                                                                          |
| ------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Customer registration commits                                                                           | Creating Agent: in-app completion/receipt. Customer: Authentication invitation email                                                                                                                                                | Separate Registered, Invited, and delivery states. Invitation includes the immutable fee snapshot under Authentication; no redundant Customer-created email or claim of activation.                                                                         |
| Agent registration commits                                                                              | Creating authorized Admin: in-app completion/receipt. Agent: Authentication invitation email                                                                                                                                        | Agent is operationally Inactive; account activation/MFA and Admin readiness are separate next steps.                                                                                                                                                        |
| Invitation fails or delivery is uncertain                                                               | Current authorized invitation manager: in-app issue. Agent invitation issues go to active Admins with `agents.manage`; Customer issues are visible to the current eligible assigned Agent and active Admins with `customers.manage` | Safe failure/uncertainty explanation, issue time, and authorized resend/correction link. Do not send a failure email to an address the provider cannot confirm.                                                                                             |
| Routine Customer personal edit by staff                                                                 | Activated Customer and current assigned Agent: in-app                                                                                                                                                                               | Name the changed field categories and the actor type; link to the authorized current profile. If the Agent made the edit, their completion receipt satisfies their own notice. Do not include next-of-kin contact or full old/new addresses in the message. |
| Customer self-service ordinary edit                                                                     | Current assigned Agent: in-app; Customer sees completion                                                                                                                                                                            | Permitted field-change summary. No business-wide fan-out or extra security email for an address/photo edit.                                                                                                                                                 |
| Staff name correction proposed                                                                          | Customer: in-app plus email; requesting staff member sees pending state                                                                                                                                                             | Explain that confirmation is required, include expiry, and link to authenticated review. The email is not a one-click approval or a password request.                                                                                                       |
| Name proposal accepted, rejected, expired, cancelled, or invalidated                                    | Customer and still-authorized requesting staff member: in-app; current Agent receives an effective name-change notice when applicable                                                                                               | Report the actual outcome. A former Agent receives no Customer-linked proposal content after reassignment; the minimal removal receipt is sufficient.                                                                                                       |
| Direct or confirmed Customer name change                                                                | Customer and current assigned Agent: in-app plus minimal Customer email                                                                                                                                                             | Identity-change alert and authorized next step. Pre-activation correction is reflected in the current invitation/profile workflow rather than implying Customer sign-in access.                                                                             |
| Customer phone or active email/security change                                                          | Authentication's security recipients/channels plus Section 4's current-assignment notice                                                                                                                                            | Customer phone completion uses verified email and current Agent in-app. Email/password/MFA/recovery messages remain Authentication-owned, not a second management email.                                                                                    |
| Customer Active/Inactive/Restricted/Archived transition or restoration                                  | Customer: in-app where accessible plus email; current assigned Agent: in-app; acting Admin sees completion                                                                                                                          | Effective status/time, Customer-facing explanation, permitted consequences, and business contact. Internal reasons remain private.                                                                                                                          |
| Customer reassigned                                                                                     | Customer: in-app where accessible plus email. Replacement Agent: in-app. Former Agent: minimal Agent-management removal receipt. Acting Admin sees completion                                                                       | Replacement business contact and permitted explanation; Section 12 controls payload privacy and loss of old scope. No financial approval or fee assessment occurs.                                                                                          |
| Agent operational activation/inactivity                                                                 | Affected Agent: in-app where accessible plus email; active Admins with `agents.manage`: in-app                                                                                                                                      | Separate operational/account states, effective time, and Agent-facing explanation. Do not disclose internal personnel reasons.                                                                                                                              |
| Agent suspended, account access restored, offboarding started/completed/cancelled, or later reactivated | Affected Agent: email plus in-app only if account access permits; active Admins with `agents.manage`: in-app                                                                                                                        | Management outcome and next steps. A revoked-access notice cannot link to a usable privileged session. Security-investigation contents go only through authorized security channels.                                                                        |
| Non-archived Customer has an unavailable assigned Agent                                                 | Customer: minimal service-interruption email and in-app where accessible; active Admins with `agents.manage` or `customers.reassign`: in-app issue/work queue                                                                       | Business contact or replacement-service next step. Do not reveal Agent suspension allegations, private status reasons, or cash discrepancies.                                                                                                               |
| Internal notes/reference or offboarding case-owner change                                               | Acting staff sees completion; eligible case owner/management recipients receive an in-app ownership notice when responsibility changes                                                                                              | No Customer/Agent self-service message exposing private notes. A changed internal reference is visible only through permitted profile access, not a broadly distributed payload.                                                                            |

An initiating user's success receipt must not become a second identical in-app message. Routine personal edits before Customer activation remain visible in permitted management/invitation workflows; no ordinary edit message implies that an Invited Customer can log in.

A service-interruption issue remains available to authorized Admins while the Agent is unavailable. Emit a Customer notice when service first becomes unavailable and a resolution/reassignment notice when the condition changes meaningfully; do not repeat alerts on every page load, background refresh, or checklist poll. Archived Customers receive no operational service-interruption campaign.

### 15.3 Recipient authorization and content privacy

- Derive recipients from the affected account, current assignment, case ownership, and actual required Admin permission. Baseline Admin read access alone does not subscribe an Admin to protected management/security messages.
- Re-check recipient account access, permission, and resource scope before in-app retrieval, email dispatch, and retries. Current-assignment notices route to the current Agent rather than retain a superseded recipient. A removed grant must stop further protected notification access/delivery.
- Email lifecycle notices to an Invited or revoked-access account contain only the intended person's permitted explanation and safe business contact/activation next step. Do not attach statements, balance histories, private notes, or privileged evidence. Routine identity-change security emails use Authentication's verified-address rules.
- In-app summaries contain the event type, effective time, permitted actor/subject reference, user-facing explanation, read state, and an authorized next step. Email subjects avoid financial amounts, detailed identity data, internal reasons, and security-investigation content.
- Never include passwords, authenticator/recovery secrets, raw security evidence, or management approval credentials. Only Authentication may include its purpose-bound activation/confirmation challenge using its own safeguards. General management links require fresh authorization when opened and cannot approve or post a financial action.
- Use the former-Agent removal receipt defined in Section 12, without Customer links/details. Suppress queued out-of-scope Customer notifications; do not preserve access because the person was authorized when the event occurred.
- Read/unread is tracked per eligible in-app recipient. Email provider acceptance is a delivery result, not proof that the recipient read it. No email-open tracking is required by this module.

### 15.4 Delivery, deduplication, retry, and failure visibility

- Commit a durable event/delivery intent with the successful business operation, then dispatch asynchronously. No successful-change notice is emitted for a failed, denied, stale, or no-op mutation. UI validation/permission errors are not successful event notifications.
- Give each notice a stable source-event, recipient, channel, and purpose binding. Retrying an operation, server restart, or recipient refresh must not create duplicate logical notices. A separately authorized resend is Authentication's distinct event, not a retry of Customer creation.
- Proposed operational-email policy: initial dispatch plus at most two automatic retries within 15 minutes for transient failures. Keep payload and event references stable, revalidate scope each time, and do not retry a permanent rejection blindly. Invitation retries use Section 3.7 and Authentication rather than a second competing policy.
- Track operational delivery as Pending, provider-accepted/Sent, Failed, or Suppressed/superseded. Provider acceptance unknown remains an uncertain attempt under Pending until reconciled or safely closed; it is not presented as confirmed receipt. Store individual attempts and safe error categories separately from the underlying event.
- Authorized staff may retry a failed operational delivery for the same event after correcting its legitimate delivery issue and rechecking current scope. This cannot rerun profile edits, restore access, reassign Customers, reset tokens, or replay financial posting. Invitation correction/resend still uses Authentication's allowed states and rate limits.
- Management views show safe delivery status, last attempt time, and retry availability within their permissions: Customer management under `customers.manage`, Agent lifecycle/cases under `agents.manage`, assignment delivery under `customers.reassign`, and security-message context under `security.operations.manage`. An assigned Agent sees only their authorized Customer invitation/delivery issues.
- A delivery failure never rolls back the committed business change or blocks an urgent suspension. It creates one deduplicated unresolved delivery issue for authorized staff, not an escalating stream of duplicate alerts. In-app notices remain available if their independently authorized channel succeeded.
- Message expiry/retention and user preference controls remain with the eventual Notifications policy. No opt-out from required lifecycle/security notices or automatic deletion rule is introduced here; routine channels above are the initial defaults.

### 15.5 Notification acceptance criteria

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. Each event follows the matrix's eligible recipients/channels, including account-access limitations and current Agent routing.
2. Invitations/security challenges remain Authentication-owned with no duplicate management activation or confirmation email.
3. Reassignment, permission removal, suspension, and scope changes suppress unauthorized queued and retrievable details; former-Agent receipts remain minimal.
4. Private notes, internal reasons, next-of-kin details, balances, and security secrets do not appear in unauthorized payloads or email subjects.
5. A committed mutation yields at most one logical notice per recipient/channel/purpose; retries and no-op submissions cannot duplicate it.
6. Failed/uncertain delivery is separate from successful business state and never restores access or repeats the underlying mutation.
7. Automatic/manual delivery retries preserve event identity and re-check authority; invitation resend/correction retains its existing distinct safeguards.
8. Service interruption and delivery issues remain visible to authorized staff without unchanged repeated alerts, and email acceptance is not labelled Read.

## 16. Audit Requirements

### 16.1 Specification status and ownership

This audit specification is a **draft for review**. Material-event auditability, secret exclusion, granular audit access, authorization denials, and historical integrity are established requirements. The event catalogue, minimum schema, protected change payloads, and local-durability behaviour below are proposed details.

Customer and Agent Management emits its canonical business events. Authentication emits credential/invitation/session/recovery events; Authorization emits permission/scope events; Fees and financial modules emit their postings/decisions. Link related events through one operation/correlation reference rather than representing the same mutation as several independent Customer creations or fee assessments.

### 16.2 Audited event catalogue

| Event family               | Required events/results                                                                                                                                                                                                                                                  |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Registration               | Customer/Agent creation committed; pre-commit validation/uniqueness rejection, authorization denial, fee-version failure, persistence failure when definitively known, and uncertain-outcome resolution. A replay/no-op is not a second creation event.                  |
| Profile changes            | Ordinary field edits and explicit clears, name and phone corrections/changes, internal-reference changes, notes replaced/cleared, photo added/replaced/removed, and engagement-date corrections.                                                                         |
| Name-confirmation workflow | Proposal issued/replaced, accepted, rejected, cancelled, expired, or invalidated, including responsible actor and triggering reassignment/status event.                                                                                                                  |
| Customer lifecycle         | Every status transition, archival eligibility failure, archive, restoration failure/success, and linked later-discovered discrepancy.                                                                                                                                    |
| Agent lifecycle            | Operational activation/inactivity, account suspension/access restoration, offboarding start/owner transfer/gate failure/completion/cancellation, and later reactivation. Link Authentication's actual revocation/recovery outcomes.                                      |
| Assignments and handover   | Initial assignment, ended/new assignment on reassignment, task-owner changes, preserved original actor references, recovery re-verification requirement/result reference, and failed/stale handover.                                                                     |
| Management invitations     | Invitation requested from creation/resend/correction/cancellation and delivery-attempt outcome references. Authentication owns the canonical token, activation, expiry, and security audit; no token content is copied here.                                             |
| Safeguards and access      | Unauthorized cross-Customer/cross-Agent access, prohibited Admin Customer creation/collection attempts, protected-field submissions, lost permission/assignment at commit, stale version conflicts, invalid transitions, and attempted archival/offboarding gate bypass. |
| Notifications              | Notice queued/suppressed, confirmed delivery failure/retry outcome, and change of permitted recipient due to scope. Preserve the source business event rather than modifying it to represent delivery.                                                                   |
| Privileged history access  | Detailed audit access and any later authorized audit-export/reveal operation under the Audit module's own access policy; read access does not silently grant export.                                                                                                     |

Routine authorized list reads need not emit one business-audit event per row. Security-relevant denials and protected operations do. For repeated denied attempts, shared audit/security infrastructure may aggregate equivalent failures with first/last times and attempt count, provided it preserves actor/target context and does not hide a distinct protected mutation or successful event.

### 16.3 Minimum audit-event schema

Each event contains:

- Unique immutable event ID, event type, owning module, configured business reference, and operation/correlation reference. Include creation-attempt or offboarding-case reference where applicable.
- Server event time in UTC and effective business timestamp when different; do not trust client time for ordering status/assignment history.
- Actor reference and role, or an explicit system actor; affected entity type and record reference when safely resolved. Failed registration may identify only its attempt, not a nonexistent Customer ID.
- Outcome: Succeeded, Denied, Validation failed, Conflict, or System failed, with a safe reason/error category. An unresolved timeout is tracked as an uncertain attempt, not falsely recorded as committed success or definitive failure.
- Required internal reason and separate user-facing explanation reference, if the workflow collects them. Never substitute a notification summary for the actual management reason.
- Changed-field names, previous/new status or assignment references, relevant version values, and protected before/after payload references for committed edits. No before/after claim for changes that did not commit.
- Required authority and the authorization/fresh-authentication result, without credentials or authentication secrets. Capture only policy-permitted request/device context; detailed retention is owned by Audit/Security.
- Related task, transaction, invitation, reconciliation, and notification event references where applicable. Keep original initiating/recording actors separate from new current service owners.

### 16.4 Personal-data masking and protected change history

- Record only fields involved in the event; do not copy an entire profile, request body, uploaded image, or next-of-kin record into every audit entry. Failed validation and duplicate checks record field names and safe categories without logging the attempted raw identity or disclosing the matching person.
- Default audit summaries mask email/phone references, summarize address/next-of-kin changes by field, and identify note/photo changes without embedding their contents. Store photo asset references rather than image bytes; include replacement/removal identity where relevant.
- Preserve actual prior/new note values and other required sensitive change details in protected change-history storage linked to the event. They must not appear in general application logs, notification text, Customer/Agent self-service history, or ordinary report exports. Detailed access follows `audit.view`, field privacy, and the Audit module's eventual masking/reveal policy; this module creates no unrestricted unmask endpoint.
- Names, public references, actor IDs, status changes, and assignment references shown to authorized auditors remain subject to business scope. Internal case/security material retains the extra permissions specified in Section 13; `audit.view` does not disclose credentials, tokens, or grant access to raw recovery evidence.
- Never record passwords, password hashes, authenticator seeds/codes, recovery codes, activation/confirmation token values, session cookies, bearer tokens, payment credentials, or provider secrets, including in exception messages. Audit freshness only as result/time/reference.

### 16.5 Durability, immutability, and failure handling

- Commit a durable canonical audit event with each material business mutation and its protected change-history references. If required durable capture cannot be guaranteed, do not commit that mutation or dispatch its successful notification.
- A remote audit-search/index outage does not prevent urgent suspension when the account change and durable local event can commit together. Retain reliable delivery to the audit store for later indexing; do not equate unavailable search with permission to omit the event.
- Denied/failed attempts may emit their own audit event outside the aborted business transaction. They create no profile, assignment, fee, reservation, or successful-change notice. An audit failure must never convert a denied action into an allowed one or expose target details.
- Audit records are append-only for application users, including Admins. Corrections append a linked explanatory event; they cannot edit/delete the original audit, historical actor, previous note change, or lifecycle timestamp.
- Retried delivery/indexing uses the same immutable audit-event ID and cannot create a second successful domain event. Correlate cascaded handover/proposal-cancellation events without rewriting their source.
- Historical Customer/Agent archival, restoration, suspension, deactivation, or reassignment cannot delete or reattribute audit history. The business record and audit history must remain consistent across versioned concurrent changes.

### 16.6 Access, management history, exports, and retention

- Only an active Admin with `audit.view` can open/search the detailed business-wide audit log. Customers and Agents receive only their permitted operational history/receipts; baseline Admin read access is not detailed audit access.
- Management timelines show only the limited business history allowed by Section 13. Private offboarding case details require `agents.manage`; security evidence/context requires `security.operations.manage`. Listing an event title cannot bypass restrictions on its payload.
- Business-wide report export requires `reports.export` but does not independently authorize detailed audit access or an undefined audit export. No detailed audit-export feature is introduced in this module; Audit owns its later permissions, formats, masking, and access logging.
- Audit/change-payload retention, notification/delivery-log retention, masking/reveal policy, and controlled expiry belong to the detailed Audit/Notifications policy. Initial management workflows expose no manual purge, erase-history, or archive-triggered deletion action.

### 16.7 Audit acceptance criteria

The expanded acceptance checks below support Section 17's indexed requirements and release scenarios:

1. Every committed material management mutation has a durable event with actor, target, effective time, result, required reason, and accurate change references.
2. Failed/denied/stale operations record their actual result without claiming a committed edit, creating partial business records, or logging raw failed identity input.
3. Protected fields, cross-scope access, privilege misuse, and gate-bypass attempts are auditable without revealing authentication secrets or unauthorized target details.
4. Notes and other sensitive prior values are preserved in protected change history while summaries, notifications, ordinary logs, and self-service views remain appropriately masked.
5. Reassignment transfers service responsibility without rewriting original transaction actors, request initiators, or preceding assignment/status history.
6. Admins cannot alter/delete canonical audit events, and retries/index outages do not duplicate successful events or drop durable suspension history.
7. Only `audit.view` grants detailed audit access; management, security, and export permissions retain their separate boundaries.
8. Retention and reveal/export remain explicit owning-module decisions, with no manual purge or history deletion caused by archival/offboarding.

## 17. Initial Scope, Functional Requirements, and Acceptance Criteria

### 17.1 Specification status and initial scope

This scope and acceptance specification is a **draft for review**. The requirement and scenario IDs below provide traceability for the preceding detailed rules; they do not mark any implementation or test as passed. Section-level checklists remain the expanded scenarios for each requirement, and neither this index nor a summary scenario weakens their safeguards.

The initial release targets the single configured business and the existing Customer, Agent, and Admin roles. All operations act on one Customer or Agent at a time, even where offboarding coordinates several independently authorized handovers.

| Area                    | Included in initial scope                                                                                                                                                                                     |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Profiles and identity   | Customer/Agent fields, validation, normalized uniqueness, generated IDs, one optional Customer next-of-kin contact, photos, private notes, and protected change history.                                      |
| Registration            | Agent-only Customer creation, Admin-controlled Agent registration, atomic records, configured fee snapshot/applicability, creation-attempt resolution, and invitation delivery/resend/correction integration. |
| Editing                 | Role/field matrices, ordinary updates, sensitive name/contact workflows, optional-field removal, and versioned save/conflict handling.                                                                        |
| Customer lifecycle      | Active/Inactive/Restricted/Archived status rules, financial holds, authoritative archive gates, identity-preserving restoration to Inactive, and separate account access.                                     |
| Agent lifecycle         | Active/Inactive readiness, account-state eligibility, suspension/access restoration, offboarding/cancellation/completion, and identity-preserving return.                                                     |
| Assignments             | One effective assignment, immediate individual reassignment, scoped access, consistent pending-task handover, historical attribution, and current-Agent recovery verification.                                |
| Screens                 | Scoped directories/profiles, search/filter/sort/pagination, permitted histories and financial summaries, contextual actions, mobile/keyboard access, and safe unavailable states.                             |
| Notifications and audit | In-app/email event routing, deduplication, retry visibility, durable append-only event capture, protected payloads, and existing permission boundaries for audit/security/history access.                     |

### 17.2 Deferred scope and owning-module boundaries

The following are excluded from this module's proposed initial delivery:

- Bulk/CSV registration or updates; multi-select status, archive, restore, and reassignment actions; one-click automatic reassignment of all an Agent's Customers.
- Scheduled, future-dated, backdated, or inactivity-inferred lifecycle/assignment transitions; automatic return to Active after account activation, recovery, or engagement-date changes.
- Public self-registration, custom roles or permissions, role conversion, multiple simultaneous assigned Agents, multi-business/branch management, and duplicate-profile merge workflows.
- Permanent-delete/identity-reuse controls, manual history purge, undefined audit reveal/export, directory CSV export, and newly invented financial/security overrides.
- SMS/WhatsApp/push delivery, notification digests/reminder campaigns, and preference/retention/reveal controls not defined by their owning policies.

Financial posting, plan scheduling, fee calculation/payment/waiver/revenue, withdrawal/reversal approval, reconciliation accounting, statement/report generation, and Authentication credentials/session/recovery mechanics remain with their owning modules. Module 04 integrates their authoritative results, gates, access checks, and responsibility transfers; it does not implement alternative ledgers or silently grant their permissions.

### 17.3 Dependency and delivery gates

| Capability                          | Required authoritative contract before enabling it                                                                                                                                                                                                                                         |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Customer registration               | Authentication account/invitation integration, shared normalization/uniqueness, valid Fees configuration and snapshot/applicability linkage, durable core persistence/audit capture, and post-commit delivery work. A missing Fees contract cannot be treated as a zero-fee configuration. |
| Agent registration and lifecycle    | Authentication activation/MFA/session revocation and state restoration, current Admin grants, durable management/audit state, and invitation delivery integration.                                                                                                                         |
| Financial profile/action sections   | Owning-module balance, plan, request, reservation, and transaction outputs. Unavailable outputs show their safe state and block dependent confirmations; fixtures or an empty unconnected service are not authoritative zero values.                                                       |
| Archival and final offboarding      | Complete obligation/gate queries from relevant owning modules, valid eligible assignment state, and concurrency safeguards against new obligations or recipient ineligibility. Unknown gate results fail closed.                                                                           |
| Reassignment with pending work      | Authoritative current tasks, consistent service ownership, supported handover/version checks, recovery re-verification hooks, and scope enforcement across jobs/exports. Unsupported handover cannot be reported as complete.                                                              |
| Notifications and protected history | Durable event delivery, recipient/permission revalidation, safe templates, protected change storage, and Authentication/Audit access integration. An audit-index outage may be tolerated only when durable canonical capture is guaranteed.                                                |

During staged implementation, a dependent section may show its documented unavailable state while working core features remain usable. Record the unimplemented capability as blocked/deferred in the delivery checklist; do not mark its end-to-end acceptance scenario passed because its controls are hidden. Full module acceptance requires every included requirement and relevant dependency gate to be implemented and verified. Authoritative empty obligations are valid; unavailable obligations are not.

### 17.4 Indexed functional requirements

| ID         | Required behaviour                                                                                                                                                      | Detailed source                  |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------- |
| CAM-FR-001 | Validate Customer required/optional fields, next of kin, references, and photo constraints without silently dropping supplied invalid values.                           | Sections 2.2–2.7                 |
| CAM-FR-002 | Validate Agent fields and proposed Agent phone uniqueness; allow optional fields to be omitted and validate all supplied values.                                        | Sections 7–8                     |
| CAM-FR-003 | Apply shared identity normalization and commit-time uniqueness, including inactive/archived/deactivated identities and privacy-safe conflicts.                          | Sections 2.3, 3.6                |
| CAM-FR-004 | Generate immutable, non-reused public IDs and server attribution/timestamps; profile input cannot overwrite them or financial values.                                   | Sections 2.4, 4.6                |
| CAM-FR-005 | Enforce role prohibitions and granular permissions on every protected operation; Admins never create Customers or perform collections.                                  | Sections 3.1, 14; Module 03      |
| CAM-FR-006 | Create one Customer/profile/account/initial assignment/status history/fee linkage/audit/delivery boundary atomically using current eligible Agent authority.            | Sections 3.2–3.3                 |
| CAM-FR-007 | Atomically register one Inactive Agent with an Invited account and no Customer fee/assignment, then activate through Authentication.                                    | Sections 3.3, 8                  |
| CAM-FR-008 | Obtain a valid applicable fee snapshot from Fees, accept an explicitly configured zero fee, and require reconfirmation if the preview configuration changes.            | Sections 3.2–3.4                 |
| CAM-FR-009 | Bind creation attempts to actor/operation/input, resolve uncertain outcomes safely, and replay committed results only under current read scope.                         | Section 3.5                      |
| CAM-FR-010 | Classify pre-/post-commit failures correctly, prevent partial business records, and keep uploads/errors/retries independent of successful creation.                     | Sections 3.4–3.6                 |
| CAM-FR-011 | Dispatch valid invitations only after commit; distinguish bounded same-challenge delivery retry from authorized token-rotating resend/correction.                       | Section 3.7; Authentication      |
| CAM-FR-012 | Apply Customer/Agent field-edit matrices, including clearing optional fields and rejecting mixed permitted/protected updates as a whole.                                | Sections 4.2, 4.5–4.7            |
| CAM-FR-013 | Enforce name-correction reasons, Customer confirmation, seven-day staff-proposal expiry, and stale/authority-loss invalidation.                                         | Section 4.3                      |
| CAM-FR-014 | Use dedicated pre-/post-activation contact workflows with existing email safeguards, Customer password freshness, and proposed Agent phone MFA step-up.                 | Sections 4.4–4.5; Authentication |
| CAM-FR-015 | Protect internal notes and sensitive history by recipient/field scope and preserve prior values without exposing them in notifications/self-service.                    | Sections 4.6, 16.4               |
| CAM-FR-016 | Commit authorized edits/versioned transitions atomically and reject stale input, lost authority, or changed operational eligibility without partial effects.            | Sections 4.7, 5.6, 9.6, 12.7     |
| CAM-FR-017 | Default Customers to operationally Active/account Invited and keep operational status independent of login access, roles, assignments, and finances.                    | Sections 5.1–5.2                 |
| CAM-FR-018 | Enforce the Customer activity matrix, including Inactive settlement and Restricted payout holds/corrective exceptions without automatic posting.                        | Sections 5.3, 5.5                |
| CAM-FR-019 | Restrict status transitions to `customers.manage`, permitted paths/reasons, and current eligibility; preserve effective history and dated collection context.           | Sections 5.4–5.7                 |
| CAM-FR-020 | Archive only after authoritative settlement gates pass and retain identity, assignments, uniqueness, account state, records, and scoped read access.                    | Sections 6.1–6.3                 |
| CAM-FR-021 | Restore the same archived Customer to Inactive with an eligible assignee, preserving history and resolving discrepancies through authorized owning workflows.           | Sections 6.4–6.6                 |
| CAM-FR-022 | Default Agents Inactive and require complete account/MFA onboarding plus explicit `agents.manage` readiness for operational activation.                                 | Sections 8, 9.1–9.2, 9.4         |
| CAM-FR-023 | Enforce Active/Inactive Agent activities while retaining scoped reads, self-maintenance, assignments, and historical attribution.                                       | Sections 9.3, 9.5                |
| CAM-FR-024 | Distinguish temporary authentication locks, unusable accounts, and assignment eligibility without discarding Authentication's legitimate-session exception.             | Section 9.2; Authentication      |
| CAM-FR-025 | Suspend access immediately with required revocations and preserved business data; restore only through authorized progress-/security-aware management.                  | Sections 10.1–10.3               |
| CAM-FR-026 | Start one offboarding case with atomic Inactive/Suspended effects and accountable case ownership, without requiring prior financial settlement.                         | Section 10.4                     |
| CAM-FR-027 | Deactivate only after all non-archived Customer handovers, Agent cash settlement, pending-work ownership, and queued-work gates pass.                                   | Section 10.5                     |
| CAM-FR-028 | Cancel offboarding without restoring access or undoing handovers, and reactivate a returning Agent under the same identity with separate readiness.                     | Section 10.6                     |
| CAM-FR-029 | Maintain exactly one effective versioned assignment and commit immediate authorized reassignment to an eligible recipient without status/financial changes.             | Sections 11, 12.1–12.2           |
| CAM-FR-030 | Transfer financial-request follow-up without changing original actors, amounts, approvals, reservations, or request state; cancel former-Agent name proposals.          | Section 12.4                     |
| CAM-FR-031 | Require current-Agent re-verification for unapproved recovery while preserving approved account-bound challenges and invitation snapshot/lifetime.                      | Section 12.4; Authentication     |
| CAM-FR-032 | Apply immediate scope loss/gain across screens/jobs/downloads; keep historical collections/cash obligations with their recording Agent and masked own-settlement scope. | Sections 12.3, 12.5–12.7         |
| CAM-FR-033 | Provide scoped Customer/Agent directories with documented defaults, status filters, normalized search, stable sort, counts, and pagination.                             | Sections 13.1–13.4               |
| CAM-FR-034 | Provide profile sections, authoritative PRD financial summaries, protected histories, and contextual actions matching current role/status/permission.                   | Sections 13.5–13.7               |
| CAM-FR-035 | Distinguish empty/loading/unavailable/revoked states and provide accessible mobile/keyboard interactions without guessed values or essential horizontal scrolling.      | Section 13.8                     |
| CAM-FR-036 | Route/deduplicate scoped notifications under the event/channel matrix, with bounded retries and delivery failures independent of business commits.                      | Section 15                       |
| CAM-FR-037 | Capture immutable durable audit events and protected change history, redact secrets, and enforce distinct audit/management/security/export boundaries.                  | Section 16                       |
| CAM-FR-038 | Honor initial-scope limits and authoritative dependency contracts; unavailable or unsupported owning-module state cannot pass a gate or invent financial authority.     | Sections 17.1–17.3, 19           |

### 17.5 Numbered acceptance scenarios

Each scenario must cover the stated outcome using the detailed field, permission, status, and workflow rules above. These are release criteria, not claims that test automation already exists.

| ID         | Requirement trace                              | Scenario and observable outcome                                                                                                                                                                                                                                            |
| ---------- | ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| CAM-AC-001 | CAM-FR-001                                     | Register a Customer with only required input and then with valid optional input, including a Unicode/single-word name; generated metadata/assignment appears correctly and omitted optional fields remain absent.                                                          |
| CAM-AC-002 | CAM-FR-002                                     | Register an Agent with required fields only; optional engagement data remains optional, and equivalent Agent phone numbers conflict only within the proposed Agent uniqueness scope.                                                                                       |
| CAM-AC-003 | CAM-FR-001, CAM-FR-003                         | Try equivalent normalized email/phone/reference variants and inactive/archived/deactivated matches; at most one unique identity exists, with no provider alias rewriting or unauthorized match disclosure.                                                                 |
| CAM-AC-004 | CAM-FR-001, CAM-FR-002, CAM-FR-010             | Exercise missing required fields, length boundaries, incomplete next of kin, invalid number, corrupt/oversized/out-of-dimension photo, and optional removal; invalid creation commits nothing and valid correction succeeds without silent omission.                       |
| CAM-AC-005 | CAM-FR-004, CAM-FR-012, CAM-FR-016             | Submit an allowed address change together with forged ID, actor, assignment, role, timestamp, or balance; reject the whole update, preserving all prior values.                                                                                                            |
| CAM-AC-006 | CAM-FR-005                                     | Try Customer creation/collection as Admin, Agent approval/reassignment, self-status changes, and protected edits without the required grant; deny every prohibited action with no business effect.                                                                         |
| CAM-AC-007 | CAM-FR-006, CAM-FR-017                         | An eligible Agent registers a unique Customer; exactly one complete core boundary commits, initial assignment is the creating Agent, status is Active/account Invited, and collection eligibility does not wait for Customer activation.                                   |
| CAM-AC-008 | CAM-FR-007, CAM-FR-022                         | An authorized Admin registers an Agent; one core boundary commits Inactive/account Invited with no Customer fee or assignment, and MFA onboarding remains Agent-controlled.                                                                                                |
| CAM-AC-009 | CAM-FR-006, CAM-FR-007, CAM-FR-010, CAM-FR-037 | Inject failure at each required profile/account/assignment/fee/audit/delivery-intent persistence stage; commit no partial usable records and dispatch no email. Any failure audit accurately describes non-commit.                                                         |
| CAM-AC-010 | CAM-FR-005, CAM-FR-006, CAM-FR-007, CAM-FR-016 | Revoke the Admin grant or change Agent account/readiness before registration commits; creation fails without records, fee obligation, or invitation job.                                                                                                                   |
| CAM-AC-011 | CAM-FR-008                                     | Compare valid positive fee, authoritative configured zero fee, missing/unavailable rules, and a changed preview version; valid snapshots commit once, missing rules block, and changed preview requires reconfirmation.                                                    |
| CAM-AC-012 | CAM-FR-009                                     | Double-click one submission, retry concurrently, and lose the successful response; resolve the same attempt to one authorized creation result with no additional fee, assignment, token, or creation email.                                                                |
| CAM-AC-013 | CAM-FR-003, CAM-FR-009                         | Submit separate attempts/actors for the same normalized identity concurrently; only one registers, and the losing actor receives privacy-safe conflict handling.                                                                                                           |
| CAM-AC-014 | CAM-FR-009, CAM-FR-010                         | Reuse an attempt with changed input and interrupt an unknown-outcome submission; changed input conflicts, uncertain status prevents fresh creation, and definitive resolution enables the correct next action.                                                             |
| CAM-AC-015 | CAM-FR-009, CAM-FR-032                         | Reassign the created Customer before the creator replays their attempt; replay cannot reveal out-of-scope Customer data or register a replacement identity.                                                                                                                |
| CAM-AC-016 | CAM-FR-010, CAM-FR-011, CAM-FR-036             | Reject or time out invitation dispatch after commit; registration remains successful, its fee snapshot persists, and delivery is Failed or genuinely Pending/uncertain rather than failed registration.                                                                    |
| CAM-AC-017 | CAM-FR-011                                     | Restart/retry delivery, rotate a challenge through authorized resend, correct invited email, and expire/cancel a generation; dispatch only currently valid challenges, preserve core records/snapshot, and enforce resend states/rates/lifetimes.                          |
| CAM-AC-018 | CAM-FR-012, CAM-FR-015                         | Exercise each actor's permitted fields, explicit optional clears, private-note access, and archived operational read-only forms; only allowed edits commit, and private sections are absent from unauthorized responses.                                                   |
| CAM-AC-019 | CAM-FR-013                                     | Propose an activated Customer name correction; effective name stays unchanged until fresh-password confirmation. Reject/expire after seven days/cancel or remove requester authority and verify no change; self-name correction invalidates stale proposals.               |
| CAM-AC-020 | CAM-FR-014                                     | Correct an invited email, activate concurrently, and attempt post-activation staff overwrite; pre-activation correction cannot race activation, and active email uses Authentication's existing confirmation/recovery safeguards.                                          |
| CAM-AC-021 | CAM-FR-003, CAM-FR-014                         | Change Customer phone with/without fresh password and Agent phone with/without proposed password/MFA step-up; only authorized unique normalized changes commit, with required notices and no invented SMS/sign-in factor.                                                  |
| CAM-AC-022 | CAM-FR-015, CAM-FR-037                         | Replace/clear notes and other sensitive fields; protected historical values persist, unauthorized summaries/messages omit them, and detailed access observes audit and field-privacy rules.                                                                                |
| CAM-AC-023 | CAM-FR-016                                     | Open two edit/transition forms and commit one before the other; stale versions or intervening permission/assignment changes reject the second operation without overwriting newer data.                                                                                    |
| CAM-AC-024 | CAM-FR-017                                     | Pair each Customer operational status with permitted/Invited/Suspended account states; operational transitions preserve authentication access/credentials/sessions, and account activation does not clear a hold or archival.                                              |
| CAM-AC-025 | CAM-FR-018                                     | Make a Customer Inactive with existing savings; block new plans and every contribution pattern, but allow eligible assigned-Agent withdrawal initiation and authorized settlement.                                                                                         |
| CAM-AC-026 | CAM-FR-018                                     | Restrict a Customer; block new contributions/withdrawals, approvals and unposted payouts, retain read/profile access, and allow only separately authorized corrective reversal/historical reconciliation exceptions.                                                       |
| CAM-AC-027 | CAM-FR-019                                     | Attempt every allowed/disallowed Customer transition with/without `customers.manage`, reason, and eligible assignee where required; only valid confirmed transitions append effective history.                                                                             |
| CAM-AC-028 | CAM-FR-018, CAM-FR-019                         | Restrict an approved-but-unpaid withdrawal and later lift the hold; preserve request/reservation history, hold posting immediately, and require fresh owning-workflow validation instead of automatic payout.                                                              |
| CAM-AC-029 | CAM-FR-020, CAM-FR-038                         | Independently fail each archival gate, including open/paused plans, liability, reservations, fee/refund, request, reconciliation, queued obligation, and unavailable validation; none can be bypassed by zero-looking UI values or rounding.                               |
| CAM-AC-030 | CAM-FR-020                                     | Archive a settled Active/Inactive Customer with an unavailable current Agent; retain identity, uniqueness, account access state, effective assignment, statements and financial history, and cancel operational name proposals.                                            |
| CAM-AC-031 | CAM-FR-021                                     | Restore an Archived Customer with eligible/ineligible current assignee and with/without separate reassignment permission; only an eligible identity-preserving Archived → Inactive restoration commits, with no new fee, plan, or login activation.                        |
| CAM-AC-032 | CAM-FR-021, CAM-FR-037                         | Discover an archived discrepancy and perform repeated archive/restore episodes; retain original events, restore for authorized correction without concealing the discrepancy, and reject stale episode retries.                                                            |
| CAM-AC-033 | CAM-FR-022                                     | Complete Agent email/MFA activation; operational status stays Inactive until an authorized Admin explicitly confirms readiness, and an open offboarding case prevents operational activation.                                                                              |
| CAM-AC-034 | CAM-FR-023                                     | Make an Agent Inactive with assigned Customers; retain permitted reads/own maintenance and all Customer state, but block creation, assignment receipt, Customer edits/invitations/requests/plans and collections.                                                          |
| CAM-AC-035 | CAM-FR-024                                     | Temporarily lock one authentication path while a legitimate session exists; preserve its permitted assigned-Customer operations but reject new assignment receipt. Suspended/Deactivated accounts allow no application access.                                             |
| CAM-AC-036 | CAM-FR-023, CAM-FR-032                         | Reassign Customers during Agent inactivity and later reactivate the Agent; reactivation returns only current scope and never reclaims former Customers or replays cancelled proposals/jobs.                                                                                |
| CAM-AC-037 | CAM-FR-025                                     | Suspend an Agent with unresolved cash and no replacement available; immediately revoke all sessions/trusted devices/resume access, block uncommitted work, and preserve assignments and finances.                                                                          |
| CAM-AC-038 | CAM-FR-025, CAM-FR-024                         | Restore an invited, MFA-incomplete, or fully activated suspended Agent; derive proper access from onboarding progress, enforce proposed freshness/security resolution, and keep old sessions revoked. Reset/recovery/unlock alone cannot restore access.                   |
| CAM-AC-039 | CAM-FR-026                                     | Start offboarding twice or interrupt its response; maintain one open case with atomic Inactive/Suspended effects, intact assignment/attribution, and accountable case ownership.                                                                                           |
| CAM-AC-040 | CAM-FR-027                                     | Attempt final deactivation with non-archived Active/Inactive/Restricted assignments, unresolved Agent cash, unsupported handover, or newly ineligible replacement; fail completion and retain suspension until all authoritative gates pass.                               |
| CAM-AC-041 | CAM-FR-027, CAM-FR-030                         | Complete documented pending-request handovers and cash settlement while retaining archived assignments; deactivation may finish without falsifying request completion, changing original actors, or allowing former-Agent access.                                          |
| CAM-AC-042 | CAM-FR-028                                     | Cancel an open case and later re-engage a completed/deactivated Agent; preserve previous cases/handovers, keep identity and Inactive readiness, and require separate account restoration/onboarding and activation.                                                        |
| CAM-AC-043 | CAM-FR-029                                     | Reassign from an unavailable Agent and race two Admin submissions; one versioned replacement commits with matching end/start time, no assignment gap/overlap, and unchanged Customer/account/financial state.                                                              |
| CAM-AC-044 | CAM-FR-030                                     | Transfer pending withdrawal/reversal follow-up; preserve initiators, amounts, decisions, evidence history, reservations and state, and do not require duplicate initiation solely because assignment changed.                                                              |
| CAM-AC-045 | CAM-FR-031                                     | Transfer unapproved versus already-approved recovery and an existing invitation; require current-Agent verification before pending recovery approval while preserving valid approved challenges and invitation snapshot/expiry.                                            |
| CAM-AC-046 | CAM-FR-030, CAM-FR-032                         | Reassign with an old Agent name proposal/open edit/queued collection; cancel or deny old work, retain prior posted contributions, and never impersonate the replacement or backdate history.                                                                               |
| CAM-AC-047 | CAM-FR-032                                     | Read Customer-linked notifications/jobs/prepared exports/downloads after scope loss; deny old access, preserve minimal removal receipt/masked own-settlement summaries, and keep cash liability with the recording Agent.                                                  |
| CAM-AC-048 | CAM-FR-018, CAM-FR-029, CAM-FR-038             | Reassign Restricted/Archived Customers and fail a required handover integration; status limits persist, unsupported transfer fails atomically, and reassignment cannot finish unrelated cash settlement or restore participation.                                          |
| CAM-AC-049 | CAM-FR-033                                     | Search/filter/paginate as Customer, assigned Agent and Admin, including unauthorized direct links; counts/options/suggestions/rows stay scoped and no Agent directory or cross-Customer existence leaks to unauthorized viewers.                                           |
| CAM-AC-050 | CAM-FR-033                                     | Apply default/archive/deactivated filters, date ranges, names, 25/50/100 pagination, sorting and profile/back navigation; stable results retain permitted state and clearly distinguish account/operational/invitation status.                                             |
| CAM-AC-051 | CAM-FR-034, CAM-FR-038                         | Display a new profile, existing history with reversals, and unavailable financial sources; PRD summaries match authoritative owning outputs, recent entries do not determine lifetime balance, and unknown values never become zero.                                       |
| CAM-AC-052 | CAM-FR-034, CAM-FR-035                         | Use desktop/mobile and keyboard/touch with each permission/status combination; sections/controls remain readable and accessible, private responses stay omitted, and blocked actions explain a permitted next step.                                                        |
| CAM-AC-053 | CAM-FR-035, CAM-FR-016                         | Exercise loading, empty scope, no matches, section failure and revoked access; distinguish outcomes, retry reads safely, and clear stale unauthorized content without repeating mutations.                                                                                 |
| CAM-AC-054 | CAM-FR-036                                     | Emit each notification-family event and repeat/retry/switch recipient scope; deliver only matrix-permitted payloads once logically, suppress private/old-scope contents, and retain safe current-recipient issues.                                                         |
| CAM-AC-055 | CAM-FR-011, CAM-FR-036                         | Fail/uncertainly accept operational and invitation delivery; apply bounded retries, distinct resend rules, safe diagnostics and read-state semantics without rollback, replay, or unchanged repeated alerts.                                                               |
| CAM-AC-056 | CAM-FR-037                                     | Verify committed/failed/denied/conflicting changes and protected before/after references; events accurately identify actor/outcome and never contain raw failed identity, credentials, tokens, or unrestricted private-note payloads.                                      |
| CAM-AC-057 | CAM-FR-037, CAM-FR-038                         | Try audit modification/read/export with baseline and split grants, fail remote indexing and durable local capture separately; preserve append-only/private access boundaries, tolerate indexing only with durability, and never silently drop a mutation's required audit. |

### 17.6 Acceptance fixtures and evidence

Verification must use distinct identities rather than one all-permission Admin for every scenario:

- A Customer and at least two Agents with different effective Customer assignments; Agents in initial-onboarding, operationally Active/Inactive, temporarily locked, Suspended, and Deactivated conditions.
- A baseline-read Admin and Admins with independently granted `customers.manage`, `customers.reassign`, `agents.manage`, `withdrawals.review`, `reversals.review`, `reconciliation.manage`, `security.operations.manage`, `audit.view`, and `reports.export`; include revocation mid-request.
- Customers in each operational status, invited/activated accounts, partial/advance contributions, closed/open/paused plan examples, authoritative zero/non-zero/reserved balances, outstanding registration fees, held/unposted requests, and archived/restored histories.
- Pending/approved recovery, invitation delivery failure/unknown acceptance/expiry/replacement, unavailable owning sources, lost responses, persistence failures, concurrent edits/assignments, and queued out-of-scope work.

Acceptance evidence records the scenario ID, requirement IDs, fixture/authority context, observed response/state, audit/notification references where relevant, and Passed/Failed/Blocked status. Failure/concurrency scenarios verify persisted state and financial invariants as well as visible messages; an attractive disabled button alone is not proof of server enforcement. Mobile/keyboard scenarios additionally record interaction evidence.

Contract doubles may verify failure handling during development but do not substitute for end-to-end authoritative integrations at release. Unknown delivery is not proof of failed registration, and skipped scenarios are Blocked rather than Passed.

## 18. Confirmed Key Decisions

The following key product decisions have been reviewed and confirmed as the binding baseline for Module 04 implementation:

- The required and optional profile fields, limits, next-of-kin structure, and photo rules in Sections 2 and 7 are confirmed.
- The `CUS-000001` and `AGT-000001` public-reference formats are confirmed.
- The field-editing matrices in Section 4, including staff name-confirmation proposals, post-activation phone self-service, internal-note visibility, and Agent self-service address and photo edits are confirmed.
- The Customer status catalogue (`Active`, `Inactive`, `Restricted`, `Archived`) and financial-hold behaviour in Section 5, and Agent `Active`/`Inactive` statuses, Admin readiness step, Inactive activity limits, and temporary-lock assignment rule in Section 9 are confirmed.
- The archival settlement gates, outstanding registration-fee handling, retained access, no-delete policy, and restoration workflow in Section 6 are confirmed.
- The Agent suspension/access-restoration safeguards, offboarding gates, archived-assignment exception, cancellation, and return-to-service rules in Section 10 are confirmed.
- The reassignment consequences and pending-task handover rules in Section 12, including recovery re-verification, invitation continuity, historical cash attribution, and former-Agent notification/export scope are confirmed.
- The atomic-registration boundary, fee-version reconfirmation, durable creation-attempt bindings, uncertain-outcome handling, and automatic invitation-delivery retry policy in Section 3 are confirmed.
- The directory columns, status filters, search/sort/pagination defaults, profile sections, contextual actions, and safe loading/error/mobile states in Section 13 are confirmed.
- The proposed Agent phone uniqueness within Agent profiles, without cross-role phone uniqueness, is confirmed.
- The individual-operation initial scope and bulk/scheduled/import/export exclusions in Section 17, together with its indexed requirements, acceptance scenarios, and dependency delivery gates are confirmed.
- The notification event/channel matrix, operational retries, scope-sensitive recipients, and delivery visibility in Section 15, and audit capture/masking/durability/access rules in Section 16 are confirmed. Detailed retention, reveal, preferences, and audit export remain with their owning policies.

The core principle remains strictly confirmed: operational profile status is independent from authentication account state. Keeping these separate prevents actions such as marking a Customer inactive from accidentally removing login access or altering financial history.

## 19. Boundaries With Other Modules

- **Authentication** owns account activation, invitation security, credentials, MFA, sessions, and recovery mechanics.
- **Roles and Permissions** owns role capabilities, Admin permissions, and resource-level authorization.
- **[Fees and Deductions](./05-fees-and-deductions.md)** owns registration/plan charge rules, fee snapshots, obligations, settlement/waiver, deductions, and business fee earnings. External fee receipts and their remittance integrate with Module 07.
- **[Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md)** owns plan terms, schedules, creation, amendments, completion, closure, and renewal.
- **[Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md)** owns contribution recording/allocation, actual card projections, shared financial-posting/balance contracts, Agent remittance, and reconciliation. It consumes Module 05 charge policy and Module 06 schedules rather than inventing alternative rules.
- **Withdrawals and Reversals** own financial requests, approvals, posting, and accounting effects.
- **Reporting and Audit** own detailed output formats, retention, masking, and exports.

Customer and Agent Management owns the operational records and relationships these modules use. It must preserve their history and must not silently change financial balances or completed transactions.
