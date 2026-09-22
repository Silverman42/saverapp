<?php

namespace App\Services;

use App\Exceptions\InvitationSenderNotReadyException;
use App\Models\BusinessProfile;

class InvitationSenderReadinessService
{
    /**
     * Placeholder domains and addresses that are strictly rejected as unready.
     */
    protected const array PLACEHOLDER_ADDRESSES = [
        'hello@example.com',
    ];

    protected const array PLACEHOLDER_DOMAINS = [
        'example.com',
        'localhost',
    ];

    /**
     * Determine if invitation sender readiness is verified and usable.
     */
    public function isReady(?BusinessProfile $business = null): bool
    {
        $business ??= BusinessProfile::current();

        $senderEmail = $this->resolveSenderEmail($business);

        if (blank($senderEmail)) {
            return false;
        }

        if ($this->isPlaceholderEmail($senderEmail)) {
            return false;
        }

        return $business->is_invitation_sender_verified
            || (bool) config('mail.invitation_sender.verified', false);
    }

    /**
     * Ensure invitation sender readiness or throw an explicit exception.
     *
     * @throws InvitationSenderNotReadyException
     */
    public function ensureReady(?BusinessProfile $business = null): void
    {
        $business ??= BusinessProfile::current();

        $senderEmail = $this->resolveSenderEmail($business);

        if (blank($senderEmail)) {
            throw new InvitationSenderNotReadyException(
                "Invitation sender is not configured for {$business->display_name}."
            );
        }

        if ($this->isPlaceholderEmail($senderEmail)) {
            throw new InvitationSenderNotReadyException(
                "Placeholder mail identity [{$senderEmail}] cannot be used for registration invitations. Verified invitation sender is required."
            );
        }

        if (! $this->isReady($business)) {
            throw new InvitationSenderNotReadyException(
                "Invitation sender [{$senderEmail}] has not been verified for {$business->display_name}."
            );
        }
    }

    /**
     * Resolve the configured invitation sender address.
     */
    public function resolveSenderEmail(?BusinessProfile $business = null): ?string
    {
        $business ??= BusinessProfile::current();

        $explicitBusinessEmail = $business->invitation_sender_email;
        if (filled($explicitBusinessEmail)) {
            return trim($explicitBusinessEmail);
        }

        $configEmail = config('mail.invitation_sender.address') ?? config('mail.from.address');

        return filled($configEmail) ? trim((string) $configEmail) : null;
    }

    /**
     * Resolve the configured invitation sender display name.
     */
    public function resolveSenderName(?BusinessProfile $business = null): string
    {
        $business ??= BusinessProfile::current();

        return $business->invitation_sender_name
            ?? config('mail.invitation_sender.name')
            ?? $business->display_name;
    }

    /**
     * Check whether an email is an unready placeholder.
     */
    protected function isPlaceholderEmail(string $email): bool
    {
        $normalized = strtolower(trim($email));

        if (in_array($normalized, self::PLACEHOLDER_ADDRESSES, true)) {
            return true;
        }

        $parts = explode('@', $normalized);
        $domain = end($parts);

        return in_array($domain, self::PLACEHOLDER_DOMAINS, true);
    }
}
