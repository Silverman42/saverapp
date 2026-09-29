<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FinancialPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $changed_by_user_id
 * @property CarbonImmutable $month
 */
#[Fillable(['business_profile_id', 'timezone', 'month', 'status', 'version', 'changed_by_user_id'])]
class FinancialPeriod extends Model
{
    /** @use HasFactory<FinancialPeriodFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['month' => 'immutable_date', 'version' => 'integer'];
    }
}
