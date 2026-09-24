<?php

namespace App\Data;

class FeePosition
{
    public function __construct(
        public readonly string $outstandingStatus,
        public readonly ?int $outstandingFeeKobo,
        public readonly string $refundPayableStatus,
        public readonly ?int $refundPayableKobo,
        public readonly string $lifecycleGateStatus,
        public readonly string $lifecycleGateMessage,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'outstanding_status' => $this->outstandingStatus,
            'outstanding_fee_kobo' => $this->outstandingFeeKobo,
            'refund_payable_status' => $this->refundPayableStatus,
            'refund_payable_kobo' => $this->refundPayableKobo,
            'lifecycle_gate_status' => $this->lifecycleGateStatus,
            'lifecycle_gate_message' => $this->lifecycleGateMessage,
        ];
    }
}
