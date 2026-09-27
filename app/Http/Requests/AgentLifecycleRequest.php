<?php

namespace App\Http\Requests;

class AgentLifecycleRequest extends AgentStatusTransitionRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['target_status']);

        return [...$rules, 'attempt_reference' => ['required', 'uuid'],
            'case_id' => ['present', 'nullable', 'integer', 'min:1'],
            'case_version' => ['present', 'nullable', 'integer', 'min:1'],
            'owner_user_id' => ['nullable', 'integer', 'min:1']];
    }
}
