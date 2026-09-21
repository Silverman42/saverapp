<?php

namespace App\Enums;

enum UnlockVerificationMethod: string
{
    case InPerson = 'in_person';
    case VerifiedPhoneCallback = 'verified_phone_callback';
    case ApprovedVideoCall = 'approved_video_call';
    case ApprovedDocuments = 'approved_documents';
    case OtherApprovedMethod = 'other_approved_method';

    /**
     * Get the human-readable display label for this verification method.
     */
    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'In-person verification',
            self::VerifiedPhoneCallback => 'Verified phone callback',
            self::ApprovedVideoCall => 'Approved video call',
            self::ApprovedDocuments => 'Approved documents',
            self::OtherApprovedMethod => 'Other approved method',
        };
    }
}
