<?php

namespace App\Services;

use App\Models\AuditPromptTemplate;
use Illuminate\Support\Facades\Schema;

class AuditPromptTemplateService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $templates = $this->configuredTemplatesByStep();

        return array_map(
            fn (string $step): array => $this->serialize($step, $templates[$step] ?? null),
            AuditPromptTemplate::STEPS
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{system:string,developer:string,user:string}
     */
    public function render(string $step, array $variables): array
    {
        $template = $this->templateForStep($step);
        $systemPrompt = $this->replaceVariables((string) $template['developer_prompt'], $variables);

        return [
            'system' => $systemPrompt,
            'developer' => $systemPrompt,
            'user' => $this->replaceVariables((string) $template['user_prompt'], $variables),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function upsert(string $step, array $payload): array
    {
        $defaults = AuditPromptTemplate::defaults();

        if (! in_array($step, AuditPromptTemplate::STEPS, true)) {
            abort(404, 'Audit prompt step does not exist.');
        }

        $template = AuditPromptTemplate::query()->updateOrCreate(
            ['step' => $step],
            [
                'title' => $payload['title'] ?? $defaults[$step]['title'],
                'developer_prompt' => $payload['systemPrompt'] ?? $payload['developerPrompt'] ?? $defaults[$step]['developer_prompt'],
                'user_prompt' => $payload['userPrompt'],
                'is_active' => (bool) ($payload['isActive'] ?? true),
            ]
        );

        return $this->serialize($step, $template);
    }

    /**
     * @return array<string, mixed>
     */
    public function reset(string $step): array
    {
        if (! in_array($step, AuditPromptTemplate::STEPS, true)) {
            abort(404, 'Audit prompt step does not exist.');
        }

        AuditPromptTemplate::query()->where('step', $step)->delete();

        return $this->serialize($step, null);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configuredTemplatesByStep(): array
    {
        if (! Schema::hasTable('audit_prompt_templates')) {
            return [];
        }

        return AuditPromptTemplate::query()
            ->get()
            ->keyBy('step')
            ->map(fn (AuditPromptTemplate $template): array => $template->toArray())
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function templateForStep(string $step): array
    {
        $defaults = AuditPromptTemplate::defaults();

        if (! array_key_exists($step, $defaults)) {
            throw new \InvalidArgumentException("Unsupported audit prompt step [{$step}].");
        }

        if (! Schema::hasTable('audit_prompt_templates')) {
            return $defaults[$step];
        }

        $template = AuditPromptTemplate::query()
            ->where('step', $step)
            ->where('is_active', true)
            ->first();

        return $template ? $template->toArray() : $defaults[$step];
    }

    /**
     * @param  AuditPromptTemplate|array<string, mixed>|null  $template
     * @return array<string, mixed>
     */
    private function serialize(string $step, AuditPromptTemplate|array|null $template): array
    {
        $defaults = AuditPromptTemplate::defaults();
        $data = $template instanceof AuditPromptTemplate ? $template->toArray() : ($template ?? $defaults[$step]);

        return [
            'step' => $step,
            'title' => (string) ($data['title'] ?? $defaults[$step]['title']),
            'systemPrompt' => (string) ($data['developer_prompt'] ?? $defaults[$step]['developer_prompt']),
            'developerPrompt' => (string) ($data['developer_prompt'] ?? $defaults[$step]['developer_prompt']),
            'userPrompt' => (string) ($data['user_prompt'] ?? $defaults[$step]['user_prompt']),
            'isActive' => (bool) ($data['is_active'] ?? $defaults[$step]['is_active']),
            'isDefault' => $template === null,
            'updatedAt' => isset($data['updated_at']) ? optional($data['updated_at'])->toIso8601String() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function replaceVariables(string $prompt, array $variables): string
    {
        $replacements = [];

        foreach ($variables as $key => $value) {
            $replacements["{{{$key}}}"] = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        return strtr($prompt, $replacements);
    }
}
