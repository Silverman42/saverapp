<?php

namespace App\Http\Requests;

class CustomerLifecycleRequest extends CustomerStatusTransitionRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['target_status']);

        return [...$rules, 'attempt_reference' => ['required', 'uuid'],
            'assignment_version' => ['present', 'nullable', 'integer', 'min:1']];
    }
}
