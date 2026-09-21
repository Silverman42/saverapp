<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Enums\UnlockVerificationMethod;
use App\Enums\UserType;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ManualUnlockRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor || $actor->user_type !== UserType::Admin) {
            return false;
        }

        return app(AuthorizationService::class)->allows($actor, AdminPermission::SecurityOperationsManage);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('reason') && is_string($this->input('reason'))) {
            $this->merge([
                'reason' => trim($this->input('reason')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:password,mfa,recovery_code'],
            'verification_method' => ['required', new Enum(UnlockVerificationMethod::class)],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }
}
