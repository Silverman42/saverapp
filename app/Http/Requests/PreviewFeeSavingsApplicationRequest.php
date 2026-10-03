<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreviewFeeSavingsApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && app(AuthorizationService::class)->allows($this->user(), AdminPermission::FeesManage);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['plan_id' => ['required', 'string', 'max:100'], 'reason' => ['required', 'string', 'min:1', 'max:500'],
            'customer_description' => ['required', 'string', 'min:1', 'max:500']];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->except('_token')), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'Unexpected fee application instructions.');
            }
        }];
    }
}
