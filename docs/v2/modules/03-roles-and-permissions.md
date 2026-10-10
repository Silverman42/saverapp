# Roles, Permissions, and Authorization

**Product version:** 2.0  
**Module status:** Draft  
**Depends on:** [User Types and Access Model](./01-user-types.md), [Authentication and Account Access](./02-authentication.md)

## 1. Purpose

This module defines what an authenticated Customer, Agent, or Admin is authorized to do.

It converts the high-level role boundaries in the User Types module into enforceable permissions, resource scopes, separation-of-duty rules, and permission-management workflows. It also defines the meaning of an **authorized Admin** wherever that term is used by the Authentication module.

Authentication proves the identity of a user. Authorization must separately verify:

1. That the account and session are currently allowed to act.
2. That the user's role is eligible to perform the requested action.
3. That an Admin holds any required granular permission.
4. That the requested record falls within the user's current resource scope.
5. That no separation-of-duty or temporary security restriction prevents the action.

Passing one check must never bypass the others.

## 2. Scope

This module defines:

- Fixed role capabilities for Customers and Agents.
- The baseline access shared by active Admins.
- The closed catalogue of granular Admin permissions.
- Permission assignment, change, and revocation workflows.
- Resource-level access for Customers, assigned Agents, and Admins.
- Separation-of-duty and self-management restrictions.
- Immediate authorization refresh requirements.
- Authorization-related notifications and audit requirements.

This module does not define:

- Passwords, MFA, invitations, sessions, or account recovery mechanics.
- Customer, Agent, plan, collection, withdrawal, reversal, fee, or reconciliation workflow details.
- The accounting effect of any financial action.
- Custom roles or user-created permissions.
- Platform-level or multi-business administration.

Those behaviours belong to their respective Version 2 modules.

## 3. Terminology

### 3.1 Role

A role is the user's single account type: **Customer**, **Agent**, or **Admin**.

Roles establish the broad access boundary. An account has exactly one role, and Version 2 does not support a user switching between roles within one account.

### 3.2 Role capability

A role capability is authority inherent to the Customer or Agent role. It is not granted or removed individually.

Examples include a Customer viewing their own transactions and an Agent recording a collection for an assigned Customer.

### 3.3 Admin permission

An Admin permission is a predefined, individually assignable authority that allows an Admin to perform a protected administrative action.

Holding the Admin role does not automatically grant every Admin permission.

### 3.4 Resource scope

Resource scope identifies the records on which a user may exercise a capability.

- A Customer's scope is their own account and financial records.
- An Agent's scope is their currently assigned Customers and the operational records required for those assignments.
- An Admin's scope is the configured thrift business, subject to granular permissions for protected actions.

### 3.5 Authorized Admin

An **authorized Admin** is an active Admin who:

- Holds the exact permission required for the action.
- Has completed any required fresh authentication.
- Is not prevented by a post-recovery or other temporary security restriction.
- Is not acting on their own account where self-action is prohibited.
- Satisfies any required separation-of-duty rule.

The Admin role by itself is not sufficient where this module assigns a granular permission.

### 3.6 Permission version

Each Admin account has a server-maintained permission version. The version changes whenever the Admin's grants or temporary authorization restrictions change.

Sessions may cache the version for performance, but the server must reject stale authorization and load the current permission state before allowing an action.

## 4. Authorization Principles

### 4.1 Default deny

Every protected action is denied unless the system can establish an explicit role capability or Admin permission and a valid resource scope.

New routes, actions, and background jobs must be inaccessible until an authorization rule has been deliberately assigned.

### 4.2 Server-side enforcement

Authorization must be enforced by the server for every protected request. Hiding a button, navigation item, or page in the interface is not an authorization control.

### 4.3 Least privilege

Users receive only the access needed for their responsibilities.

- Customer and Agent access is fixed and narrowly scoped by role.
- Admins receive baseline read access plus only the protected action permissions assigned to them.
- New Admin permissions are not automatically granted to existing Admins, except through a reviewed seed or migration decision.

### 4.4 Explicit grants

Admin permissions are stored as explicit grants from the predefined catalogue. Version 2 must not use a wildcard such as `all` as the durable source of authority.

This prevents a future permission from being silently inherited by an Admin who was never reviewed for it.

### 4.5 One configured business

Version 2 manages one configured thrift business. Admin business-wide access means access within that business only. There is no platform administrator, tenant selector, or cross-business authorization path.

### 4.6 No financial-history override

No role or permission allows a user to permanently delete completed financial transactions or silently rewrite financial history.

Corrections must use the approved reversal, adjustment, or counter-entry workflow and remain attributable.

### 4.7 Role prohibitions override permissions

An Admin permission cannot override an action reserved for another role.

In particular:

- An Admin cannot perform or record collections.
- An Agent cannot approve withdrawals or transaction reversals.
- A Customer cannot create or alter financial transactions.
- No permission allows one Customer to access another Customer's records.

## 5. Customer Authorization

Customer capabilities are fixed. They cannot be individually granted to or removed from an active Customer.

An active Customer may:

- Access the Customer dashboard.
- View their own profile and account status.
- View their own thrift plans and digital thrift cards.
- View contributions, missed days, partial payments, advance payments, and multiple-day payments recorded for them.
- View their own withdrawals, fees, deductions, reversals, and adjustments.
- View their current balance and transaction history.
- View or obtain their own statement when the statement feature is available.
- Manage their own password, email address, phone number, authenticator-independent security information, sessions, and recovery information through the approved authenticated workflows.
- View notifications addressed to them.

A Customer may not:

- View another Customer's profile, plan, balance, transaction, statement, or notification.
- Add a Customer or Agent.
- Create, edit, approve, reverse, or delete a financial transaction.
- Change their assigned Agent.
- Change their own role, account status, registration-fee snapshot, or financial history.
- Access Agent or Admin dashboards, reports, configuration, or audit records.

Customer-management modules may define which non-financial profile fields a Customer can propose or edit. They must not expand the financial or cross-Customer access boundary established here.

## 6. Agent Authorization

Agent capabilities are fixed. Version 2 does not support assigning additional permissions to individual Agents.

### 6.1 Agent capabilities

An active Agent may:

- Access the Agent dashboard and collection workspace.
- View Customers currently assigned to them.
- Add a Customer and become that Customer's initial assigned Agent.
- Manage the invitation of a Customer currently assigned to them.
- View the full profile, plans, balances, and transaction history of an assigned Customer.
- Update permitted operational and profile information for an assigned Customer.
- Create and manage thrift plans for an assigned Customer within the plan lifecycle rules.
- Perform and record collections for an assigned Customer.
- View their own collection activity and reconciliation status.
- Initiate a withdrawal request for an assigned Customer.
- Initiate a transaction-reversal request for a transaction they are permitted to access.
- Review the operational history needed to resolve routine discrepancies with an assigned Customer.
- Initiate Customer assisted recovery for a currently assigned Customer after completing the required identity-verification procedure.
- Manage their own authentication, session, and recovery settings through the approved workflows.

Detailed modules may narrow these capabilities according to record status. For example, a completed plan may no longer be editable even though an Agent can manage active plans.

### 6.2 Agent prohibitions

An Agent may not:

- View or manage a Customer who is not currently assigned to them.
- Approve or reject a withdrawal request.
- Approve or reject a transaction-reversal request.
- Approve a Customer assisted-recovery request.
- Reassign Customers between Agents.
- Register, suspend, deactivate, or recover another Agent.
- Add, manage, or recover an Admin.
- View business-wide financial totals, another Agent's performance, or unrestricted business reports.
- Change business configuration, fee rules, permissions, or security policy.
- Permanently delete completed financial transactions.

### 6.3 Assignment scope

Agent access is determined by the current effective Customer assignment, not by historical interaction with the Customer.

- Access begins when an assignment becomes effective.
- Access ends immediately when reassignment becomes effective.
- The previous Agent retains attribution on historical actions but not continuing access to the Customer.
- The new Agent receives access to the Customer's existing profile, plans, balances, and history required to continue service.
- A Customer record identifier must not be treated as proof that the requesting Agent is assigned to that Customer.
- Background jobs and exports requested by an Agent must re-check assignment scope when the job executes.

The Customer and Agent Management module will define reassignment requests, reasons, notifications, and lifecycle details.

## 7. Admin Authorization Model

### 7.1 Baseline Admin access

Every active Admin receives the following read-only access without an additional granular permission:

- Access to the Admin dashboard.
- View the configured business profile.
- View Customer and Agent profiles and statuses across the business.
- View Customer assignments.
- View thrift plans, balances, and transaction histories across the business.
- View business-wide financial summaries and read-only reports.
- View the Admin's own permissions, authentication settings, sessions, and recovery status.

Baseline access does not authorize a protected mutation, approval, export, security operation, or access to the detailed audit log.

The `Yes` values shown for Admins in the User Types capability baseline identify capabilities available to the Admin role. Where a capability appears in the granular catalogue below, an individual Admin must also hold its permission before acting.

### 7.2 Granular permission catalogue

Version 2 uses the following closed permission catalogue.

| Permission code              | Display name                  | Authority granted                                                                                                                                                                                                                    |
| ---------------------------- | ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `admins.manage`              | Admin management              | Invite Admins; assign initial Admin permissions; change another Admin's permissions; suspend, reactivate, or deactivate another Admin; manage Admin invitations; and perform the Admin-recovery actions assigned to this permission. |
| `agents.manage`              | Agent management              | Register, invite, update, suspend, reactivate, deactivate, and manage invitation actions for Agents. Agent assisted recovery remains a security operation.                                                                           |
| `customers.manage`           | Customer management           | Update existing Customer profiles and statuses business-wide and manage existing Customer invitations. It does not allow an Admin to create a Customer.                                                                              |
| `customers.reassign`         | Customer reassignment         | Reassign a Customer from one Agent to another through the approved reassignment workflow.                                                                                                                                            |
| `withdrawals.review`         | Withdrawal approval           | Review, approve, or reject Customer withdrawal requests. It does not record a collection or bypass withdrawal validation.                                                                                                            |
| `reversals.review`           | Transaction-reversal approval | Review, approve, or reject transaction-reversal requests. It does not silently edit the original transaction.                                                                                                                        |
| `fees.manage`                | Fee management                | Configure fee rules and perform the Admin fee actions defined by the Fees module. It does not merge fee earnings with Customer liabilities.                                                                                          |
| `deductions.manage`          | Deduction management          | Perform the Admin deduction actions defined by the Deductions module, subject to confirmation and audit rules.                                                                                                                       |
| `reconciliation.manage`      | Reconciliation management     | Review Agent collection submissions, record reconciliation outcomes, and resolve reconciliation exceptions through the approved workflow.                                                                                            |
| `business.settings.manage`   | Business configuration        | Update general and operational business settings. Fresh authentication for publication was removed on 8 October 2026.                                                                                                                |
| `security.operations.manage` | Security operations           | View non-secret security events, review Customer assisted recovery, manage permitted authentication locks, and perform the Agent security operations assigned to this permission.                                                    |
| `audit.view`                 | Audit-log access              | Search and view business audit events, subject to masking and export restrictions.                                                                                                                                                   |
| `reports.export`             | Report export                 | Export business reports and statements containing business-wide or multi-Customer information.                                                                                                                                       |

Permission codes are stable identifiers. Display names may change without changing the stored code.

### 7.3 Effective capability matrix

| Capability                             | Customer                                | Agent                                     | Admin                        |
| -------------------------------------- | --------------------------------------- | ----------------------------------------- | ---------------------------- |
| View Customer profile and finances     | Own records                             | Assigned Customers                        | Business-wide baseline       |
| Create a Customer                      | No                                      | Yes; Agent becomes assignee               | No                           |
| Manage an existing Customer            | Limited approved self-service           | Assigned Customers; permitted fields      | `customers.manage`           |
| Reassign a Customer                    | No                                      | No                                        | `customers.reassign`         |
| Create or manage a thrift plan         | No                                      | Assigned Customers                        | No                           |
| Perform and record collections         | No                                      | Assigned Customers                        | No                           |
| View collection activity               | Contributions recorded for the Customer | Own collection activity                   | Business-wide baseline       |
| Initiate withdrawal processing         | No                                      | Assigned Customers                        | No                           |
| Approve or reject a withdrawal         | No                                      | No                                        | `withdrawals.review`         |
| Initiate a transaction reversal        | No                                      | Accessible assigned-Customer transactions | No                           |
| Approve or reject a reversal           | No                                      | No                                        | `reversals.review`           |
| Manage Agents                          | No                                      | No                                        | `agents.manage`              |
| Manage Admins and Admin permissions    | No                                      | No                                        | `admins.manage`              |
| Manage fee rules                       | No                                      | No                                        | `fees.manage`                |
| Manage deductions                      | No                                      | No                                        | `deductions.manage`          |
| Manage reconciliation                  | No                                      | View own status                           | `reconciliation.manage`      |
| Update business configuration          | No                                      | No                                        | `business.settings.manage`   |
| Perform privileged security operations | No                                      | Initiate assigned-Customer recovery only  | `security.operations.manage` |
| View detailed audit log                | No                                      | No                                        | `audit.view`                 |
| View business-wide reports             | No                                      | No                                        | Business-wide baseline       |
| Export business-wide reports           | No                                      | No                                        | `reports.export`             |

The detailed domain modules may add status and workflow prerequisites, but they cannot grant a role an action marked **No** without an explicit revision to this module and the User Types baseline.

### 7.4 Permission boundaries

Granular permissions do not imply one another unless explicitly stated.

- `admins.manage` does not grant `security.operations.manage` for Customer or Agent security cases.
- `agents.manage` does not grant `customers.reassign`.
- `customers.manage` does not allow Customer creation, collection recording, or reassignment.
- `withdrawals.review` does not grant `reversals.review`.
- `fees.manage` does not grant `deductions.manage`.
- `audit.view` does not grant `reports.export`.
- `business.settings.manage` does not grant Admin or security management.

The interface may group permissions for convenience, but selecting a group must resolve to visible, explicit individual grants.

### 7.5 Permission catalogue changes

Adding, renaming, splitting, or retiring a permission is a controlled product and data-migration change.

- A new permission is denied to every Admin until explicitly granted or assigned through a reviewed migration.
- A retired permission must be removed from active grants without deleting its historical audit records.
- A split permission must not automatically broaden existing access.
- Unknown permission codes must be ignored for authorization and reported for investigation.

## 8. Authentication-to-Permission Mapping

The following table resolves authorization terms used by the Authentication module.

| Authentication action                                 | Required role or permission  | Additional restriction                                                                                       |
| ----------------------------------------------------- | ---------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Invite or manage an Admin invitation                  | `admins.manage`              | Fresh password-and-MFA authentication; actor cannot be under the post-recovery Admin-management restriction. |
| Assign or change Admin permissions                    | `admins.manage`              | Fresh authentication; no self-change; final-capable-Admin safeguards apply.                                  |
| Suspend, reactivate, or deactivate an Admin           | `admins.manage`              | Fresh authentication; no self-action; final-active-Admin safeguards apply.                                   |
| Initiate or approve another Admin's assisted recovery | `admins.manage`              | No self-approval; distinct approvers; two approvers when two eligible Admin approvers are available.         |
| Register or manage an Agent invitation                | `agents.manage`              | Agent must be active before receiving Customer assignments.                                                  |
| Initiate or control Agent assisted recovery           | `security.operations.manage` | Actor must not learn or set the Agent's password, authenticator secret, or recovery codes.                   |
| Manage an existing Customer invitation as an Admin    | `customers.manage`           | Admin still cannot create the Customer.                                                                      |
| Approve or reject Customer assisted recovery          | `security.operations.manage` | The initiating Agent cannot approve the request.                                                             |
| View authentication-abuse and lockout context         | `security.operations.manage` | Submitted credentials and authentication secrets remain hidden.                                              |
| Manually unlock a Customer or Agent                   | `security.operations.manage` | Identity verification and a recorded reason are required.                                                    |
| Manually unlock another Admin                         | `security.operations.manage` | No self-unlock; final-Admin rules remain effective.                                                          |
| Receive privileged security notifications             | `security.operations.manage` | Admin must be active at notification time.                                                                   |
| Change security-sensitive business configuration      | `business.settings.manage`   | Fresh password-and-MFA authentication is required.                                                           |

Where the Authentication module specifically requires `admins.manage`, `security.operations.manage` alone is not a substitute.

## 9. Admin Permission Provisioning

### 9.1 Seeded first Admin

The seeded first Admin receives every permission in the current catalogue.

The grants must be recorded with `system_seed` as their source. After first access, the Admin may distribute duties to additional Admins, but the safeguards in this module must always preserve at least one active Admin capable of Admin management.

### 9.2 Additional Admin

When inviting another Admin:

1. The inviting Admin is signed in with a confirmed authenticator. _(Repeated fresh password-and-MFA authentication was removed on 8 October 2026; see Authentication Section 8.5.)_
2. The system verifies `admins.manage` and any active temporary restrictions.
3. The inviting Admin enters the required identity information.
4. The inviting Admin selects initial permissions from the predefined catalogue.
5. The system shows a plain-language summary of the access being granted.
6. The inviting Admin confirms the invitation and records a reason or responsibility description.
7. The system creates the explicit permission grants and invitation atomically.
8. The invited Admin cannot use the grants until activation and mandatory MFA setup are complete.

An invited Admin may receive no granular permissions and operate with baseline read-only Admin access after activation. The interface should make this consequence clear before the invitation is sent.

Only an Admin who holds `admins.manage` may grant `admins.manage` to another Admin.

Because `admins.manage` is the authority to administer access, its holder may assign any permission from the predefined catalogue even when the holder does not personally use that operational permission. Every such grant remains subject to fresh authentication, self-management prohibitions, confirmation, notification, and audit. The interface must clearly identify `admins.manage` as the highest-risk assignable permission.

### 9.3 Customer and Agent provisioning

Customers and Agents do not receive individual permission records.

- Their fixed capabilities become usable only when their accounts reach the required active state.
- Agent resource access additionally depends on current Customer assignments.
- Invitation, MFA-setup, suspension, deactivation, and recovery states restrict access as defined by Authentication.

## 10. Admin Permission Management

### 10.1 Viewing permissions

An Admin may view their own permissions from account settings.

Any active Admin may see a concise responsibility summary for other Admins. Only an Admin with `admins.manage` may view the complete permission-management history and change controls for another Admin.

### 10.2 Changing permissions

An Admin with `admins.manage` may change another Admin's permissions through this workflow:

1. Open the target Admin's access settings.
2. Be signed in with a confirmed authenticator. _(Repeated fresh password-and-MFA authentication was removed on 8 October 2026; see Authentication Section 8.5.)_
3. Select the permissions to grant or revoke.
4. Enter a required reason.
5. Review the before-and-after permission summary.
6. Confirm the change.
7. The server validates the actor, target, permission codes, security restrictions, and final-capable-Admin safeguards.
8. The system applies the complete change atomically and increments the target's permission version.
9. Active authorization begins using the new permission set immediately.
10. The system sends notifications and writes the audit events.

A permission change must never partially succeed.

### 10.3 Self-management prohibition

An Admin cannot grant, revoke, or otherwise change their own permissions.

This includes indirect self-change through an API request, bulk action, imported configuration, background job, or permission group. Another eligible Admin must perform the change.

The seeded first Admin may manage business operations while they are the only Admin, but cannot reduce their own permissions. They must activate another Admin with `admins.manage` before their permission set can be changed.

### 10.4 Final-capable-Admin safeguard

Outside the final-Admin emergency recovery process, the system must preserve both:

- At least one active Admin account.
- At least one active Admin who holds `admins.manage` and is not currently prevented from using it by a post-recovery restriction.

The system must reject any permission revocation, suspension, deactivation, or scheduled change that would violate either condition.

An invited Admin, an Admin awaiting MFA setup, a suspended Admin, a deactivated Admin, or an Admin inside the 24-hour post-recovery Admin-management restriction does not satisfy the final-capable-Admin requirement.

Final-Admin emergency recovery is the only permitted temporary exception. When emergency recovery places the sole Admin under a post-recovery restriction, the business may temporarily have no Admin who can use `admins.manage`. The system must preserve the underlying grant, deny Admin-management actions until the restriction expires, and must not treat the condition as permission loss or create a bypass.

### 10.5 Permission-change effects

- A granted permission becomes effective immediately after the committed change.
- A revoked permission becomes unavailable immediately, including in existing sessions.
- Removing access invalidates pending page actions, exports, and unexecuted background jobs that require the permission.
- A form opened before revocation cannot be submitted successfully after revocation.
- Permission changes do not modify the target's password, MFA, role, account status, assignments, or historical attribution.
- Revocation does not delete records created or approved while the permission was valid.

### 10.6 Notifications

The target Admin must receive an email and in-application notification when:

- A permission is granted.
- A permission is revoked.
- Their Admin account is suspended, reactivated, or deactivated.
- A post-recovery restriction begins or ends.

Active Admins with `admins.manage` must also be notified when that permission is granted or revoked, or when an Admin-management safeguard blocks an attempted change.

Notifications must identify the acting Admin, broad change, time, and a route to review the account. They must not expose authentication secrets or unnecessary personal information.

## 11. Role and Account-State Rules

### 11.1 Role immutability

Version 2 does not support changing an account from Customer to Agent, Agent to Admin, or any other role conversion.

The system must not silently change a role to resolve a duplicate email address. A future role-conversion workflow must define identity continuity, historical attribution, conflicts of duty, assignment effects, and financial access before role changes can be enabled.

### 11.2 Account-state interaction

| Account state      | Authorization effect                                                                                                                                                 |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Invited            | Account and initial grants may exist, but no application capability is usable.                                                                                       |
| MFA setup required | Admin or Agent may access only the mandatory MFA setup flow.                                                                                                         |
| Active             | Role capabilities and current Admin permissions are usable, subject to resource scope and restrictions.                                                              |
| Temporarily locked | The affected new authentication path is blocked; legitimate existing sessions retain their current authorization unless compromise handling separately revokes them. |
| Suspended          | All application authorization is denied and active sessions are revoked.                                                                                             |
| Deactivated        | All application authorization is denied; historical attribution and permission history are retained.                                                                 |

Account state is checked before role, permission, and resource scope on every protected request.

### 11.3 Post-recovery Admin restriction

For 24 hours after assisted Admin recovery completes, the recovered Admin cannot:

- Invite, suspend, reactivate, deactivate, or recover another Admin.
- Grant or revoke Admin permissions.
- Grant `admins.manage` through any workflow.

The restriction applies even if the Admin holds `admins.manage`. It must be represented as a separate, expiring authorization restriction rather than by deleting the underlying permission grant.

Other granted permissions remain available unless the recovery process or an authorized Admin separately restricts them.

## 12. Separation of Duties

### 12.1 General rule

Where a workflow separates initiation from approval, one user cannot perform both duties on the same request.

Changing devices, sessions, or authentication methods does not make the same account a different actor.

### 12.2 Withdrawals

- An assigned Agent initiates a Customer withdrawal request.
- An Admin with `withdrawals.review` approves or rejects it.
- An Admin cannot create the Agent-side initiation in order to approve it themselves.
- The detailed Withdrawals module will define processing, completion, evidence, limits, and accounting effects.

### 12.3 Transaction reversals

- An Agent initiates a reversal request for an accessible transaction.
- One Admin with `reversals.review` approves or rejects it, regardless of transaction value.
- Approval produces the traceable reversal behaviour defined by the Transaction Ledger module; it does not edit or delete the original transaction.

### 12.4 Assisted recovery

- The recovering user cannot approve their own recovery.
- An Agent who initiates Customer recovery cannot approve it.
- Admin recovery requires distinct eligible approvers and two approvals when two eligible Admin approvers are available.
- No approver may learn or assign the recovering user's permanent password, authenticator secret, or recovery codes.

### 12.5 Admin access management

- An Admin cannot change their own permissions or status.
- An Admin cannot approve or complete a request whose sole purpose is to bypass that prohibition.
- Bulk operations must apply the same actor-target restrictions as individual operations.

## 13. Resource-Level Authorization

### 13.1 Customer ownership

Customer requests must derive the Customer identity from the authenticated account. A request-supplied Customer ID must never allow a Customer to select another Customer's scope.

### 13.2 Agent assignment checks

Every Agent request involving a Customer, plan, transaction, withdrawal, recovery request, statement, notification, or export must verify the current effective assignment on the server.

Historical `created_by` or `agent_id` fields establish attribution but do not establish current access.

### 13.3 Admin business scope

Admin requests must be limited to the configured business. Business context must be derived from trusted server configuration or the authenticated account, not from an unrestricted client-supplied business identifier.

### 13.4 Related-record checks

Authorization must cover the complete relationship chain. For example, recording a contribution requires validation that:

- The requester is an active Agent.
- The Customer is currently assigned to that Agent.
- The thrift plan belongs to that Customer.
- The plan is in a state that accepts the contribution.
- The Agent capability permits the requested operation.

Valid access to one identifier must not authorize mismatched related records.

### 13.5 Lists, counts, and search

Authorization applies to:

- List results.
- Search suggestions.
- Dashboard totals.
- Counts and badges.
- Report rows.
- Export files.
- Notification previews.
- Error messages and record-existence checks.

The system must not leak an out-of-scope record through metadata even when the full record page is protected.

## 14. Enforcement Behaviour

### 14.1 Authorization evaluation order

For each protected request, the server should evaluate:

1. Session validity.
2. Account state.
3. Temporary security restrictions.
4. Role eligibility.
5. Required Admin permission, if applicable.
6. Resource scope and related-record ownership.
7. Separation-of-duty requirements.
8. Domain status and workflow rules.

### 14.2 Denied requests

- The server must return a consistent forbidden response for an authenticated user who lacks authority.
- Where record existence is sensitive, the response must not confirm whether the out-of-scope record exists.
- A denied mutation must not create a partial record, reserve funds, advance a workflow, or enqueue a privileged job.
- Repeated or suspicious attempts to access protected financial or security resources must be audited and may trigger security monitoring.

### 14.3 User-interface behaviour

- Navigation and controls should reflect the user's current role and Admin permissions.
- A disabled control should explain a domain prerequisite when the user has permission but the record is ineligible.
- A control should normally be hidden when the user lacks the required role or permission.
- Direct navigation to a now-unauthorized page must show a safe access-denied state or return the user to the appropriate dashboard.
- The interface must refresh after a permission or assignment change and must not continue presenting stale privileged actions.

### 14.4 Concurrent changes

Permission, status, and assignment changes must use concurrency control.

- Each protected mutation evaluates current authorization at commit time.
- A stale permission-management form must not overwrite a newer permission change.
- Permission changes increment the target's permission version.
- Assignment changes increment the assignment version or otherwise provide an equivalent consistency check.
- If authorization changes during a request, the system must fail safely before committing the protected action.

## 15. Permission Data Requirements

The authorization model should support the following records.

### 15.1 Permission definition

- Stable permission code.
- Display name.
- Description.
- Status: active or retired.
- Introduction and retirement timestamps.

### 15.2 Admin permission grant

- Admin account.
- Permission code.
- Grant status.
- Granted by.
- Granted timestamp.
- Grant source, such as seed, invitation, or permission change.
- Reason or responsibility description.
- Revoked by and revoked timestamp where applicable.
- Revocation reason where applicable.

Historical grants must not be physically deleted.

### 15.3 Authorization restriction

- Affected account.
- Restriction type.
- Capabilities or permissions affected.
- Start and expiry timestamps.
- Source event, such as assisted recovery.
- Created by or system source.
- Cleared timestamp and reason where applicable.

### 15.4 Permission snapshot on approvals

Material approvals should retain the approving user's identity, required permission code, decision timestamp, and relevant request version.

Later permission revocation must not erase evidence that the approver was authorized when the decision was made.

## 16. Audit Requirements

The audit log must record:

- Initial Admin permissions assigned at invitation.
- Every Admin permission grant and revocation.
- The actor, target Admin, permission, reason, and before-and-after permission set.
- Fresh-authentication completion associated with a permission change, without recording credentials or authenticator codes.
- Successful and blocked Admin invitations, status changes, and recovery actions.
- Final-active-Admin and final-capable-Admin safeguard failures.
- Permission-version changes and active-session authorization refreshes.
- Customer reassignment authorization changes.
- High-risk denied requests, including attempted self-management or cross-scope access.
- Use of privileged permissions for approvals, security operations, reconciliation, business configuration, reports, and audit access.
- Permission catalogue migrations.

Audit entries must include:

- Event identifier.
- Acting user or trusted system process.
- Affected account or record.
- Action and result.
- Required role and permission where applicable.
- Previous and new references or values where safe.
- Reason.
- Timestamp.
- Request, session, and device context where available.

Audit records must not contain passwords, session tokens, invitation tokens, authenticator codes, authenticator secrets, recovery codes, or unmasked sensitive exports.

Authorization audit records are append-oriented and cannot be altered through the permission-management interface.

## 17. Functional Requirements

### AUTHZ-001 — Single role

The system shall assign exactly one role—Customer, Agent, or Admin—to each account and shall not support role switching or silent role conversion.

### AUTHZ-002 — Default deny

The system shall deny every protected action that has no explicit role capability or Admin-permission rule.

### AUTHZ-003 — Server enforcement

The system shall enforce account state, role, permission, resource scope, separation of duties, and workflow eligibility on the server for every protected request.

### AUTHZ-004 — Customer isolation

The system shall restrict each Customer to their own profile, plans, balances, transactions, statements, notifications, and approved self-service settings.

### AUTHZ-005 — Fixed Agent capabilities

The system shall provide Agents with the fixed operational capabilities defined by this module and shall not support individual Agent permission grants in Version 2.

### AUTHZ-006 — Current assignment scope

The system shall limit Agent access to currently assigned Customers and shall immediately apply assignment and reassignment changes.

### AUTHZ-007 — Historical attribution

The system shall preserve the identity of the Agent or Admin who performed a historical action without treating that attribution as continuing access.

### AUTHZ-008 — Admin baseline access

The system shall provide active Admins with the defined business-wide read access while requiring granular permissions for protected actions.

### AUTHZ-009 — Closed permission catalogue

The system shall authorize granular Admin actions only through active, explicit grants from the predefined permission catalogue.

### AUTHZ-010 — No wildcard grants

The system shall not use a durable wildcard grant that automatically authorizes future permission codes.

### AUTHZ-011 — Admin management

The system shall require `admins.manage` to invite, manage, recover, suspend, reactivate, deactivate, or change the permissions of another Admin.

### AUTHZ-012 — Agent management

The system shall require `agents.manage` for protected Agent provisioning, invitation, status, and management actions.

### AUTHZ-013 — Customer management

The system shall require `customers.manage` for protected business-wide Customer management while preserving Agent-only Customer creation.

### AUTHZ-014 — Customer reassignment

The system shall require `customers.reassign` to change a Customer's active Agent assignment.

### AUTHZ-015 — Withdrawal approval

The system shall require `withdrawals.review` for an Admin to approve or reject a withdrawal request.

### AUTHZ-016 — Reversal approval

The system shall require `reversals.review` for one Admin to approve or reject a transaction-reversal request.

### AUTHZ-017 — Financial administration

The system shall independently enforce `fees.manage`, `deductions.manage`, and `reconciliation.manage` for their respective protected workflows.

### AUTHZ-018 — Business configuration

The system shall require `business.settings.manage` for business-configuration changes. Publication and scheduled-change cancellation no longer repeat fresh authentication (8 October 2026 decision, Authentication Section 8.5).

### AUTHZ-019 — Security operations

The system shall require `security.operations.manage` for Customer recovery approval, controlled manual unlock, privileged security-event access, and the Agent security actions assigned to that permission.

### AUTHZ-020 — Audit access

The system shall require `audit.view` to access the detailed business audit log.

### AUTHZ-021 — Report export

The system shall require `reports.export` to export business-wide or multi-Customer reports.

### AUTHZ-022 — Seeded Admin grants

The system shall explicitly grant every current permission to the seeded first Admin and record `system_seed` as the grant source.

### AUTHZ-023 — Initial Admin permissions

The system shall require an inviting Admin with `admins.manage` to review and explicitly assign each additional Admin's initial permission set.

### AUTHZ-024 — No self-management

The system shall prevent an Admin from changing their own permissions or account status through individual, bulk, API, import, or indirect workflows.

### AUTHZ-025 — Final Admin safeguards

Outside final-Admin emergency recovery, the system shall prevent any change that would leave the business without an active Admin or without an active, currently capable holder of `admins.manage`.

### AUTHZ-026 — Atomic permission changes

The system shall apply a reviewed Admin permission change atomically and reject the complete change if any part is invalid.

### AUTHZ-027 — Immediate authorization refresh

The system shall make permission grants and revocations effective immediately in active sessions and shall reject stale permission versions.

### AUTHZ-028 — Pending-work invalidation

The system shall re-check authorization before executing a queued export, background job, approval, or form submission and shall cancel it safely when the required authority no longer exists.

### AUTHZ-029 — Post-recovery restriction

The system shall temporarily deny Admin-management actions to a recovered Admin for 24 hours without deleting the Admin's underlying permission grants.

### AUTHZ-030 — Separation of duties

The system shall prevent the same account from acting as both initiator and approver where the applicable workflow requires distinct actors.

### AUTHZ-031 — Related-record validation

The system shall validate the authorization relationship among every Customer, Agent, plan, transaction, request, and business identifier involved in a protected action.

### AUTHZ-032 — Scope-safe discovery

The system shall apply resource scope to searches, lists, dashboard totals, counts, notifications, reports, exports, and record-existence responses.

### AUTHZ-033 — Safe denial

The system shall ensure a denied mutation creates no partial record, financial effect, workflow transition, or privileged background task.

### AUTHZ-034 — Permission history

The system shall retain append-oriented Admin grant, revocation, restriction, and catalogue-migration history.

### AUTHZ-035 — Approval evidence

The system shall retain evidence of the approver's identity, required permission, decision time, and request version for every material approval.

### AUTHZ-036 — Permission notifications

The system shall notify an Admin when their permissions or status changes and notify active holders of `admins.manage` of changes affecting Admin-management continuity.

### AUTHZ-037 — Authorization audit

The system shall audit material grants, revocations, privileged actions, assignment effects, safeguard failures, and high-risk denied requests without recording authentication secrets.

## 18. Acceptance Criteria

This module is operational when:

1. A Customer can access their own profile, plan, balance, and transactions but receives no information when requesting another Customer's records.
2. An Agent can access and perform permitted operations for a currently assigned Customer.
3. The same Agent immediately loses access when the Customer is reassigned.
4. The new Agent immediately gains the operational access required for the reassigned Customer without changing historical attribution.
5. An Agent cannot access a Customer merely because that Agent recorded an earlier transaction for them.
6. An Agent cannot approve a withdrawal, approve a reversal, reassign a Customer, or access business-wide finances.
7. An Admin can view business-wide operational and financial information but cannot perform or record a collection.
8. An Admin without a required granular permission cannot complete the protected action through the interface or a direct endpoint call.
9. Granting an Admin permission makes the action available immediately in their active session.
10. Revoking an Admin permission blocks the action immediately, including from an already-open form or queued job.
11. The seeded first Admin has an explicit grant for every current permission, with the seed source preserved in history.
12. An invited Admin receives only the explicitly selected permissions and cannot use them before activation and MFA completion.
13. An Admin cannot alter their own permissions or status through any supported interface or direct request.
14. An Admin without `admins.manage` cannot invite, suspend, recover, deactivate, or change the permissions of another Admin.
15. The system prevents removal of the final active Admin.
16. The system also prevents revoking or disabling the final currently capable holder of `admins.manage`, except for the defined temporary restriction created by final-Admin emergency recovery.
17. An Admin inside the 24-hour post-recovery restriction cannot perform Admin-management actions even when `admins.manage` remains granted.
18. An Admin with `agents.manage` can register and manage an Agent but cannot reassign Customers without `customers.reassign`.
19. An Admin with `customers.manage` can manage an existing Customer and invitation but cannot create a Customer or record a collection.
20. Only an Admin with `withdrawals.review` can approve or reject a withdrawal request.
21. Only an Admin with `reversals.review` can approve or reject a reversal request, and one authorized Admin approval is sufficient regardless of value.
22. Fee, deduction, and reconciliation permissions are enforced independently.
23. Only an Admin with `security.operations.manage` can approve Customer assisted recovery or manually unlock a Customer or Agent.
24. Only an Admin with `audit.view` can open and search the detailed audit log.
25. Only an Admin with `reports.export` can export a business-wide or multi-Customer report.
26. Lists, search suggestions, counts, dashboards, notifications, and exports exclude records outside the user's scope.
27. Mismatching an accessible Customer with another Customer's plan or transaction is rejected without financial effect.
28. A denied mutation creates no partial record, approval, balance change, reserved funds, or background task.
29. Permission changes require a reason, review of the before-and-after access, and atomic confirmation. _(Fresh authentication was removed from this criterion by the 8 October 2026 decision in Authentication Section 8.5.)_
30. Permission grants, revocations, restrictions, privileged actions, safeguard failures, and high-risk denials appear in the audit log without authentication secrets.

## 19. Confirmed Product Decisions

- Each account has exactly one role: Customer, Agent, or Admin.
- Customer and Agent capabilities are fixed in Version 2 and are not individually configurable.
- Agent access is restricted by the Customer's current effective assignment.
- Admins have business-wide baseline read access and explicit granular permissions for protected actions.
- Admin permissions come from a closed system-defined catalogue; users cannot create custom roles or permissions.
- Permission grants are explicit and do not use a wildcard that would include future permissions.
- The seeded first Admin receives every current granular permission as explicit seed grants.
- Additional Admins receive only the permissions explicitly selected during invitation or later granted by another authorized Admin.
- Only an Admin with `admins.manage` can assign or change another Admin's permissions.
- An Admin cannot change their own permissions or status.
- Outside final-Admin emergency recovery, the system preserves at least one active Admin and one active, currently capable holder of `admins.manage`.
- Admin permission and Agent-assignment changes take effect immediately in active authorization.
- An Admin cannot record collections, and no granular permission can grant that capability.
- An Agent cannot approve withdrawals or transaction reversals.
- A transaction reversal requires one approval from an Admin with `reversals.review`, regardless of value.
- Creating a Customer remains exclusive to Agents; an Admin with `customers.manage` may manage an existing Customer and invitation.
- Detailed audit-log access and business-wide report export require separate permissions.
- A recovered Admin retains their grants but cannot use Admin-management authority for 24 hours.
- Role conversion is outside Version 2 scope until a dedicated workflow is defined.

## 20. Decisions Deferred to Detailed Modules

The following decisions remain with their owning modules:

- Customer and Agent profile-field edit rules.
- Agent activation, suspension, offboarding, and Customer reassignment workflow details.
- Plan creation, amendment, completion, and renewal rules.
- Withdrawal initiation, evidence, approval, rejection, payout, and completion rules.
- Transaction-reversal eligibility, timing, posting, and notification rules.
- Fee and deduction configuration and accounting behaviour.
- Collection reconciliation states and exception handling.
- Report contents, formats, and export retention.
- Audit-log retention, masking, and permitted export behaviour.
- Business settings classified as general, financial, or security-sensitive.

## 21. Related Version 2 Modules

This module informs:

- Customer and Agent management.
- Business profile and configuration.
- Thrift plans.
- Fees and deductions.
- Collections and reconciliation.
- Withdrawals and approvals.
- Transaction ledger and reversals.
- Dashboard and reporting.
- Audit logs and security monitoring.
