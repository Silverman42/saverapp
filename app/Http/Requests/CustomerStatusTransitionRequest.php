<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class CustomerStatusTransitionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null
            && $actor->user_type === UserType::Admin
            && app(AuthorizationService::class)->allows($actor, AdminPermission::CustomersManage);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
            'customer_explanation' => is_string($this->input('customer_explanation'))
                ? trim($this->input('customer_explanation'))
                : $this->input('customer_explanation'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'target_status' => ['required', 'string', 'in:active,inactive,restricted'],
            'version' => ['required', 'integer', 'min:1'],
            'confirmed' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'customer_explanation' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
