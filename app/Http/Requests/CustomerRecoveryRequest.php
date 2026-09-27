<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['notes', 'procedure_reference', 'reason', 'email'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $action = $this->route('action') ?? 'request';
        $rules = ['attempt_reference' => ['required', 'uuid'], 'confirmed' => ['accepted']];
        if ($action === 'request' || $action === 'verify') {
            $rules += ['version' => ['required', 'integer', 'min:1'], 'assignment_version' => ['required', 'integer', 'min:1'],
                'in_person' => ['accepted'], 'record_compared' => ['accepted'],
                'verified_at' => ['required', 'date'],
                'procedure_reference' => ['required', 'string', 'max:150'], 'notes' => ['required', 'string', 'max:2000']];
        }
        if ($action === 'request') {
            $rules['email'] = ['required', 'email:rfc', 'max:255'];
        } else {
            $rules += ['recovery_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']];
        }

        return $rules;
    }
}
