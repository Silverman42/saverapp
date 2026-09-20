# User Types and Access Model

**Product version:** 2.0  
**Module status:** Draft  
**Source:** Version 1 PRD and confirmed Version 2 role definitions

## 1. Purpose

Version 2 supports three user types: **Customer**, **Agent**, and **Admin**. This module defines what each user type represents, its core responsibilities, and the boundaries between the roles.

Detailed authentication, onboarding, permissions, and financial workflow requirements will be specified in their respective Version 2 modules.

## 2. Terminology

- **Customer** is the Version 2 product term for a saver.
- **Agent** is the Version 2 product term for a thrift collector.
- **Admin** is the person responsible for managing the thrift business within the system.
- **Business** means the thrift organization whose customers, agents, collections, and finances are managed in the system.

These terms should be used consistently throughout the Version 2 documentation and user interface. Where needed for familiarity, the interface may describe a customer as a “Customer (Saver).”

## 3. User Type Summary

| User type | Business role | Primary responsibility | Scope of knowledge and control |
| --- | --- | --- | --- |
| Customer | Saver | Participates in thrift savings | Their own profile, plans, savings, and transactions |
| Agent | Thrift collector | Manages assigned customers and records collections | Operational information required to perform collections |
| Admin | Business administrator | Manages customers, registers agents, and oversees the business | Business-wide operations, finances, users, and controls |

## 4. Customer

### 4.1 Definition

A Customer is a saver whose money is collected and managed through the thrift business.

### 4.2 Core characteristics

- The Customer participates in one or more thrift savings arrangements.
- The Customer is associated with the thrift business.
- The Customer has login access to the system.
- The Customer is added to the system by an Agent.
- The Customer is assigned to exactly one Agent at a time.
- The Customer may be reassigned to a different Agent.
- The Customer owns the financial activity recorded against their profile, including contributions, withdrawals, fees, and deductions.

### 4.3 Core needs

A Customer needs to be able to understand:

- Their active thrift plan or plans.
- The contribution amount and collection schedule.
- Contributions recorded on their behalf.
- Missed, partial, advance, and multiple-day payments.
- Withdrawals, fees, and deductions.
- Their current savings balance.
- Their transaction history and thrift statement.

### 4.4 Access boundary

A Customer must never be able to view or manage another customer's profile, savings, transactions, or personal information.

A Customer's authenticated experience is limited to their own account and financial information. Customer authentication, account activation, and recovery requirements will be defined in the authentication module.

## 5. Agent

### 5.1 Definition

An Agent is a thrift collector who carries out day-to-day collection activities on behalf of the business.

### 5.2 Core responsibilities

- Add Customers to the system.
- Create thrift plans for their assigned Customers.
- Perform and record collections.
- Maintain the operational information needed to collect from Customers.
- Ensure collection records are accurate and attributable.
- Review the collection history needed to resolve routine discrepancies with a Customer.
- Initiate withdrawal requests on behalf of Customers.
- Initiate transaction-reversal requests when required.

### 5.3 Core needs

An Agent needs to know:

- Which Customers they are responsible for.
- Which Customers are due for collection.
- How much each Customer is expected to contribute.
- Who has paid, partially paid, paid ahead, or missed a contribution.
- What the Agent collected during a given period.
- Whether recorded collections have been successfully reconciled.

### 5.4 Access boundary

An Agent's access is limited to the Customers and collection activity within their responsibility. For assigned Customers, the Agent can view full balances and transaction histories. An Agent must not receive business-wide financial control merely because they can record collections.

Agents cannot approve transaction reversals or withdrawal processing. These actions require Admin approval.

A transaction reversal requires approval from one authorized Admin, regardless of the transaction value.

### 5.5 Customer assignment and reassignment

- Each Customer has one active Agent assignment at a time.
- An Admin may reassign a Customer to another Agent.
- A reassignment must record the previous Agent, new Agent, approving Admin, reason, and effective timestamp.
- Historical collections remain attributed to the Agent who originally recorded them.
- The new Agent becomes responsible for collections recorded after the reassignment takes effect.
- Reassignment does not reset or recreate the Customer's thrift plan, balance, or transaction history.

## 6. Admin

### 6.1 Definition

An Admin is the highest-authority user within a thrift business. The Admin manages the business's users and operations and has master control over the business account.

### 6.2 Core responsibilities

- Register and manage Agents.
- Manage Customers across the business.
- Reassign Customers from one Agent to another.
- Approve or reject withdrawal processing.
- Approve or reject transaction reversals.
- Oversee collection operations.
- Maintain a broader view of the business's financial position.
- Update business configuration from the dashboard.
- Control business-level settings and access.
- Investigate financial activity and operational issues across the business.

### 6.3 Core needs

An Admin needs to know:

- How much has been collected across the business.
- How much the business currently owes Customers.
- How Agents are performing and what each Agent has collected.
- The value and status of withdrawals, fees, deductions, and reconciliations.
- Which Customers and Agents are active, inactive, suspended, or otherwise restricted.
- What important actions were performed, when they occurred, and who performed them.

### 6.4 Access boundary

An Admin has business-wide control, but all sensitive and financial actions must remain traceable through an audit log. “Master control” does not permit the deletion or silent alteration of financial history.

An Admin cannot perform or record collections. Collection recording is strictly an Agent responsibility.

The Admin governs the configured thrift business and its users. Version 2 does not define a separate platform-level or super-administrator role.

### 6.5 Multiple Admins and initial setup

The system may have multiple Admins, all managing the same thrift business.

The business and its first Admin are provisioned as seeded data rather than created through a public self-service registration flow. After provisioning, an Admin can update the business configuration from the dashboard. An Admin with the required Admin-management permission can add other Admins.

The Admin role supports granular administrative permissions. Only an Admin granted the relevant permission may add, suspend, or remove another Admin. Holding the Admin role alone does not automatically grant Admin-management authority.

The system must prevent removal or suspension of the final active Admin account so that the business cannot be left without administrative access.

## 7. Role Hierarchy

Within a business, the authority order is:

**Admin → Agent → Customer**

This hierarchy describes breadth of access, not ownership of Customer funds. Customer savings remain a liability owed to the Customer, while fees earned by the business must remain separately accounted for.

## 8. Confirmed Capability Baseline

| Capability | Customer | Agent | Admin |
| --- | :---: | :---: | :---: |
| Log in to the system | Yes | Yes | Yes |
| View own savings and transactions | Yes | No | No |
| Add Customers | No | Yes | No |
| View full balances and transaction histories for assigned Customers | No | Yes | Yes |
| Create Customer thrift plans | No | Yes | No |
| Perform and record collections | No | Yes | No |
| Initiate withdrawal processing | No | Yes | No |
| Approve withdrawal processing | No | No | Yes |
| Initiate a transaction reversal | No | Yes | No |
| Approve a transaction reversal | No | No | Yes |
| Reassign Customers between Agents | No | No | Yes |
| Register Agents | No | No | Yes |
| Add, suspend, or remove Admins | No | No | With permission |
| Manage Customers business-wide | No | No | Yes |
| View business-wide finances | No | No | Yes |
| Update business configuration | No | No | Yes |
| Control the business account and system settings | No | No | Yes |

This table contains the confirmed Version 2 capability baseline. For Admins, **Yes** means the Admin role is eligible for the capability; protected actions may additionally require a granular permission under [Roles, Permissions, and Authorization](./03-roles-and-permissions.md). Detailed workflow rules will be expanded in their respective modules.

## 9. Cross-Cutting Requirements

### 9.1 System scope

Version 2 operates for one configured thrift business. The business profile and its first Admin are provided through seeded data. The system does not require tenants, tenant selection, or cross-business data isolation.

### 9.2 Least-privilege access

Each user type should receive only the access required to perform its responsibilities. Sensitive financial actions may require additional permission or Admin approval even when a user can view the underlying record.

### 9.3 Accountability

Every material action must record the responsible user, timestamp, and relevant business context. This includes Customer creation, collection recording, transaction correction or reversal, withdrawals, deductions, Agent management, and permission changes.

### 9.4 Financial history

No user type may permanently delete completed financial transactions. Corrections must use a traceable reversal or adjustment workflow.

### 9.5 Status controls

The system should support statuses for controlling access without destroying historical records. The exact lifecycle for Customer, Agent, and Admin statuses will be defined in the identity and access module.

## 10. Decisions Deferred to Detailed Modules

The baseline user types and authority boundaries are confirmed. Detailed modules will define:

- The individual permissions that can be granted to an Admin and who may grant them.
- Customer invitation, account activation, login, and recovery flows.
- Withdrawal request statuses, evidence, approval, rejection, and completion rules.
- Transaction-reversal request statuses, reason requirements, and approval flow.
- Agent registration, activation, suspension, and offboarding.
- Customer reassignment workflow and notifications.

## 11. Related Version 2 Modules

The decisions in this module will inform:

- Business profile and configuration.
- Authentication and account recovery.
- User onboarding and registration.
- [Roles, permissions, and authorization](./03-roles-and-permissions.md).
- Customer management.
- Agent management and assignment.
- Collections and reconciliation.
- Financial reporting.
- Audit logs and security.
