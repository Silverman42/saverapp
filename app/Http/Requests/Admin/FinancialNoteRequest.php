<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Enums\FinancialNoteType;
use App\Services\AuthorizationService;
use App\Support\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class FinancialNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(AuthorizationService::class)->allows($this->user()->fresh(), AdminPermission::FinancialNotesManage);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'category' => is_string($this->input('category')) && trim($this->input('category')) !== '' ? trim($this->input('category')) : null,
            'amount_ngn' => is_string($this->input('amount_ngn')) ? str_replace(',', '', trim($this->input('amount_ngn'))) : $this->input('amount_ngn'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(FinancialNoteType::class)],
            'title' => ['required', 'string', 'max:150'],
            'amount_ngn' => ['required', 'string', 'max:20'],
            'note_date' => ['required', 'date_format:Y-m-d'],
            'category' => ['nullable', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:5000'],
            'version' => [$this->isMethod('post') ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('amount_ngn')) {
                return;
            }
            try {
                $amountKobo = MoneyAmount::parseNairaToKobo((string) $this->input('amount_ngn'));
            } catch (InvalidArgumentException) {
                $validator->errors()->add('amount_ngn', 'Enter an amount in naira with no more than two decimal places.');

                return;
            }
            if ($amountKobo < 1) {
                $validator->errors()->add('amount_ngn', 'Enter an amount greater than zero.');
            }
        }];
    }

    /**
     * @return array{type: string, title: string, amount_kobo: int, note_date: string, category: ?string, body: string}
     */
    public function noteValues(): array
    {
        $data = $this->validated();

        return [
            'type' => $data['type'],
            'title' => $data['title'],
            'amount_kobo' => MoneyAmount::parseNairaToKobo($data['amount_ngn']),
            'note_date' => $data['note_date'],
            'category' => $data['category'] ?? null,
            'body' => trim($data['body']),
        ];
    }
}
