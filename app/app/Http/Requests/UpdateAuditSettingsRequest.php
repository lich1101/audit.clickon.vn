<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAuditSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'aiProvider' => ['required', 'string', 'in:openai,gemini,gemini_deep_research'],
            'aiModel' => ['nullable', 'string', 'max:160'],
            'step2AiProvider' => ['required', 'string', 'in:openai,gemini,gemini_deep_research'],
            'step2AiModel' => ['nullable', 'string', 'max:160'],
            'step3AiProvider' => ['required', 'string', 'in:openai,gemini,gemini_deep_research'],
            'step3AiModel' => ['nullable', 'string', 'max:160'],
            'step2FormatterProvider' => ['required', 'string', 'in:openai,gemini'],
            'step2FormatterModel' => ['nullable', 'string', 'max:160'],
            'step3FormatterProvider' => ['required', 'string', 'in:openai,gemini'],
            'step3FormatterModel' => ['nullable', 'string', 'max:160'],
            'step3FlowMode' => ['required', 'string', 'in:standard,audit_deep_research'],
            'maxParallelItems' => ['required', 'integer', 'min:1', 'max:10'],
            'step2BatchSize' => ['required', 'integer', 'min:1', 'max:300'],
            'step3BatchSize' => ['required', 'integer', 'min:1', 'max:300'],
            'deepResearchBatchSize' => ['required', 'integer', 'min:1', 'max:100'],
            'deepResearchResearchModel' => ['required', 'string', 'max:160'],
            'deepResearchReasoningModel' => ['required', 'string', 'max:160'],
            'deepResearchFormatterProvider' => ['required', 'string', 'in:openai,gemini'],
            'deepResearchFormatterModel' => ['required', 'string', 'max:160'],
        ];
    }
}
