<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PreviewFeeRuleRetirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && app(AuthorizationService::class)->allows($actor, AdminPermission::FeesManage);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:1', 'max:500']];
    }
}
