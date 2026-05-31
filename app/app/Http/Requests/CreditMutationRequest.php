<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreditMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'userId' => ['required', 'string'],
            'credits' => ['nullable', 'integer', 'min:1'],
            'amountUsd' => ['nullable', 'numeric', 'min:0.000001'],
            'reason' => ['required', 'string', 'min:4'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('credits') && ! $this->filled('amountUsd')) {
                    $validator->errors()->add('credits', 'Cần nhập số credit hoặc số USD.');
                }
            },
        ];
    }
}
