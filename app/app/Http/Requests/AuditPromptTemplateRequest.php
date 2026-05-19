<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuditPromptTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'systemPrompt' => ['nullable', 'string', 'min:20', 'max:50000'],
            'developerPrompt' => ['required_without:systemPrompt', 'string', 'min:20', 'max:50000'],
            'userPrompt' => ['required', 'string', 'min:20', 'max:50000'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
