<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PublicIdGenerator
{
    /**
     * Generate an immutable public ID for a Customer (format: CUS-000001).
     */
    public function generateForCustomer(): string
    {
        return $this->generate('customer');
    }

    /**
     * Generate an immutable public ID for an Agent (format: AGT-000001).
     */
    public function generateForAgent(): string
    {
        return $this->generate('agent');
    }

    /**
     * Generate an immutable public ID for a thrift plan (format: PLN-000001).
     */
    public function generateForPlan(): string
    {
        return $this->generate('plan');
    }

    /**
     * Generate a public ID atomically for an entity type.
     */
    public function generate(string $entityType): string
    {
        return DB::transaction(function () use ($entityType): string {
            $sequence = DB::table('public_id_sequences')
                ->where('entity_type', $entityType)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                throw new InvalidArgumentException("Unknown sequence entity type [{$entityType}].");
            }

            $currentNumber = (int) $sequence->next_number;

            DB::table('public_id_sequences')
                ->where('entity_type', $entityType)
                ->update([
                    'next_number' => $currentNumber + 1,
                    'updated_at' => now(),
                ]);

            return sprintf('%s%06d', $sequence->prefix, $currentNumber);
        });
    }
}
