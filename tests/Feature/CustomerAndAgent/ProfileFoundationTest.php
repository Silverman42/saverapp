<?php

use App\Enums\AccountState;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Enums\Gender;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\ProfilePhotoService;
use App\Services\PublicIdGenerator;
use App\Support\InternalReferenceNormalizer;
use App\Support\PhoneNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    //
});

test('public ID generator generates sequential 6-digit references with correct prefixes', function (): void {
    $generator = app(PublicIdGenerator::class);

    $cus1 = $generator->generateForCustomer();
    $cus2 = $generator->generateForCustomer();
    $agt1 = $generator->generateForAgent();
    $agt2 = $generator->generateForAgent();

    expect($cus1)->toBe('CUS-000001')
        ->and($cus2)->toBe('CUS-000002')
        ->and($agt1)->toBe('AGT-000001')
        ->and($agt2)->toBe('AGT-000002');
});

test('CAM-AC-001: customer profile can be created with required fields only and with optional fields', function (): void {
    $user1 = User::factory()->customer()->create(['name' => 'Chukwudi']);
    $profile1 = CustomerProfile::factory()->create([
        'user_id' => $user1->id,
        'phone' => '08012345678',
        'address' => null,
        'gender' => null,
        'occupation' => null,
        'photo_path' => null,
        'notes' => null,
        'internal_reference' => null,
        'next_of_kin' => null,
    ]);

    expect($profile1->customer_id)->toStartWith('CUS-')
        ->and($profile1->phone_normalized)->toBe('+2348012345678')
        ->and($profile1->operational_status)->toBe(CustomerStatus::Active)
        ->and($profile1->address)->toBeNull()
        ->and($profile1->next_of_kin)->toBeNull()
        ->and($user1->name)->toBe('Chukwudi');

    $user2 = User::factory()->customer()->create(['name' => "O'Connor-Smith"]);
    $profile2 = CustomerProfile::factory()->withNextOfKin([
        'full_name' => 'Amara Okafor',
        'relationship' => 'Sister',
        'phone' => '08098765432',
        'address' => '14 Victoria Island, Lagos',
    ])->create([
        'user_id' => $user2->id,
        'phone' => '+2348022223333',
        'address' => '42 Broad Street, Lagos',
        'gender' => Gender::Female,
        'occupation' => 'Trader',
        'notes' => 'Important operational note',
        'internal_reference' => 'REF/2026/001',
    ]);

    expect($profile2->gender)->toBe(Gender::Female)
        ->and($profile2->occupation)->toBe('Trader')
        ->and($profile2->notes)->toBe('Important operational note')
        ->and($profile2->internal_reference)->toBe('REF/2026/001')
        ->and($profile2->internal_reference_normalized)->toBe('ref/2026/001')
        ->and($profile2->next_of_kin)->toBeArray()
        ->and($profile2->next_of_kin['full_name'])->toBe('Amara Okafor')
        ->and($profile2->next_of_kin['relationship'])->toBe('Sister')
        ->and($profile2->next_of_kin['phone_normalized'])->toBe('+2348098765432');
});

test('CAM-AC-002: agent profile defaults to inactive and enforces agent-scoped phone uniqueness', function (): void {
    $user1 = User::factory()->agent()->create(['name' => 'Agent One']);
    $agent1 = AgentProfile::factory()->create([
        'user_id' => $user1->id,
        'phone' => '08033334444',
        'employment_date' => null,
    ]);

    expect($agent1->agent_id)->toStartWith('AGT-')
        ->and($agent1->operational_status)->toBe(AgentStatus::Inactive)
        ->and($agent1->employment_date)->toBeNull()
        ->and($agent1->phone_normalized)->toBe('+2348033334444');

    // Customer can share the same phone without collision
    $customerUser = User::factory()->customer()->create();
    $customer = CustomerProfile::factory()->create([
        'user_id' => $customerUser->id,
        'phone' => '08033334444',
    ]);
    expect($customer->phone_normalized)->toBe('+2348033334444');

    // Another Agent cannot have the same phone
    $user2 = User::factory()->agent()->create(['name' => 'Agent Two']);
    expect(function () use ($user2): void {
        AgentProfile::factory()->create([
            'user_id' => $user2->id,
            'phone' => '+2348033334444',
        ]);
    })->toThrow(QueryException::class);
});

test('CAM-AC-003: normalized phone and internal reference collide across format variants and retained statuses', function (): void {
    $customerUser1 = User::factory()->customer()->create();
    $profile1 = CustomerProfile::factory()->create([
        'user_id' => $customerUser1->id,
        'phone' => '(080) 5555-6666',
        'internal_reference' => 'ACC-999',
        'operational_status' => CustomerStatus::Archived,
    ]);

    expect($profile1->phone_normalized)->toBe('+2348055556666')
        ->and($profile1->internal_reference_normalized)->toBe('acc-999');

    // Same normalized phone on another Customer fails even though first is Archived
    $customerUser2 = User::factory()->customer()->create();
    expect(function () use ($customerUser2): void {
        CustomerProfile::factory()->create([
            'user_id' => $customerUser2->id,
            'phone' => '+2348055556666',
        ]);
    })->toThrow(QueryException::class);

    // Same normalized internal reference on another Customer fails even though casing differs
    $customerUser3 = User::factory()->customer()->create();
    expect(function () use ($customerUser3): void {
        CustomerProfile::factory()->create([
            'user_id' => $customerUser3->id,
            'phone' => '08077778888',
            'internal_reference' => 'acc-999',
        ]);
    })->toThrow(QueryException::class);
});

test('CAM-AC-004: invalid inputs, partial next of kin, and invalid references are rejected', function (): void {
    // Invalid phone number
    expect(PhoneNormalizer::isValid('invalid'))->toBeFalse()
        ->and(PhoneNormalizer::isValid('12345'))->toBeFalse()
        ->and(PhoneNormalizer::normalize('invalid'))->toBeNull();

    // Partial next-of-kin rejects
    expect(function (): void {
        CustomerProfile::sanitizeNextOfKin([
            'full_name' => 'Incomplete Contact',
            'relationship' => '',
            'phone' => '',
        ]);
    })->toThrow(InvalidArgumentException::class);

    // Empty next-of-kin returns null
    expect(CustomerProfile::sanitizeNextOfKin([
        'full_name' => '',
        'relationship' => '',
        'phone' => '',
        'address' => '',
    ]))->toBeNull();

    // Invalid internal reference characters
    expect(InternalReferenceNormalizer::isValid('REF with spaces'))->toBeFalse()
        ->and(InternalReferenceNormalizer::isValid('REF@123'))->toBeFalse()
        ->and(InternalReferenceNormalizer::isValid(str_repeat('A', 51)))->toBeFalse()
        ->and(InternalReferenceNormalizer::isValid('VALID-REF_123/A'))->toBeTrue();
});

test('CAM-AC-004: photo validator enforces formats, dimensions, and file size limits', function (): void {
    $service = app(ProfilePhotoService::class);

    // Oversized photo > 5MB
    $oversizedFile = UploadedFile::fake()->image('large.jpg')->size(5121);
    expect(fn () => $service->validatePhoto($oversizedFile))
        ->toThrow(ValidationException::class);

    // Unsupported format (e.g. gif or pdf)
    $gifFile = UploadedFile::fake()->create('avatar.gif', 200, 'image/gif');
    expect(fn () => $service->validatePhoto($gifFile))
        ->toThrow(ValidationException::class);

    // Dimensions below 100x100
    $tooSmallFile = UploadedFile::fake()->image('small.jpg', 99, 99);
    expect(fn () => $service->validatePhoto($tooSmallFile))
        ->toThrow(ValidationException::class);

    // Dimensions above 4096x4096 (width > 4096)
    $tooLargeDimFile = UploadedFile::fake()->image('wide.jpg', 4097, 100);
    expect(fn () => $service->validatePhoto($tooLargeDimFile))
        ->toThrow(ValidationException::class);

    // Valid JPEG 500x500
    $validJpg = UploadedFile::fake()->image('valid.jpg', 500, 500);
    expect(fn () => $service->validatePhoto($validJpg))->not->toThrow(ValidationException::class);

    // Valid PNG 200x200
    $validPng = UploadedFile::fake()->image('valid.png', 200, 200);
    expect(fn () => $service->validatePhoto($validPng))->not->toThrow(ValidationException::class);

    // Valid WebP 300x300
    $validWebp = UploadedFile::fake()->image('valid.webp', 300, 300);
    expect(fn () => $service->validatePhoto($validWebp))->not->toThrow(ValidationException::class);
});

test('CAM-FR-004: public IDs and user linkage are immutable', function (): void {
    $customer = CustomerProfile::factory()->create();
    $agent = AgentProfile::factory()->create();

    expect(function () use ($customer): void {
        $customer->customer_id = 'CUS-999999';
        $customer->save();
    })->toThrow(RuntimeException::class, 'Cannot change immutable customer_id.');

    $customer->refresh();

    expect(function () use ($customer): void {
        $customer->user_id = 9999;
        $customer->save();
    })->toThrow(RuntimeException::class, 'Cannot change user_id on an existing customer profile.');

    expect(function () use ($agent): void {
        $agent->agent_id = 'AGT-999999';
        $agent->save();
    })->toThrow(RuntimeException::class, 'Cannot change immutable agent_id.');

    $agent->refresh();

    expect(function () use ($agent): void {
        $agent->user_id = 9999;
        $agent->save();
    })->toThrow(RuntimeException::class, 'Cannot change user_id on an existing agent profile.');
});

test('user with customer or agent profile cannot be deleted due to attribution rules', function (): void {
    $customer = CustomerProfile::factory()->create();
    $user = $customer->user;

    expect($user->hasHistoricalAttribution())->toBeTrue()
        ->and(fn () => $user->delete())
        ->toThrow(RuntimeException::class, 'Cannot delete user with historical attribution; deactivate the account instead.');

    $agent = AgentProfile::factory()->create();
    $agentUser = $agent->user;

    expect($agentUser->hasHistoricalAttribution())->toBeTrue()
        ->and(fn () => $agentUser->delete())
        ->toThrow(RuntimeException::class, 'Cannot delete user with historical attribution; deactivate the account instead.');
});

test('agent profile eligibility correctly derives from active operational status and active MFA account', function (): void {
    $agentUser = User::factory()->agent()->create([
        'account_state' => AccountState::Active,
        'two_factor_secret' => 'secret',
        'two_factor_confirmed_at' => now(),
    ]);

    $agentProfile = AgentProfile::factory()->create([
        'user_id' => $agentUser->id,
        'operational_status' => AgentStatus::Inactive,
    ]);

    // Inactive agent profile is not eligible
    expect($agentProfile->isEligible())->toBeFalse();

    // Operational active makes them eligible
    $agentProfile->operational_status = AgentStatus::Active;
    $agentProfile->save();
    expect($agentProfile->isEligible())->toBeTrue();

    // If account state changes to Suspended, eligibility becomes false
    $agentUser->account_state = AccountState::Suspended;
    $agentUser->save();
    $agentProfile->refresh();
    expect($agentProfile->isEligible())->toBeFalse();
});
