<?php

namespace App\Services;

use App\Support\IdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EmailReservationService
{
    public function assertAvailable(string $email, ?int $userId = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Email claims require a transaction.');
        }
        $normalized = IdentityNormalizer::normalizeEmail($email);
        DB::table('email_identity_locks')->insertOrIgnore(['normalized_email' => $normalized]);
        DB::table('email_identity_locks')->where('normalized_email', $normalized)->lockForUpdate()->firstOrFail();
        $occupied = DB::table('users')->where('email_normalized', $normalized)
            ->when($userId !== null, fn ($q) => $q->where('id', '!=', $userId))->exists();
        $pending = DB::table('pending_email_changes')->where('proposed_email_normalized', $normalized)->where('expires_at', '>', now())
            ->when($userId !== null, fn ($q) => $q->where('user_id', '!=', $userId))->exists();
        $recovery = DB::table('customer_recoveries as r')->join('customer_profiles as c', 'c.id', '=', 'r.customer_profile_id')
            ->where('r.proposed_email_normalized', $normalized)
            ->when($userId !== null, fn ($q) => $q->where('c.user_id', '!=', $userId))->exists();
        if ($occupied || $pending || $recovery) {
            throw ValidationException::withMessages(['email' => ['The email address is reserved or already in use.']]);
        }
    }
}
