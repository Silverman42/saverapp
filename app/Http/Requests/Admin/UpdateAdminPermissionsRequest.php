<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminPermissionsRequest extends FormRequest
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

        return app(AuthorizationService::class)->allows($actor, AdminPermission::AdminsManage);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'expected_permission_version' => ['required', 'integer'],
            'confirmed' => ['required', 'accepted'],
        ];
    }
}
