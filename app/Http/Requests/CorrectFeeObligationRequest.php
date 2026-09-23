<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CorrectFeeObligationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, AdminPermission::FeesManage);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/'],
            'direction' => ['required', 'string', 'in:reduce,increase'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'customer_description' => ['required', 'string', 'min:1', 'max:500'],
            'attempt_reference' => ['required', 'uuid'],
        ];
    }
}
