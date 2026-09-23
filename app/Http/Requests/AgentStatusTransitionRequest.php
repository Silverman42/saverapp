<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class AgentStatusTransitionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null
            && $actor->user_type === UserType::Admin
            && app(AuthorizationService::class)->allows($actor, AdminPermission::AgentsManage);
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'agent_explanation'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'target_status' => ['required', 'string', 'in:active,inactive'],
            'version' => ['required', 'integer', 'min:1'],
            'confirmed' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'agent_explanation' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
