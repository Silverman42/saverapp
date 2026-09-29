# V2 Module Implementation Workflow

Use this workflow for every remaining implementation task in [the V2 register](./tasks.md). The checkpoints are required; the depth of planning and verification depends on the change. The module specifications remain the source of truth, and `AGENTS.md` and matching `.ai/rules` still govern the work.

## 1. Define a focused task

1. Select one register entry and identify its task ID or name, specification sections, functional requirements, acceptance scenarios, current status, and evidence or blocker. Do not infer that an `Implemented`, `Done`, or partial status means acceptance passed.
2. Read the owning module specification and the dependencies named in its register header before changing the task. Use its existing implementation plan, when present, to find settled decisions and unresolved gates. After finding the relevant sections, keep subsequent searches focused on them and the code they reference.
3. Write a brief in the task or implementation plan with the intended result, acceptance criteria, files or areas likely to change, explicit boundaries, a comparable existing implementation, dependency gates, and the narrowest meaningful checks. Name unknowns that could change the design.
4. For substantial or cross-cutting work, settle the approach in a separate implementation plan before coding. For a small change, use the brief directly. Do not reopen settled architecture without a concrete conflict or blocker.

## 2. Gather only necessary context

- Read `.ai/rules/index.md`, every rule matching the paths in scope, and search the rules for relevant terms. Activate the project skills required for the domains touched. Check sibling files and existing components or services before adding a new pattern.
- Prefer Laravel Boost for application facts: schema before model or migration work, read-only database queries, recent logs for debugging, and URL resolution before sharing an app URL. Confirm installed package versions before relying on APIs. Use scoped `search-docs` queries when Laravel ecosystem behavior or syntax matters; reuse sufficient results already gathered.
- Start with the files named in the brief. Follow references or broaden scope when a real dependency requires it, then update the brief. Avoid repository-wide reads, repeated searches, and unrelated modules.

## 3. Implement and verify

- Make the smallest change that meets the acceptance criteria. Reuse existing contracts and components. Keep unsupported owner behavior unavailable or blocked until its authoritative dependency exists. Do not add dependencies or new base directories without the approval required by `AGENTS.md`.
- Add or update meaningful regression tests for behavior changes, including important failure modes. Run the affected tests first and rerun a changed test. Run `vendor/bin/pint --dirty --format agent` after PHP edits; run the relevant frontend check or build when frontend code changes. Broaden verification only for a concrete cross-module risk or a required checkpoint.
- Review the diff for scope, accidental changes, and claims of completion. Update the register's status and evidence when implementation actually begins or advances; keep blocked acceptance scenarios explicit. After feature tests pass, follow `AGENTS.md` by asking the user to run `php artisan test --compact` for the complete PHP suite.
- Report the result, significant decisions, checks run, and remaining blockers. Do not claim a task `Completed` while required acceptance criteria, tests, or dependencies are unresolved.

## Task brief template

```text
Task: [register entry and ID, if present]
Result: [observable outcome and acceptance criteria]
Source: [module sections, requirement/scenario IDs, and settled plan]
Scope: [likely files or areas; excluded work]
Reference: [existing implementation to follow]
Dependencies: [owner contracts, decisions, and blockers]
Verification: [focused tests, required format/type/build checks, and checkpoint gate]
```

## Reusable task prompt

```text
Implement [one V2 register task]. Follow docs/v2/implementation-workflow.md.
Start from [register entry] and [module/plan sections]. Use [existing implementation] as the reference.
Keep work within [areas] unless a necessary dependency requires expansion. Preserve [named owner gates].
Use the smallest valid change, run [focused checks], review the diff, and update register evidence truthfully.
Report the result, tests, and unresolved acceptance or dependency gates.
```

## Task setup guidance

For routine work, choose a lower-cost model or reasoning level when starting the task; reserve deeper reasoning for architecture, security, concurrency, and difficult debugging. Check usage periodically, and start a fresh chat at a logical module boundary when useful. These are user-controlled setup choices, not conditions for completing a task. Use parallel agents only when explicitly requested and the work is genuinely independent.
