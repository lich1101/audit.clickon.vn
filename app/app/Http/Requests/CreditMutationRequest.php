<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'amountUsd' => ['required', 'numeric', 'min:0.000001'],
            'reason' => ['required', 'string', 'min:4'],
        ];
    }
}
