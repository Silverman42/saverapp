# Module 01 — Confirmed User-Type Foundation

## Summary

Implement only Module 01 decisions that are confirmed and independently enforceable now: every account has one Customer, Agent, or Admin type, and public self-registration is unavailable. Do not introduce draft Business, account-state, permission, profile, assignment, or audit schemas.

## Implementation Changes

- Add a string-backed `App\Enums\UserType` with `Customer`, `Agent`, and `Admin` cases.
- Add a required `users.user_type` string column with no database default and cast it to `UserType` on `User`.
    - Use a new migration rather than modifying the applied base migration.
    - Fail before altering the schema if legacy users exist, preventing silent role assignment.
- Update `UserFactory` with `customer()`, `agent()`, and `admin()` states; use Customer as the least-privileged factory default.
- Remove the starter sample user from `DatabaseSeeder`; secure Business/first-Admin provisioning remains deferred.
- Disable Fortify registration, remove the registration action/view wiring and unused registration page, and remove sign-up links from the welcome and login pages.
- Update the Inertia TypeScript contract with `UserType = 'customer' | 'agent' | 'admin'` and required `user_type`.
- Expand Module 01 in `docs/v2/tasks.md`, preserving the original row:
    - Mark user-type persistence, closed registration, and frontend contract tasks according to verification evidence.
    - Record Business/first-Admin bootstrap and resource authorization as `Blocked` on confirmed Modules 02, 03, 04, 14, and 15 decisions.

## Public Interfaces

- PHP: `UserType::Customer`, `UserType::Agent`, `UserType::Admin`.
- Database: required `users.user_type` containing `customer`, `agent`, or `admin`.
- Eloquent: `$user->user_type` returns `UserType`.
- Factories: `User::factory()->customer()`, `agent()`, and `admin()`.
- TypeScript: authenticated users expose a required `user_type: UserType`.
- HTTP: `GET /register` and `POST /register` are unavailable.

## Test Plan

- Test all three factory states persist and cast to the correct enum.
- Test the default factory produces a Customer account.
- Repurpose the existing registration tests to verify GET/POST registration return 404, create no user, and leave the requester unauthenticated.
- Verify an authenticated Inertia response serializes `user_type` as its backed string value.
- Run the focused Pest tests, `npm run check`, `npm run types:check`, `npm run build`, PHP static analysis, and `vendor/bin/pint --dirty --format agent`.
- Confirm `php artisan route:list --path=register` returns no registration routes, then ask for the complete `php artisan test --compact` suite before promoting remaining register tasks to `Completed`.

## Assumptions and Deferred Work

- The repository is a greenfield V2 installation; the inspected database currently has no users. Any environment with existing users must classify them explicitly before this migration.
- No role-changing API or generic role middleware is introduced; Module 03 will own authorization enforcement.
- Account states and MFA remain with Module 02; Customer/Agent profiles and assignments with Module 04; audit storage with Module 14; singleton Business and secure first-Admin provisioning with Modules 02, 03, and 15.
- The generic dashboard remains unchanged because role-specific dashboards belong to later modules.
