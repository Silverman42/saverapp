import type { AdminPermission } from "@/types";

/**
 * Help & FAQ manual for Agents and Admins.
 *
 * This file is the source of truth for how features work. When a feature
 * changes, update the matching answer in the same change.
 */

export type HelpAudience = "agent" | "admin";

export type HelpArticle = {
    id: string;
    question: string;
    answer: string[];
    permission?: AdminPermission;
};

export type HelpSection = {
    id: string;
    title: string;
    audience: HelpAudience;
    summary: string;
    articles: HelpArticle[];
};

export const permissionLabels: Record<AdminPermission, string> = {
    "admins.manage": "Admin management",
    "agents.manage": "Agent management",
    "customers.manage": "Customer management",
    "customers.reassign": "Customer reassignment",
    "withdrawals.review": "Withdrawal approval",
    "cash.execute": "Cash execution",
    "reversals.review": "Reversal approval",
    "fees.manage": "Fee management",
    "deductions.manage": "Deduction management",
    "reconciliation.manage": "Reconciliation management",
    "financial.periods.manage": "Booking months",
    "business.settings.manage": "Business configuration",
    "security.operations.manage": "Security operations",
    "audit.view": "Activity log access",
    "reports.export": "Report export",
};

const agentSections: HelpSection[] = [
    {
        id: "agent-getting-started",
        title: "Getting started",
        audience: "agent",
        summary: "Activating your account, signing in and staying secure.",
        articles: [
            {
                id: "agent-activate-account",
                question: "How do I activate my account?",
                answer: [
                    "Open the invitation email and follow the link. It is valid for 24 hours.",
                    "Choose a password of at least 8 characters.",
                    "Set up an authenticator app. You cannot work until this is done.",
                    "If the link expired, ask an Admin to resend it. Each resend cancels the previous link.",
                ],
            },
            {
                id: "agent-sessions",
                question: "Why was I signed out?",
                answer: [
                    "Agents are signed out after 1 hour without activity, and after 24 hours at most.",
                    "You can be signed in on 2 devices at once. Signing in on a third asks you to sign out another device.",
                    "You can see and end your sessions under Security settings.",
                ],
            },
            {
                id: "agent-lockouts",
                question: "What happens if I enter the wrong password or code?",
                answer: [
                    "After 5 failed passwords in 15 minutes you must wait a few minutes before trying again.",
                    "10 failed passwords in 1 hour lock the account for 15 minutes and send you an email.",
                    "20 failed passwords in 24 hours lock the account for 1 hour and flag it for review.",
                    "10 wrong authenticator codes in 1 hour lock code entry for 15 minutes.",
                    "An Admin can unlock you after checking your identity.",
                ],
            },
            {
                id: "agent-lost-device",
                question: "I lost my phone or authenticator app. What do I do?",
                answer: [
                    "Use one of your 10 recovery codes to sign in, then set up the authenticator again.",
                    "With no recovery codes, ask an Admin to start staff account recovery. Once approved, you get an activation link valid for 60 minutes.",
                ],
            },
            {
                id: "agent-confirm-identity",
                question: "Why am I asked for my password again?",
                answer: [
                    "Sensitive actions need you to confirm your identity with your password and authenticator code.",
                    "Once confirmed, you are not asked again for 10 minutes.",
                ],
            },
            {
                id: "agent-eligibility",
                question: "Why can I see customers but not record anything?",
                answer: [
                    "To do customer work you must have an active account, a confirmed authenticator and an operational status of Active.",
                    "If an Admin sets you to Inactive, you can still view your customers but cannot record collections, plans or withdrawals.",
                ],
            },
        ],
    },
    {
        id: "agent-registering-customers",
        title: "Registering customers",
        audience: "agent",
        summary: "Adding new customers, offline customers and invitations.",
        articles: [
            {
                id: "agent-register-customer",
                question: "How do I register a customer?",
                answer: [
                    "Go to Customers and choose Register customer.",
                    "Name and phone (international format) are required. Email is required unless the customer is offline only.",
                    "Phone, email and internal reference must be unique.",
                    "If you add any next-of-kin detail, full name, relationship and phone are all required.",
                    "The customer is assigned to you automatically and gets a customer ID (CUS-…).",
                ],
            },
            {
                id: "agent-registration-blocked",
                question: "Why is registration blocked?",
                answer: [
                    "Registration needs a published registration fee rule. Ask an Admin with Fee management to publish one.",
                    "For customers with an email, the business email sender must also be ready.",
                    "If the fee changed while you were filling the form, review the new fee and confirm again.",
                ],
            },
            {
                id: "agent-offline-customer",
                question: "What is an offline-only customer?",
                answer: [
                    "A customer without an email address who will not use the app. Turn on “Offline only” when registering.",
                    "No invitation is sent and they cannot sign in. You handle everything for them.",
                    "Emails meant for them are skipped.",
                ],
            },
            {
                id: "agent-give-app-access",
                question: "How do I give an offline customer app access later?",
                answer: [
                    "Open the customer and use Give app access. Enter their email.",
                    "An invitation is sent and the customer is no longer offline only.",
                ],
            },
            {
                id: "agent-customer-invitation",
                question: "How long does a customer invitation last?",
                answer: [
                    "Customer invitations are valid for 7 days.",
                    "You can resend from the customer page once a minute, up to 5 times in 24 hours. Each resend cancels the old link.",
                    "While the customer has not activated yet, you can correct their email or cancel the invitation with a reason.",
                ],
            },
            {
                id: "agent-edit-customer",
                question: "What customer details can I change?",
                answer: [
                    "Address, gender, occupation, next of kin, notes, internal reference and photo. Notes and internal reference need a reason.",
                    "Name: changes immediately before activation. After activation the customer must accept the change within 7 days.",
                    "Phone: you can correct it before activation. After activation only the customer can change it.",
                ],
            },
        ],
    },
    {
        id: "agent-registration-fee",
        title: "Registration fee",
        audience: "agent",
        summary: "Collecting the registration fee now, in part or later.",
        articles: [
            {
                id: "agent-fee-options",
                question: "What are the fee payment options at registration?",
                answer: [
                    "Pending: nothing collected now.",
                    "Part payment: more than ₦0 and less than the full fee.",
                    "Paid in full: the whole fee is collected now.",
                    "The option is hidden when the fee is ₦0.",
                ],
            },
            {
                id: "agent-fee-cash",
                question: "What happens to the cash I collect for the fee?",
                answer: [
                    "A cash receipt is recorded automatically and added to your cash batch for the day.",
                    "You must hand that cash over to the business like any other collection.",
                    "If the receipt cannot be recorded (for example, the month is closed), nothing is saved and you see the reason.",
                ],
            },
            {
                id: "agent-fee-later",
                question: "How does a customer pay an unpaid fee later?",
                answer: [
                    "Record a fee-only collection: savings ₦0 with the unpaid fee selected. No plan is needed.",
                ],
            },
            {
                id: "agent-fee-charge-again",
                question: "Can I charge the registration fee again?",
                answer: [
                    "Yes, from the customer page, with a reason. It uses the current published fee.",
                    "You can choose Pending, Part payment or Paid in full.",
                    "Not allowed for archived customers or when the current fee is ₦0. It is never charged again automatically.",
                ],
            },
        ],
    },
    {
        id: "agent-plans",
        title: "Savings plans",
        audience: "agent",
        summary:
            "Creating, changing and closing a customer’s savings plan (page).",
        articles: [
            {
                id: "agent-create-plan",
                question: "How do I create a plan?",
                answer: [
                    "Open the customer and choose Create plan. The customer must be Active.",
                    "Set a daily amount (₦1 to ₦10,000,000), number of days (1–366) and start date (today or within 365 days).",
                    "Pick a service charge option and confirm the customer agreed to the terms.",
                    "The preview shows the expected total, end date, fee estimate and early-withdrawal terms.",
                ],
            },
            {
                id: "agent-one-plan",
                question: "Can a customer have more than one plan?",
                answer: [
                    "No. A customer can have only one open plan (Active, Paused or Completed) at a time.",
                ],
            },
            {
                id: "agent-plan-statuses",
                question: "What do the plan statuses mean?",
                answer: [
                    "Active: the only status that accepts savings.",
                    "Paused: no savings accepted. Resume when the customer is Active and no closure is pending.",
                    "Completed: every day is fully paid. Set automatically.",
                    "Closed: settled, or closed after a full withdrawal.",
                    "Cancelled: only possible for a plan that was never used.",
                ],
            },
            {
                id: "agent-amend-plan",
                question: "Can I change a plan after creating it?",
                answer: [
                    "Yes, with a reason and an explanation for the customer.",
                    "Once there has been any activity or a fee was charged, only the name and notes can change.",
                ],
            },
            {
                id: "agent-new-page",
                question: "How do I start a new page?",
                answer: [
                    "Use Start new page on a Closed or Cancelled plan. The form is pre-filled from the old plan.",
                    "The new page has its own service charge.",
                ],
            },
            {
                id: "agent-thrift-card",
                question: "What can I mark on the thrift card?",
                answer: [
                    "You can mark an unpaid past or current day as Missed or Skipped, or clear the mark, with a reason.",
                    "Future days and paid days cannot be marked.",
                ],
            },
            {
                id: "agent-close-plan",
                question: "How do I close a completed plan?",
                answer: [
                    "Open plan settlement. Closing needs: no unpaid fees, zero savings left, reconciled batches and no pending requests.",
                    "If enabled for your business.",
                ],
            },
        ],
    },
    {
        id: "agent-collections",
        title: "Collections",
        audience: "agent",
        summary: "Recording daily savings and fee payments.",
        articles: [
            {
                id: "agent-record-collection",
                question: "How do I record a collection?",
                answer: [
                    "Open the customer and choose Record collection. Only the assigned Agent can record.",
                    "Savings go into the Active plan and fill the earliest unpaid days first. You can also split them by hand.",
                    "Every naira must land on a day, and no day can be overpaid.",
                    "Paying a future day counts as an advance payment.",
                    "Each receipt gets a reference like TXN-YYYYMMDD-… and the customer gets a receipt.",
                ],
            },
            {
                id: "agent-late-collection",
                question: "Can I record a collection from an earlier day?",
                answer: [
                    "Yes, up to 30 days back (the business can change this). A late reason is required.",
                    "The month of the received date must be open for booking.",
                ],
            },
            {
                id: "agent-receipt-limits",
                question: "Is there a minimum or maximum amount?",
                answer: [
                    "The receipt total must be between the business minimum (₦1 by default) and maximum.",
                ],
            },
            {
                id: "agent-service-charge",
                question: "When is the plan service charge taken?",
                answer: [
                    "Depending on the plan’s fee option: on the first contribution, when the plan is completed, or at withdrawal.",
                    "When the fee is due from savings and the savings cover it, it is taken automatically.",
                ],
            },
            {
                id: "agent-noncash",
                question: "How do I record a transfer or POS payment?",
                answer: [
                    "Upload payment evidence first: reference, amount, date and 1–3 files (JPG, PNG, WebP or PDF, 5 MB each).",
                    "A reviewer must verify it before you can use it to record the collection.",
                    "If enabled for your business.",
                ],
            },
        ],
    },
    {
        id: "agent-cash-handover",
        title: "Cash handover",
        audience: "agent",
        summary: "Cash batches and handing collected cash to the business.",
        articles: [
            {
                id: "agent-batches",
                question: "What is a cash batch?",
                answer: [
                    "All your receipts for one day and one method are grouped into a batch.",
                    "After the day ends, the batch is frozen and ready for review.",
                ],
            },
            {
                id: "agent-handover",
                question: "What happens when I hand over cash?",
                answer: [
                    "An Admin records the amount received and reviews the batch.",
                    "If everything matches, the batch is reconciled.",
                    "If cash is missing, a shortage is opened and stays open until you hand over the rest.",
                    "Cash you collected stays your responsibility, even if the customer moves to another Agent.",
                ],
            },
        ],
    },
    {
        id: "agent-withdrawals",
        title: "Withdrawals",
        audience: "agent",
        summary: "Requesting withdrawals and what happens after.",
        articles: [
            {
                id: "agent-withdrawal-types",
                question: "What types of withdrawal are there?",
                answer: [
                    "Partial: takes some money and leaves the rest in the plan.",
                    "Full: takes everything available. On an Active or Paused plan this ends the plan early.",
                    "End of page: takes everything available on a Completed plan.",
                ],
            },
            {
                id: "agent-withdrawal-request",
                question: "How do I request a withdrawal?",
                answer: [
                    "Open the customer, choose Withdraw and get a quote. The quote is valid for 10 minutes.",
                    "Customer receives = amount − fee − deduction. It must be more than ₦0.",
                    "Only one open withdrawal per plan. A reason is required.",
                    "The amount is set aside straight away so it cannot be used twice.",
                ],
            },
            {
                id: "agent-withdrawal-review",
                question: "What happens after I submit?",
                answer: [
                    "An Admin has 7 days to approve or reject. After approval there are 7 more days to pay.",
                    "You can cancel while it is waiting for review.",
                    "Rejected, cancelled or expired requests release the set-aside money.",
                    "If the customer becomes Restricted, the request is put on hold.",
                ],
            },
            {
                id: "agent-withdrawal-paid",
                question: "How is the customer paid?",
                answer: [
                    "Currently an Admin pays outside the platform (cash or bank transfer) and records it as “Paid outside platform”.",
                    "This cannot be reversed in the app, so check the details before approval.",
                ],
            },
            {
                id: "agent-early-withdrawal",
                question: "What happens after a full early withdrawal?",
                answer: [
                    "The plan is paused and closed automatically once all checks pass. Unpaid page fees are collected from the payout.",
                    "If something blocks closing (like an unreconciled batch), the system retries every 5 minutes.",
                    "The customer’s next page starts fresh with its own service charge.",
                ],
            },
        ],
    },
    {
        id: "agent-reversals",
        title: "Corrections (reversals)",
        audience: "agent",
        summary: "Fixing a posted transaction that was wrong.",
        articles: [
            {
                id: "agent-request-reversal",
                question: "How do I correct a wrong transaction?",
                answer: [
                    "Request a reversal on the transaction. Choose a reason: duplicate, wrong customer, wrong allocation, payment not received, incorrect fee, incorrect payout or other.",
                    "An internal reason, an explanation for the customer and evidence (up to 3 files) are required.",
                    "An Admin approves or rejects it. You can cancel while it is pending.",
                    "After a collection is reversed, you can record a correct replacement receipt.",
                ],
            },
            {
                id: "agent-reversal-limits",
                question: "What cannot be reversed?",
                answer: [
                    "Each transaction can only be reversed once, and only in full.",
                    "Payouts recorded as “Paid outside platform” cannot be reversed.",
                    "Archived customers cannot have reversals.",
                ],
            },
        ],
    },
    {
        id: "agent-customer-recovery",
        title: "Customer account recovery",
        audience: "agent",
        summary: "Helping a customer who lost access to their account.",
        articles: [
            {
                id: "agent-recovery-steps",
                question: "How do I help a customer who lost access?",
                answer: [
                    "Verify the customer in person, then request recovery with their new email.",
                    "A security Admin must approve it.",
                    "The customer then gets an activation link valid for 30 minutes, and all their old sessions are ended.",
                    "Requests expire after 7 days. Offline-only customers cannot use recovery.",
                ],
            },
        ],
    },
    {
        id: "agent-dashboard",
        title: "Dashboard and reports",
        audience: "agent",
        summary: "What the figures mean.",
        articles: [
            {
                id: "agent-today-figures",
                question: "What do the Today figures count?",
                answer: [
                    "Customers registered today.",
                    "Registration fee charged today: the fees charged, not the cash collected.",
                    "Service charge deducted today: plan fees taken from savings.",
                    "Deposits today: savings only, not fees. For you, only receipts you recorded.",
                    "Today always means the business day (Lagos time), whatever filter you pick.",
                ],
            },
            {
                id: "agent-reports",
                question: "What reports can I see?",
                answer: [
                    "Reports show only your customers. Exporting needs Admin permission.",
                    "Customer statements can cover up to 366 days.",
                ],
            },
            {
                id: "agent-notifications",
                question: "What appears in Notifications?",
                answer: [
                    "Updates about your customers, plans, collections, withdrawals and reversals, plus account and security notices.",
                    "Filter by read status, category or action required. Notices are kept for 24 months.",
                ],
            },
        ],
    },
];

const adminSections: HelpSection[] = [
    {
        id: "admin-access",
        title: "Admin team and permissions",
        audience: "admin",
        summary: "Inviting Admins and what each permission allows.",
        articles: [
            {
                id: "admin-invite",
                question: "How do I add an Admin?",
                permission: "admins.manage",
                answer: [
                    "Go to Admin team, invite them and choose their permissions. The link lasts 24 hours.",
                    "They must set up an authenticator app after activation.",
                ],
            },
            {
                id: "admin-permissions-list",
                question: "What does each permission allow?",
                answer: [
                    "Admin management: invite Admins and change their permissions or status.",
                    "Agent management: register, update, suspend and offboard Agents.",
                    "Customer management: update customers, their status and invitations. Admins never create customers.",
                    "Customer reassignment: move a customer to another Agent.",
                    "Withdrawal approval: approve, reject or revoke withdrawals and record offline payouts.",
                    "Cash execution: pay approved cash payments.",
                    "Reversal approval: approve or reject corrections.",
                    "Fee management: fee rules and fee actions. Deduction management: deductions.",
                    "Reconciliation management: review cash batches and evidence, resolve exceptions.",
                    "Booking months: open, close and reopen months.",
                    "Business configuration: business settings and collection methods.",
                    "Security operations: lockouts, customer recovery and security cases.",
                    "Activity log access and Report export.",
                ],
            },
            {
                id: "admin-safeguards",
                question: "What safeguards protect the Admin team?",
                answer: [
                    "You cannot change your own permissions or status.",
                    "There must always be at least one active Admin with Admin management.",
                    "The last active Admin cannot be suspended or deactivated.",
                ],
            },
            {
                id: "admin-sessions",
                question: "Why am I signed out so often?",
                answer: [
                    "Admins can be signed in on 1 device. You are signed out after 30 minutes without activity and after 24 hours at most.",
                ],
            },
        ],
    },
    {
        id: "admin-agents",
        title: "Managing agents",
        audience: "admin",
        summary: "Registering, pausing and offboarding Agents.",
        articles: [
            {
                id: "admin-register-agent",
                question: "How do I register an Agent?",
                permission: "agents.manage",
                answer: [
                    "Go to Agents and add name, email and phone (unique). Address, employment date, notes and photo are optional.",
                    "An invitation valid for 24 hours is sent.",
                ],
            },
            {
                id: "admin-agent-status",
                question:
                    "What is the difference between Inactive and Suspended?",
                permission: "agents.manage",
                answer: [
                    "Inactive (operational status): the Agent can sign in and view their customers but cannot record work or receive new customers.",
                    "Suspended: the Agent is signed out and blocked. Restore brings them back.",
                    "Both need a reason.",
                ],
            },
            {
                id: "admin-offboarding",
                question: "How do I offboard an Agent?",
                permission: "agents.manage",
                answer: [
                    "Start offboarding. The Agent is suspended and set to Inactive, and a case is opened.",
                    "Complete offboarding only works when everything is clear: customers moved, cash handed over and reconciled, no pending requests or invitations.",
                    "Completing deactivates the Agent. Return to service brings them back as Inactive.",
                ],
            },
            {
                id: "admin-staff-recovery",
                question:
                    "An Agent lost their password or authenticator. What do I do?",
                permission: "agents.manage",
                answer: [
                    "Create a staff recovery request. Another Admin must approve it (not the requester).",
                    "Once approved, the old login is cleared and an activation link (60 minutes) is emailed.",
                    "Admin recovery needs Admin management and two approvers when possible.",
                ],
            },
        ],
    },
    {
        id: "admin-customers",
        title: "Managing customers",
        audience: "admin",
        summary: "Customer status, archiving and reassignment.",
        articles: [
            {
                id: "admin-customer-statuses",
                question: "What does each customer status allow?",
                permission: "customers.manage",
                answer: [
                    "Active: everything.",
                    "Inactive: withdrawals, fees and corrections. No new savings or plans.",
                    "Restricted: corrections and reconciliation only. Withdrawals are put on hold.",
                    "Archived: read-only. Customers are never deleted.",
                ],
            },
            {
                id: "admin-archive",
                question: "When can I archive a customer?",
                permission: "customers.manage",
                answer: [
                    "Only when plans are closed, savings are zero, all requests are finished, fees are settled and collections are reconciled.",
                    "Restore brings the customer back as Inactive.",
                ],
            },
            {
                id: "admin-reassign",
                question: "How do I move a customer to another Agent?",
                permission: "customers.reassign",
                answer: [
                    "Use Reassign on the customer. Preview, then confirm with a reason.",
                    "The new Agent must be eligible. Pending withdrawals, reversals and recoveries move with the customer.",
                    "Cash the old Agent collected stays their responsibility.",
                ],
            },
            {
                id: "admin-registration-fee-charge",
                question: "Can I charge a registration fee?",
                permission: "fees.manage",
                answer: [
                    "Yes, but only as Pending. Only the assigned Agent can record a payment.",
                ],
            },
        ],
    },
    {
        id: "admin-fee-rules",
        title: "Fee rules",
        audience: "admin",
        summary: "Registration fees and plan service charges.",
        articles: [
            {
                id: "admin-publish-fee",
                question: "How do I publish a fee rule?",
                permission: "fees.manage",
                answer: [
                    "Go to Fees, preview the rule, then confirm and publish. Name, customer description and reason are required.",
                    "It can start now or later, never in the past. The next version starts when the previous one ends.",
                    "Existing customers keep the terms they agreed to. New rules apply to new agreements only.",
                ],
            },
            {
                id: "admin-fee-types",
                question: "What plan fee types are there?",
                permission: "fees.manage",
                answer: [
                    "Fixed or No fee: charged at first contribution, plan completion or withdrawal.",
                    "One-day: one day’s contribution, charged at first contribution or completion.",
                    "Percentage at completion: a rate of total savings.",
                    "Percentage at withdrawal: a rate of each withdrawal.",
                    "Withdrawal fees come out of the payout; the others come out of savings. Percentages round to the nearest kobo.",
                ],
            },
            {
                id: "admin-retire-fee",
                question: "How do I stop a fee rule?",
                permission: "fees.manage",
                answer: [
                    "Retire it with a reason. A rule scheduled for later must be cancelled before it starts.",
                    "Without a published registration fee rule, Agents cannot register customers.",
                ],
            },
        ],
    },
    {
        id: "admin-fee-actions",
        title: "Fee actions and charges",
        audience: "admin",
        summary: "Waiving, correcting and applying fees.",
        articles: [
            {
                id: "admin-waive-correct",
                question: "How do I waive or correct a fee?",
                permission: "fees.manage",
                answer: [
                    "Open Fees, choose the fee, prepare the action and confirm. A reason and customer description are required.",
                    "Waive: up to the unpaid amount.",
                    "Correct: reduce or increase, only if nothing was paid or waived yet.",
                ],
            },
            {
                id: "admin-apply-savings",
                question: "Can I pay a fee from the customer’s savings?",
                permission: "fees.manage",
                answer: [
                    "Yes, if available savings cover the whole fee without touching money set aside for withdrawals. The quote lasts 10 minutes.",
                    "If enabled for your business.",
                ],
            },
            {
                id: "admin-manual-charges",
                question: "What are manual charges?",
                permission: "deductions.manage",
                answer: [
                    "Publish a charge category (fee or deduction), then assess it on a customer.",
                    "Deductions need enough savings, not counting money set aside.",
                    "If enabled for your business.",
                ],
            },
        ],
    },
    {
        id: "admin-withdrawals",
        title: "Withdrawal review and payouts",
        audience: "admin",
        summary: "Approving withdrawals and recording payment.",
        articles: [
            {
                id: "admin-approve-withdrawal",
                question: "How do I review a withdrawal?",
                permission: "withdrawals.review",
                answer: [
                    "Open Withdrawals, check the request and approve or reject. You confirm your identity first.",
                    "You have 7 days to decide. Expired requests release the money automatically.",
                    "Requests on hold (Restricted customer) cannot be approved. When the hold lifts, the deadline is extended (at least 24 hours).",
                    "You can revoke an approved request that has not been paid.",
                ],
            },
            {
                id: "admin-offline-payout",
                question: "How do I record a payout?",
                permission: "withdrawals.review",
                answer: [
                    "Pay the customer outside the app, then use “Paid outside platform” on the approved request.",
                    "Enter the channel (cash or bank transfer), the reference (required for transfers), the paid-on date and a note.",
                    "The withdrawal becomes Posted. This cannot be reversed in the app.",
                    "Cash and bank payouts inside the app are not available yet.",
                ],
            },
        ],
    },
    {
        id: "admin-reversals",
        title: "Reversal review",
        audience: "admin",
        summary: "Approving corrections requested by Agents.",
        articles: [
            {
                id: "admin-review-reversal",
                question: "How do I review a reversal?",
                permission: "reversals.review",
                answer: [
                    "Open Reversals, check the reason and evidence, then approve or reject. You confirm your identity first.",
                    "Approving reverses the whole transaction. The original is never edited.",
                    "A pending correction blocks the batch review until it is decided.",
                ],
            },
        ],
    },
    {
        id: "admin-reconciliation",
        title: "Reconciliation",
        audience: "admin",
        summary: "Reviewing cash batches and handling exceptions.",
        articles: [
            {
                id: "admin-batch-review",
                question: "How do I reconcile a cash batch?",
                permission: "reconciliation.manage",
                answer: [
                    "Go to Cash batches. Batches are ready for review once their day has ended.",
                    "Record the remittance: reference, amount, date and where it was received.",
                    "Review the batch. It is reconciled if nothing is outstanding. Otherwise a cash shortage is opened.",
                ],
            },
            {
                id: "admin-exceptions",
                question: "How are exceptions handled?",
                permission: "reconciliation.manage",
                answer: [
                    "Shortages open automatically. You can also report an overage or a missing transfer.",
                    "Move them through investigating to resolved. A shortage cannot be resolved until the Agent hands over the cash.",
                ],
            },
            {
                id: "admin-evidence-review",
                question: "How do I verify transfer or POS evidence?",
                permission: "reconciliation.manage",
                answer: [
                    "Check the reference, the exact amount and the destination, then verify or reject.",
                    "Non-cash batches also need bank settlement evidence.",
                ],
            },
        ],
    },
    {
        id: "admin-booking-months",
        title: "Booking months",
        audience: "admin",
        summary: "Opening and closing months for posting.",
        articles: [
            {
                id: "admin-months",
                question: "How do booking months work?",
                permission: "financial.periods.manage",
                answer: [
                    "A month must be open before receipts can be recorded in it.",
                    "Close it after it ends, once all cash handovers and batches are reconciled.",
                    "You can reopen a closed month. Every action needs a reason.",
                ],
            },
        ],
    },
    {
        id: "admin-business-settings",
        title: "Business settings",
        audience: "admin",
        summary: "Business profile, limits, methods and features.",
        articles: [
            {
                id: "admin-change-settings",
                question: "How do I change a setting?",
                permission: "business.settings.manage",
                answer: [
                    "Edit a draft, preview it (valid 10 minutes), then publish now or schedule it.",
                    "You can cancel a scheduled change or roll back. Changes only apply from now on.",
                    "Collection limit changes take effect at a future midnight.",
                ],
            },
            {
                id: "admin-locked-settings",
                question: "Why is a setting locked?",
                permission: "business.settings.manage",
                answer: [
                    "Money features (collections, payouts, reversals, statements, exports and others) are off by default.",
                    "Each stays locked until its readiness check shows “Ready to enable”.",
                ],
            },
            {
                id: "admin-collection-methods",
                question: "How do I add a transfer or POS method?",
                permission: "business.settings.manage",
                answer: [
                    "Go to Collection methods and publish it with a reason. You confirm your identity first.",
                ],
            },
        ],
    },
    {
        id: "admin-security",
        title: "Security",
        audience: "admin",
        summary: "Lockouts, recovery, security cases and the activity log.",
        articles: [
            {
                id: "admin-unlock",
                question: "How do I unlock someone?",
                permission: "security.operations.manage",
                answer: [
                    "Go to Lockouts, choose how you checked their identity (in person, phone callback, video, documents or other) and give a reason.",
                    "You cannot unlock yourself. They get an email when unlocked.",
                ],
            },
            {
                id: "admin-customer-recovery-review",
                question: "How do I approve a customer recovery?",
                permission: "security.operations.manage",
                answer: [
                    "Open Customer recovery and review the Agent’s request. You cannot approve a request you made.",
                ],
            },
            {
                id: "admin-all-locked",
                question: "What if every Admin is locked out?",
                answer: [
                    "Use the emergency recovery key issued from the server. It works only for the original Admin email, 5 attempts per hour.",
                ],
            },
            {
                id: "admin-activity-log",
                question: "Where can I see who did what?",
                permission: "audit.view",
                answer: [
                    "The Activity log lists every important action with who did it and when.",
                ],
            },
        ],
    },
    {
        id: "admin-dashboard-reports",
        title: "Dashboard and reports",
        audience: "admin",
        summary: "Finance figures, reports and exports.",
        articles: [
            {
                id: "admin-dashboard",
                question: "What does the Admin dashboard show?",
                answer: [
                    "The same Today figures as Agents, for the whole business.",
                    "The Finance tab shows money movements, business cash and incidents.",
                    "Filter by Agent, period and status.",
                ],
            },
            {
                id: "admin-exports",
                question: "How do exports work?",
                permission: "reports.export",
                answer: [
                    "Export reports as CSV or PDF. 2 can run at once, up to 20 a day.",
                    "Files are kept for 7 days.",
                ],
            },
        ],
    },
];

export const helpSections: HelpSection[] = [...agentSections, ...adminSections];
