<?php

namespace App\Services;

use App\Models\ThriftPlan;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanFundingReadService
{
    /**
     * @return array{summary: array<string, ?string>, slots: ?list<array{ordinal: int, due_date: string,
     *     formatted_expected_amount: string, formatted_funded_amount: string, formatted_remaining_amount: string,
     *     collection_status: string, advance: bool}>}
     */
    public function readDetail(User $viewer, ThriftPlan $plan): array
    {
        $unavailable = ['summary' => $this->unavailable(), 'slots' => null];
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            return DB::transaction(function () use ($viewer, $plan, $unavailable): array {
                $current = $plan->fresh();
                if ($current === null || $current->version !== $plan->version
                    || $current->current_terms_revision !== $plan->current_terms_revision) {
                    return $unavailable;
                }
                $summary = $this->readMany($viewer, [$plan->plan_id])[$plan->plan_id];
                if ($summary['funded_principal'] === null) {
                    return ['summary' => $summary, 'slots' => null];
                }
                $card = app(CollectionReadService::class)->fundingCard($current);
                $partialCount = count(array_filter($card['slots'], fn (array $slot): bool => $slot['funded_kobo'] > 0 && $slot['funded_kobo'] < $slot['target_kobo']));
                if ($summary['funded_principal'] !== MoneyFormatter::formatNaira($card['funded_kobo'])
                    || $summary['fully_funded_slots'] !== (string) $card['paid_slots']
                    || $summary['required_slots'] !== (string) $card['slot_count']
                    || $summary['partially_funded_slots'] !== (string) $partialCount
                    || $summary['remaining_scheduled_target'] !== MoneyFormatter::formatNaira($card['target_kobo'] - $card['funded_kobo'])) {
                    throw new RuntimeException('Plan funding and its dated allocation owner disagree.');
                }
                $slots = [];
                foreach ($card['slots'] as $slot) {
                    $slots[] = ['ordinal' => $slot['ordinal'], 'due_date' => $slot['due_date'],
                        'formatted_expected_amount' => MoneyFormatter::formatNaira($slot['target_kobo']),
                        'formatted_funded_amount' => MoneyFormatter::formatNaira($slot['funded_kobo']),
                        'formatted_remaining_amount' => MoneyFormatter::formatNaira($slot['remaining_kobo']),
                        'collection_status' => $slot['status'], 'advance' => $slot['advance']];
                }

                return ['summary' => $summary, 'slots' => $slots];
            });
        } catch (RuntimeException|QueryException) {
            return $unavailable;
        }
    }

    /**
     * @param  list<string>  $planIds
     * @return array<string, array{status: string, message: string, as_of: ?string, source_version: ?string,
     *     funded_principal: ?string, remaining_scheduled_target: ?string, required_slots: ?string,
     *     fully_funded_slots: ?string, partially_funded_slots: ?string}>
     */
    public function readMany(User $viewer, array $planIds): array
    {
        $planIds = array_values(array_unique($planIds));
        if ($planIds === []) {
            return [];
        }
        if (count($planIds) > 100) {
            throw new RuntimeException('Plan funding exceeds the bounded directory page.');
        }
        $result = array_fill_keys($planIds, $this->unavailable());
        try {
            $report = app(ReportReadService::class)->read($viewer, 'plans', [
                '_funding_plan_ids' => $planIds, 'page_size' => 100, 'group' => '',
            ]);
        } catch (HttpException $exception) {
            if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                throw $exception;
            }

            return $result;
        } catch (RuntimeException|QueryException) {
            return $result;
        }
        $section = $report['sections']['funding_progress'];
        if (! in_array($section['status'], ['Current', 'Partial'], true)) {
            return $result;
        }
        foreach ($section['rows'] as $row) {
            if (! array_key_exists($row['reference'], $result)) {
                continue;
            }
            $result[$row['reference']] = [
                'status' => $section['status'], 'message' => 'Current contributions allocated to this plan.',
                'as_of' => $report['manifest']['cutoff'], 'source_version' => $section['source_version'],
                'funded_principal' => $row['funded_principal'], 'remaining_scheduled_target' => $row['remaining_scheduled_target'],
                'required_slots' => (string) $row['required_slots'], 'fully_funded_slots' => (string) $row['fully_funded_slots'],
                'partially_funded_slots' => (string) $row['partially_funded_slots'],
            ];
        }

        return $result;
    }

    /** @return array{status: string, message: string, as_of: ?string, source_version: ?string,
     *     funded_principal: ?string, remaining_scheduled_target: ?string, required_slots: ?string,
     *     fully_funded_slots: ?string, partially_funded_slots: ?string}
     */
    public function unavailable(): array
    {
        return ['status' => 'Unavailable', 'message' => 'Collection funding cannot be verified. Reload to try again.',
            'as_of' => null, 'source_version' => null, 'funded_principal' => null, 'remaining_scheduled_target' => null,
            'required_slots' => null, 'fully_funded_slots' => null, 'partially_funded_slots' => null];
    }
}
