<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BusinessSettingsCatalogue
{
    public const VERSION = 1;

    /** @return array<string, array{label: string, group: string, default: mixed, rules: list<string>, editable: bool, help: string}> */
    public function definitions(): array
    {
        $items = [
            'display_name' => ['Display name', 'Business profile', 'SaverApp', ['required', 'string', 'max:150']],
            'legal_name' => ['Legal name', 'Business profile', null, ['nullable', 'string', 'max:200']],
            'support_email' => ['Support email', 'Business profile', null, ['nullable', 'email', 'max:254']],
            'support_phone' => ['Support phone', 'Business profile', null, ['nullable', 'regex:/\A\+[1-9][0-9]{7,14}\z/']],
            'address' => ['Private business address', 'Business profile', null, ['nullable', 'string', 'max:500']],
            'brand_accent' => ['Brand accent', 'Business profile', null, ['nullable', 'regex:/\A#[0-9A-Fa-f]{6}\z/']],
            'brand_foreground' => ['Brand foreground', 'Business profile', null, ['nullable', 'regex:/\A#[0-9A-Fa-f]{6}\z/']],
            'logo_reference' => ['Logo asset', 'Business profile', null, ['nullable', 'string', 'regex:/\A[0-9a-f]{64}\z/']],
            'website' => ['Website', 'Business profile', null, ['nullable', 'url:https', 'max:500']],
            'timezone' => ['Business timezone', 'Locale & calendar', 'Africa/Lagos', ['required', 'timezone']],
            'day_boundary' => ['Operational boundary', 'Locale & calendar', '00:00', ['required', 'in:00:00']],
            'locale' => ['Locale', 'Locale & calendar', 'en-NG', ['required', 'in:en-NG']],
            'week_start' => ['Week starts', 'Locale & calendar', 'Monday', ['required', 'in:Monday,Sunday']],
            'currency' => ['Currency', 'Currency', 'NGN', ['required', 'in:NGN']],
            'minor_digits' => ['Minor digits', 'Currency', 2, ['required', 'integer', 'in:2']],
            'receipt_minimum_kobo' => ['Receipt minimum (kobo)', 'Collection methods & limits', 100, ['required', 'integer', 'min:1', 'max:999999999999']],
            'receipt_maximum_kobo' => ['Receipt maximum (kobo)', 'Collection methods & limits', 999999999999, ['required', 'integer', 'min:1', 'max:999999999999']],
            'late_lookback_days' => ['Late receipt lookback (days)', 'Collection methods & limits', 30, ['required', 'integer', 'min:0', 'max:365']],
            'collection_cash' => ['Agent cash', 'Collection methods & limits', false, ['required', 'boolean']],
            'collection_transfer' => ['Business bank transfer', 'Collection methods & limits', false, ['required', 'boolean']],
            'collection_pos' => ['POS', 'Collection methods & limits', false, ['required', 'boolean']],
            'collection_other' => ['Configured Other methods', 'Collection methods & limits', false, ['required', 'boolean']],
            'withdrawal_cash' => ['Cash payout', 'Withdrawal methods', false, ['required', 'boolean']],
            'withdrawal_transfer' => ['Bank payout', 'Withdrawal methods', false, ['required', 'boolean']],
            'dashboard_activity_range' => ['Collection dashboard range', 'Dashboard & reports', 'today', ['required', 'in:today,week,month']],
            'dashboard_financial_range' => ['Financial dashboard range', 'Dashboard & reports', 'month', ['required', 'in:today,week,month']],
            'report_range' => ['Report activity range', 'Dashboard & reports', 'month', ['required', 'in:today,week,month']],
            'page_size' => ['Default rows', 'Dashboard & reports', 25, ['required', 'integer', 'in:25,50,100']],
            'export_format' => ['Export preference', 'Dashboard & reports', 'csv', ['required', 'in:csv,pdf']],
            'in_app_notifications' => ['In-app lifecycle notices', 'Notifications', true, ['required', 'boolean']],
            'transactional_email' => ['Transactional email', 'Notifications', false, ['required', 'boolean']],
            'customer_registration' => ['Customer registration', 'Features & readiness', false, ['required', 'boolean']],
            'plan_creation' => ['Plan creation', 'Features & readiness', false, ['required', 'boolean']],
            'collections' => ['New collections', 'Features & readiness', false, ['required', 'boolean']],
            'payout_execution' => ['Payout execution', 'Features & readiness', false, ['required', 'boolean']],
            'reversal_posting' => ['Reversal posting', 'Features & readiness', false, ['required', 'boolean']],
            'statement_pdf' => ['Issued statements / PDF', 'Features & readiness', false, ['required', 'boolean']],
            'report_exports' => ['Report file exports', 'Features & readiness', false, ['required', 'boolean']],
            'manual_charges' => ['Controlled manual charges', 'Features & readiness', false, ['required', 'boolean']],
            'fee_refunds' => ['Fee refund entitlements', 'Features & readiness', false, ['required', 'boolean']],
            'cash_disbursements' => ['Cash refunds and earnings draws', 'Features & readiness', false, ['required', 'boolean']],
        ];
        $readOnly = ['dashboard_financial_range', 'brand_accent', 'brand_foreground', 'timezone', 'day_boundary', 'locale', 'currency', 'minor_digits', 'in_app_notifications', 'transactional_email', 'collection_cash', 'collection_transfer', 'collection_pos', 'withdrawal_cash', 'withdrawal_transfer', 'customer_registration', 'plan_creation', 'collections', 'payout_execution', 'reversal_posting', 'statement_pdf', 'report_exports'];
        if (app(BusinessSettingsReadiness::class)->checks()['collections']['state'] === 'Ready to enable') {
            $readOnly = array_values(array_diff($readOnly, ['collection_cash', 'collections']));
        }
        $readOnly = [...$readOnly, 'collection_other', 'manual_charges', 'fee_refunds', 'cash_disbursements'];
        foreach (['plan_creation', 'withdrawal_cash', 'payout_execution', 'reversal_posting', 'statement_pdf', 'report_exports', 'manual_charges', 'fee_refunds', 'cash_disbursements'] as $code) {
            if ((app(BusinessSettingsReadiness::class)->checks()[$code]['state'] ?? '') === 'Ready to enable') {
                $readOnly = array_values(array_diff($readOnly, [$code]));
            }
        }
        $result = [];
        foreach ($items as $code => [$label, $group, $default, $rules]) {
            $result[$code] = ['label' => $label, 'group' => $group, 'default' => $default, 'rules' => $rules,
                'editable' => ! in_array($code, $readOnly, true),
                'help' => in_array($code, $readOnly, true) ? 'Fixed policy or unavailable owner certification. See readiness.' : 'Applies prospectively. Historical records retain their snapshots.'];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function initialValues(BusinessProfile $profile): array
    {
        $values = array_map(fn (array $definition): mixed => $definition['default'], $this->definitions());
        foreach (['display_name', 'legal_name', 'support_email', 'support_phone', 'address', 'timezone'] as $code) {
            $values[$code] = $profile->getAttribute($code);
        }

        return $values;
    }

    /** @param array<string, mixed> $patch
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public function validatePatch(array $patch, array $current, bool $import = false): array
    {
        $definitions = $this->definitions();
        $errors = [];
        foreach ($patch as $code => &$value) {
            $definition = $definitions[$code] ?? null;
            if ($definition === null || (! $import && ! $definition['editable'])) {
                $errors[$code] = 'This setting is unknown, fixed, or awaiting owner certification.';

                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '' && in_array('nullable', $definition['rules'], true)) {
                    $value = null;
                }
            }
            if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{C}<>]/u', $value))) {
                $errors[$code] = 'Use plain text without markup or control characters.';

                continue;
            }
            if (in_array('integer', $definition['rules'], true) && ! is_int($value)) {
                $errors[$code] = 'Enter an exact integer.';

                continue;
            }
            if (in_array('boolean', $definition['rules'], true) && ! is_bool($value)) {
                $errors[$code] = 'Use a boolean value.';

                continue;
            }
            $validator = Validator::make(['value' => $value], ['value' => $definition['rules']]);
            if ($validator->fails()) {
                $errors[$code] = $validator->errors()->first('value');
            }
            if ($code === 'logo_reference' && is_string($value) && ! isset($errors[$code]) && ! app(BusinessLogoService::class)->exists($value)) {
                $errors[$code] = 'Upload the logo again; this asset is unavailable.';
            }
            if ($code === 'website' && $value !== null) {
                $host = parse_url($value, PHP_URL_HOST);
                if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) || ! preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}\z/i', $host)
                    || preg_match('/(?:^|\.)(localhost|local|test|internal)$/i', $host) || parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
                    $errors[$code] = 'Use a public HTTPS website without credentials or local addresses.';
                }
            }
            if ($code === 'support_email' && is_string($value)) {
                $value = mb_strtolower($value);
                if (User::query()->where('user_type', 'admin')->where('email_normalized', $value)->exists()) {
                    $errors[$code] = 'Public support email must be distinct from an Admin login identity.';
                }
            }
        }
        unset($value);
        $merged = array_replace($current, $patch);
        if (($merged['receipt_minimum_kobo'] ?? 0) > ($merged['receipt_maximum_kobo'] ?? 0)) {
            $errors['receipt_minimum_kobo'] = 'The minimum cannot exceed the maximum.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        ksort($patch);

        return $patch;
    }

    /** @param array<string, mixed> $values */
    public function hash(array $values): string
    {
        ksort($values);

        return hash_hmac('sha256', json_encode($values, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
