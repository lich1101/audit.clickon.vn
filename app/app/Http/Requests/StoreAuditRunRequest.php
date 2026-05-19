<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAuditRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'websiteId' => ['required', 'string'],
            'websiteName' => ['nullable', 'string', 'max:255'],
            'websiteUrl' => ['nullable', 'url', 'max:2048'],
            'targetUrls' => ['required', 'array', 'min:1', 'max:200'],
            'targetUrls.*' => ['required', 'url', 'max:2048'],
            'categories' => ['nullable', 'array', 'max:200'],
            'categories.*.name' => ['required_with:categories', 'string', 'max:255'],
            'categories.*.url' => ['required_with:categories', 'url', 'max:2048'],
            'checklistText' => ['nullable', 'string', 'max:50000'],
        ];
    }
}
