# CAM-T01 & CAM-T02 — Customer & Agent Foundations, Profile Persistence, and Uniqueness

## Summary

Implement `CAM-T01` and `CAM-T02` as scoped in Module 04 Sections 2, 7, 18, and functional requirements `CAM-FR-001`–`CAM-FR-004` / acceptance scenarios `CAM-AC-001`–`CAM-AC-004`:

1. **CAM-T01**: Confirm all 12 proposed product decisions in Section 18 as the binding specification baseline, updating `docs/v2/modules/04-customer-and-agent-management.md`, `docs/v2/tasks.md`, and `docs/v2/implementation_plan/04-customer-and-agent-management.md`.
2. **CAM-T02**:
   - Add enums `CustomerStatus` (`active`, `inactive`, `restricted`, `archived`), `AgentStatus` (`active`, `inactive`), and `Gender` (`female`, `male`, `other`, `prefer_not_to_say`).
   - Create migrations for `public_id_sequences`, `customer_profiles`, and `agent_profiles`.
   - Implement `PublicIdGenerator` to generate immutable, non-reused public IDs (`CUS-000001` and `AGT-000001`) with sequence locking.
   - Implement phone normalization and validation support in `PhoneNormalizer` (defaulting to Nigeria `+234`, rejecting invalid phone numbers, supporting country prefixing, local formats, parentheses, hyphens, and whitespace).
   - Implement `CustomerProfile` and `AgentProfile` models with one-to-one `User` relationships, immutable ID and `user_id` guards, optimistic concurrency `version`, and actor attribution (`created_by_user_id`, `updated_by_user_id`).
   - Implement structured next-of-kin validation and casting on `CustomerProfile`.
   - Implement case-insensitive uniqueness for Customer `internal_reference`.
   - Implement photo validation support in `ProfilePhotoService` (JPEG/PNG/WebP, max 5MB, 100x100 to 4096x4096).
   - Provide `CustomerProfileFactory` and `AgentProfileFactory` with realistic states.
   - Wire `customerProfile` and `agentProfile` relationships on `User` and protect users with profile records from deletion.

## Implementation Details

### 1. Enums
- `App\Enums\CustomerStatus`:
  - Cases: `Active = 'active'`, `Inactive = 'inactive'`, `Restricted = 'restricted'`, `Archived = 'archived'`.
  - Methods: `displayName(): string`, `canTransact(): bool`, `allowsWithdrawalOfExistingFunds(): bool`.
- `App\Enums\AgentStatus`:
  - Cases: `Active = 'active'`, `Inactive = 'inactive'`.
  - Methods: `displayName(): string`, `isEligible(): bool`.
- `App\Enums\Gender`:
  - Cases: `Female = 'female'`, `Male = 'male'`, `Other = 'other'`, `PreferNotToSay = 'prefer_not_to_say'`.
  - Methods: `displayName(): string`.

### 2. Public ID Sequences & Generator
- `database/migrations/2026_09_21_030000_create_public_id_sequences_table.php`:
  - `entity_type` (string, primary key)
  - `prefix` (string, e.g. `CUS-`, `AGT-`)
  - `next_number` (unsignedBigInteger, default 1)
- `App\Services\PublicIdGenerator`:
  - Atomic, pessimistic-locking sequence generator.
  - Formats numbers with `%06d` (e.g. `CUS-000001`, expanding naturally beyond 6 digits).

### 3. Normalization and Validation Support
- `App\Support\PhoneNormalizer`:
  - Validates and normalizes phone numbers for Nigeria (+234) default and international E.164 formats.
  - Strips spaces, hyphens, dots, and parentheses.
  - Converts leading 0 in Nigerian numbers (e.g. `08012345678`) to `+2348012345678`.
  - Rejects malformed numbers and numbers with invalid digit counts.
- `App\Support\InternalReferenceNormalizer`:
  - Trims and normalizes internal references for case-insensitive unique comparison.

### 4. Database Migrations for Profiles
- `database/migrations/2026_09_21_031000_create_customer_profiles_table.php`:
  - `id`: bigIncrements
  - `user_id`: foreignId constrained to `users` with `restrictOnDelete()`, unique
  - `customer_id`: string(32), unique, indexed (immutable public reference)
  - `phone`: string(50)
  - `phone_normalized`: string(50), unique, indexed
  - `address`: text, nullable (max 500)
  - `gender`: string(30), nullable
  - `occupation`: string(100), nullable
  - `photo_path`: string, nullable
  - `notes`: text, nullable (max 2000, internal operational notes)
  - `internal_reference`: string(50), nullable
  - `internal_reference_normalized`: string(50), nullable, unique, indexed
  - `next_of_kin`: json, nullable
  - `operational_status`: string(30), default 'active', indexed
  - `version`: unsignedInteger, default 1
  - `created_by_user_id`: foreignId constrained to `users`, nullable
  - `updated_by_user_id`: foreignId constrained to `users`, nullable
  - `timestamps`
- `database/migrations/2026_09_21_032000_create_agent_profiles_table.php`:
  - `id`: bigIncrements
  - `user_id`: foreignId constrained to `users` with `restrictOnDelete()`, unique
  - `agent_id`: string(32), unique, indexed (immutable public reference)
  - `phone`: string(50)
  - `phone_normalized`: string(50), unique, indexed
  - `address`: text, nullable (max 500)
  - `profile_photo_path`: string, nullable
  - `employment_date`: date, nullable
  - `notes`: text, nullable (max 2000)
  - `operational_status`: string(30), default 'inactive', indexed
  - `version`: unsignedInteger, default 1
  - `created_by_user_id`: foreignId constrained to `users`, nullable
  - `updated_by_user_id`: foreignId constrained to `users`, nullable
  - `timestamps`

### 5. Eloquent Models
- `App\Models\CustomerProfile`:
  - Immutability checks on `customer_id` and `user_id`.
  - Casts: `operational_status => CustomerStatus::class`, `gender => Gender::class`, `next_of_kin => 'array'`, `version => 'integer'`.
  - Relations: `user()`, `createdBy()`, `updatedBy()`.
- `App\Models\AgentProfile`:
  - Immutability checks on `agent_id` and `user_id`.
  - Casts: `operational_status => AgentStatus::class`, `employment_date => 'date'`, `version => 'integer'`.
  - Relations: `user()`, `createdBy()`, `updatedBy()`.
- `App\Models\User`:
  - Relations: `customerProfile(): HasOne`, `agentProfile(): HasOne`.
  - Guard `hasHistoricalAttribution()` to include presence of profile records.

### 6. Factories
- `Database\Factories\CustomerProfileFactory`:
  - Default: Active, valid Nigerian phone, valid public customer ID.
  - States: `inactive()`, `restricted()`, `archived()`, `withNextOfKin()`, `withInternalReference()`.
- `Database\Factories\AgentProfileFactory`:
  - Default: Inactive (Section 7 default), valid phone, valid public agent ID.
  - States: `active()`, `withEmploymentDate()`.

### 7. Verification Plan
- Pest feature tests in `tests/Feature/CustomerAndAgent/ProfileFoundationTest.php`:
  - `CAM-AC-001`: Customer creation with required-only vs full optional fields, Unicode/single-word name, public ID `CUS-000001`.
  - `CAM-AC-002`: Agent registration with required-only vs optional engagement date; phone uniqueness within Agent scope only (Customer and Agent can share a phone without collision).
  - `CAM-AC-003`: Normalized email/phone/internal reference variants; inactive/archived/deactivated uniqueness preservation.
  - `CAM-AC-004`: Missing required fields, boundaries, next-of-kin partial validation, phone normalization and invalid formats, photo constraints.
  - Model immutability enforcement for public IDs and user linkages.
