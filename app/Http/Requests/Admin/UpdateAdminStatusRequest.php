<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AdminStatusService;
use App\Services\AuthorizationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminStatusRequest extends FormRequest
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
     * Record the denied Admin-management attempt before refusing it.
     */
    protected function failedAuthorization(): void
    {
        $target = $this->route('admin');
        if ($this->user() !== null && $target instanceof User) {
            AuditEvent::record('admin.status_denied', User::class, $target->id, null,
                ['changed_fields' => [is_string($this->input('action')) && preg_match('/\\A[a-z]{1,20}\\z/', $this->input('action')) === 1 ? $this->input('action') : 'unknown'], 'denial_code' => 'missing_authority'], $this->user(),
                ['required_permission' => AdminPermission::AdminsManage->value, 'executor' => self::class, 'outcome' => 'Denied']);
        }

        parent::failedAuthorization();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(array_keys(AdminStatusService::SOURCE_STATES))],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'expected_version' => ['required', 'integer'],
            'confirmed' => ['required', 'accepted'],
        ];
    }
}
