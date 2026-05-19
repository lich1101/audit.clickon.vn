<?php

namespace App\Services;

use App\Models\AiModelPricing;
use App\Models\AiUsageEvent;
use App\Models\AuditRunItem;
use Illuminate\Support\Facades\Cache;

class TokenBillingService
{
    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    /**
     * @param  array{provider:string,model:string,input_tokens:int,output_tokens:int,total_tokens:int}  $usage
     */
    public function chargeForAiCall(
        AuditRunItem $item,
        string $step,
        array $usage,
    ): AiUsageEvent {
        $item->loadMissing('run');
        $credits = $this->calculateCredits(
            $usage['provider'],
            $usage['model'],
            (int) $usage['input_tokens'],
            (int) $usage['output_tokens'],
        );

        $event = AiUsageEvent::query()->create([
            'audit_run_item_id' => $item->id,
            'step' => $step,
            'provider' => $usage['provider'],
            'model' => $usage['model'],
            'input_tokens' => (int) $usage['input_tokens'],
            'output_tokens' => (int) $usage['output_tokens'],
            'total_tokens' => (int) $usage['total_tokens'],
            'credits_charged' => $credits,
        ]);

        if ($credits > 0 && $item->run) {
            $this->creditService->mutate(
                firebaseUid: $item->run->user_uid,
                type: 'subtract',
                amount: $credits,
                reason: "Audit AI [{$step}] {$usage['model']} · {$usage['input_tokens']} in / {$usage['output_tokens']} out tokens",
                source: 'audit',
                referenceType: 'audit_run_item',
                referenceId: (string) $item->public_id,
            );
        }

        return $event;
    }

    public function calculateCredits(string $provider, string $model, int $inputTokens, int $outputTokens): int
    {
        $pricing = $this->resolvePricing($provider, $model);

        if (($pricing['credits_per_1k_input'] ?? 0) == 0 && ($pricing['credits_per_1k_output'] ?? 0) == 0) {
            return (int) $pricing['min_credits_per_call'];
        }

        $raw = (($inputTokens / 1000) * (float) $pricing['credits_per_1k_input'])
            + (($outputTokens / 1000) * (float) $pricing['credits_per_1k_output']);

        return max((int) $pricing['min_credits_per_call'], (int) ceil($raw));
    }

    public function estimateMinimumCreditsForUrl(string $provider, ?string $model): int
    {
        $modelName = $model ?: $this->defaultModelForProvider($provider);
        $pricing = $this->resolvePricing($provider, $modelName);

        return ((int) $pricing['min_credits_per_call']) * 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePricing(string $provider, string $model): array
    {
        $cacheKey = "ai_model_pricing.{$provider}.{$model}";

        return Cache::remember($cacheKey, 300, function () use ($provider, $model): array {
            $record = AiModelPricing::query()
                ->where('provider', $provider)
                ->where('model', $model)
                ->where('is_active', true)
                ->first();

            if ($record) {
                return $record->only([
                    'provider',
                    'model',
                    'label',
                    'credits_per_1k_input',
                    'credits_per_1k_output',
                    'min_credits_per_call',
                ]);
            }

            $fallback = AiModelPricing::query()
                ->where('provider', $provider)
                ->where('is_active', true)
                ->orderByDesc('min_credits_per_call')
                ->first();

            if ($fallback) {
                return $fallback->only([
                    'provider',
                    'model',
                    'label',
                    'credits_per_1k_input',
                    'credits_per_1k_output',
                    'min_credits_per_call',
                ]);
            }

            return [
                'provider' => $provider,
                'model' => $model,
                'label' => $model,
                'credits_per_1k_input' => 2.0,
                'credits_per_1k_output' => 8.0,
                'min_credits_per_call' => 2,
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPricing(): array
    {
        return AiModelPricing::query()
            ->where('is_active', true)
            ->orderBy('provider')
            ->orderBy('model')
            ->get()
            ->map(fn (AiModelPricing $row): array => [
                'provider' => $row->provider,
                'model' => $row->model,
                'label' => $row->label ?? $row->model,
                'creditsPer1kInput' => (float) $row->credits_per_1k_input,
                'creditsPer1kOutput' => (float) $row->credits_per_1k_output,
                'minCreditsPerCall' => (int) $row->min_credits_per_call,
            ])
            ->values()
            ->all();
    }

    private function defaultModelForProvider(string $provider): string
    {
        return match ($provider) {
            'gemini' => (string) config('services.gemini.model', 'gemini-2.5-pro'),
            'gemini_deep_research' => (string) config('services.gemini.deep_research_agent', 'deep-research-preview-04-2026'),
            default => (string) config('services.openai.model', 'gpt-5.5'),
        };
    }
}
