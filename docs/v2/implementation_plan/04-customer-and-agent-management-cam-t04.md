# CAM-T04 — Customer and Agent Directories and Profiles

## Summary

Build read-only, scoped directories and profile pages on the completed CAM-T03 assignment and authorization foundation. Financial, plan, and invitation information shows an explicit unavailable state until its owning module provides an authoritative contract.

## Routes, Scope, and Data

- Add named GET routes for Customer and Agent directories and profiles. Agents and Admins can open the Customer directory; only Admins can open the Agent directory. Customers can open only their own Customer profile, and Agents can open only their own Agent profile.
- Resolve public Customer and Agent IDs through `ResourceScopeService` before loading a profile. Missing and unauthorized IDs return the same generic Record unavailable response. Apply the same scope to photo and nested read requests.
- Build server-side search, filters, matching counts, stable sorting, and 25/50/100 pagination from scoped queries. Default lists exclude archived Customers and deactivated Agent accounts; explicit filters can include them without expanding the viewer's scope.
- Support available operational and account-status filters, assignment and Agent-eligibility filters, current non-archived assigned-Customer counts, and registered-date ranges. Normalize exact email, phone, and reference searches; allow case-insensitive name/reference and formatted partial-phone searches. Validate date ranges and allowlist sort fields and directions.
- Interpret inclusive registered-date filters and display dates in `Africa/Lagos`, the documented initial business timezone, until Module 15 supplies the setting. Convert local boundaries to UTC for database queries.
- Show invitation and active-plan filters as unavailable until their owning modules provide the necessary contracts. Do not derive invitation progress from account state or infer that missing plan data means no active plan.
- Return explicit viewer-specific Inertia props. Omit Customer notes from Customer responses, Agent notes from Agent self-service, normalized identifiers, security details, and privileged history. Derive Agent assignment counts from current assignments, separating archived Customers. Use `AgentEligibilityService` for displayed readiness and ensure eligibility filtering applies before counts and pagination.

## Pages and Navigation

- Build responsive Inertia Vue directories and profiles with separate operational and account-state labels, accessible search and filter controls, Previous/Next pagination, and distinct empty-scope, no-match, loading, revoked-access, and dependency-unavailable states.
- Preserve list search, filter, sort, and page state in the URL when opening a profile and returning. Every server visit revalidates scope; stale unauthorized personal data must not remain visible after access is revoked.
- Use Wayfinder route functions, existing UI components, and the shared page-header and breadcrumb conventions. Show only working read links; registration, editing, reassignment, and financial actions remain unavailable until their owning tasks implement them.
- Display profile-photo fallbacks and serve stored photos only through scoped, authenticated endpoints. Change future photo storage to a nonpublic disk; the current database has no Customer or Agent profiles requiring a file migration.
- Present financial summaries, plans, transactions, requests, and statements as explicitly unavailable, without guessed counts, zero balances, or enabled dependent actions.

## Test Plan

- Add focused Pest feature tests for every viewer role, direct-ID denial, former-Agent access loss, scoped search/count/filter/pagination, stable sort ties, date boundaries, private-prop omission, Agent assignment counts, and protected photo access.
- Check responsive and keyboard navigation, distinct empty/unavailable states, URL return state, and stale-access handling.
- Run focused tests, `npm run check`, the frontend build, PHPStan, and `vendor/bin/pint --dirty --format agent` for changed PHP. After focused tests pass, ask the user to run the complete compact test suite as required by the project rules.
- After verification, update the Module 04 implementation plan and task register with CAM-T04 evidence and status.

## Assumptions and Deferred Work

- CAM-T04 introduces no registration or profile mutations and no new dependencies.
- Financial values, plan details, invitation progress, and their dependent actions remain explicitly unavailable until authoritative contracts exist.
- `Africa/Lagos` is the interim business timezone, as selected for this plan.
