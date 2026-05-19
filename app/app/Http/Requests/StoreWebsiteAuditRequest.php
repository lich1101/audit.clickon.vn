<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebsiteAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'websiteId' => ['required', 'string', 'max:128'],
            'auditId' => ['nullable', 'string', 'max:128'],
            'articleUrlsInput' => ['required', 'string', 'max:500000'],
            'categoriesInput' => ['required', 'string', 'max:500000'],
            'checklistText' => ['nullable', 'string', 'max:500000'],
            'aiProvider' => ['nullable', 'string', 'in:openai,gemini,gemini_deep_research'],
            'aiModel' => ['nullable', 'string', 'max:255'],
        ];
    }
}
