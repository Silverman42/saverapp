<?php

namespace App\Http\Requests\Admin;

use App\Services\BusinessSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }
        app(BusinessSettings::class)->authorize($this->user(), true);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $action = $this->route()?->getActionMethod();

        return match ($action) {
            'store', 'update' => ['operation_id' => ['required', 'uuid'], 'base_version' => ['required', 'integer', 'min:1'],
                'patch' => ['required', 'array', 'min:1', 'max:40'], 'revision' => [$action === 'update' ? 'required' : 'sometimes', 'integer', 'min:1']],
            'preview' => ['revision' => ['required', 'integer', 'min:1'], 'effective_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP']],
            'publish' => ['operation_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'],
                'preview_reference' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:500'], 'confirmation' => ['required', 'accepted']],
            'cancel' => ['operation_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:500'], 'confirmation' => ['required', 'accepted']],
            'discard' => ['operation_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1']],
            'rollback' => ['operation_id' => ['required', 'uuid']],
            default => [],
        };
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [...array_keys($this->rules()), '_token'];
            foreach (array_keys($this->all()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    $validator->errors()->add($field, 'Unexpected configuration field.');
                }
            }
        }];
    }
}
