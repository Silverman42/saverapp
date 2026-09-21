# AUTHZ-T01 & AUTHZ-T06 — Spatie Laravel Permission Setup, Custom Permission Model, and Closed Catalogue

## Summary

Implement `AUTHZ-T01` and `AUTHZ-T06` as scoped in Module 03 Sections 4, 7.2, 7.5, 11, 14, 15.1, and requirements `AUTHZ-002`, `AUTHZ-009`, `AUTHZ-010`:

1. Install `spatie/laravel-permission:^8.0` and commit composer manifest/lock updates.
2. Publish and configure `config/permission.php` (`web` guard, `register_permission_check_method => false`, disabled teams, disabled wildcards, hidden exception names, enabled events, custom Permission model class).
3. Create standard Spatie tables with custom metadata columns on `permissions` (`display_name`, `description`, `status`, `introduced_at`, `retired_at`).
4. Define `App\Enums\AdminPermission` with all 13 canonical permissions, display names, and descriptions from Section 7.2.
5. Implement `App\Models\Permission` extending Spatie's model with active/retired scopes and status helpers.
6. Seed the closed 13-permission catalogue into `permissions` table and clear `PermissionRegistrar` cache.
7. Add `HasRoles` trait to `App\Models\User`.
8. Document mandatory Spatie role and permission conventions in `AGENTS.md`.

## Implementation Details

### 1. Package Installation & Configuration

- Require `spatie/laravel-permission:^8.0`.
- Publish and configure `config/permission.php`:
    - `models.permission => App\Models\Permission::class`
    - `default_guard => 'web'`
    - `register_permission_check_method => false`
    - `teams => false`
    - `enable_wildcard_permission => false`
    - `display_permission_in_exception => false`
    - `display_role_in_exception => false`
    - `events_enabled => true`

### 2. Database Migrations

- `database/migrations/2026_09_20_220000_create_permission_tables.php`:
    - Creates `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`.
    - Enhances `permissions` table with `display_name` (string), `description` (text), `status` (string, default 'active', indexed), `introduced_at` (timestamp), and `retired_at` (timestamp, nullable).
- `database/migrations/2026_09_20_221000_seed_permission_catalogue.php`:
    - Seeds all 13 `AdminPermission` records with display names and descriptions, active status, `web` guard, and clears cached permissions.

### 3. Domain Model & Enum

- `App\Enums\AdminPermission`:
    - `AdminsManage = 'admins.manage'`
    - `AgentsManage = 'agents.manage'`
    - `CustomersManage = 'customers.manage'`
    - `CustomersReassign = 'customers.reassign'`
    - `WithdrawalsReview = 'withdrawals.review'`
    - `ReversalsReview = 'reversals.review'`
    - `FeesManage = 'fees.manage'`
    - `DeductionsManage = 'deductions.manage'`
    - `ReconciliationManage = 'reconciliation.manage'`
    - `BusinessSettingsManage = 'business.settings.manage'`
    - `SecurityOperationsManage = 'security.operations.manage'`
    - `AuditView = 'audit.view'`
    - `ReportsExport = 'reports.export'`
    - Methods: `displayName(): string`, `description(): string`, `values(): array`.
- `App\Models\Permission`:
    - Extends `Spatie\Permission\Models\Permission`.
    - Scopes: `scopeActive`, `scopeRetired`.
    - Helpers: `isActive(): bool`, `isRetired(): bool`.
- `App\Models\User`:
    - Adds `HasRoles` trait.

### 4. `AGENTS.md` Rules (AUTHZ-T06)

- Add `=== spatie/core rules ===` section covering:
    - Mandatory use of `spatie/laravel-permission` for role/permission persistence and assignment.
    - Immutability of `user_type` matching exactly one Spatie `web` role.
    - Granular Admin permissions as direct user grants, never inherited from the Admin role.
    - Permission codes backed by `AdminPermission` enum; wildcard permissions and super-Admin bypasses prohibited.
    - All permission mutations through the application permission-management service.
    - Controllers/frontend authorizing through Laravel Gates/policies rather than `hasDirectPermission()`.
    - Mandatory resource scope and separation-of-duty checks alongside permission checks.
    - Direct catalogue/backfill database operations clearing `PermissionRegistrar` cache.
    - Frontend permission checks being presentational only.

## Verification

- Feature tests in `tests/Feature/Authz/PermissionCatalogueTest.php`.
- Full test suite via `php artisan test --compact`.
- Static analysis via `composer types:check`.
- Formatting via `vendor/bin/pint --format agent`.
