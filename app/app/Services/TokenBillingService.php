<?php

namespace App\Services;

use App\Models\AiModelPricing;
use App\Models\AiUsageEvent;
use App\Models\AuditRunItem;
use Illuminate\Support\Facades\Cache;

class TokenBillingService
{
    private const PRICE_SCALE_PER_MILLION = 1000000;

    private const PRICE_SCALE_PER_THOUSAND = 1000;

    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    /**
     * @param  array{
     *   provider:string,
     *   model:string,
     *   input_tokens:int,
     *   output_tokens:int,
     *   total_tokens:int,
     *   citation_tokens?:int,
     *   reasoning_tokens?:int,
     *   search_queries?:int,
     *   provider_reported_cost_usd?:float|int|string|null
     * }  $usage
     */
    public function chargeForAiCall(
        AuditRunItem $item,
        string $step,
        array $usage,
    ): AiUsageEvent {
        $item->loadMissing('run');
        $usdResult = $this->calculateUsdForUsage($usage);
        $usdCharged = (float) ($usdResult['amount'] ?? 0.0);
        $creditsCharged = $this->calculateCredits(
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
            'citation_tokens' => (int) ($usage['citation_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($usage['reasoning_tokens'] ?? 0),
            'search_queries' => (int) ($usage['search_queries'] ?? 0),
            'provider_reported_cost_usd' => $this->normalizeUsd($usage['provider_reported_cost_usd'] ?? null),
            'credits_charged' => $creditsCharged,
            'usd_charged' => $usdCharged,
        ]);

        if ($usdCharged > 0 && $item->run) {
            $this->creditService->mutateUsd(
                firebaseUid: $item->run->user_uid,
                type: 'subtract',
                amountUsd: $usdCharged,
                reason: sprintf(
                    'Audit AI [%s] %s · %d in / %d out tokens · $%.6f',
                    $step,
                    $usage['model'],
                    (int) $usage['input_tokens'],
                    (int) $usage['output_tokens'],
                    $usdCharged,
                ),
                source: 'audit',
                referenceType: 'audit_run_item',
                referenceId: (string) $item->public_id,
            );
        }

        return $event;
    }

    /**
     * @param  array{
     *   provider:string,
     *   model:string,
     *   input_tokens?:int,
     *   output_tokens?:int,
     *   citation_tokens?:int,
     *   reasoning_tokens?:int,
     *   search_queries?:int,
     *   provider_reported_cost_usd?:float|int|string|null
     * }  $usage
     * @return array{amount:float,isExact:bool,source:string}
     */
    public function calculateUsdForUsage(array $usage): array
    {
        $provider = trim((string) ($usage['provider'] ?? ''));
        $model = trim((string) ($usage['model'] ?? ''));
        $markup = max(0.0, (float) config('services.audit.billing_markup', 1.0));
        $reported = $this->normalizeUsd($usage['provider_reported_cost_usd'] ?? null);

        if ($reported !== null && $reported > 0) {
            $amount = round($reported * $markup, 6);

            return [
                'amount' => max($amount, $this->resolveMinUsdPerCall($provider, $model)),
                'isExact' => true,
                'source' => 'provider_reported',
            ];
        }

        $estimated = $this->estimateUsdForUsage([
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'citation_tokens' => (int) ($usage['citation_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($usage['reasoning_tokens'] ?? 0),
            'search_queries' => (int) ($usage['search_queries'] ?? 0),
        ]);

        if ($estimated['amount'] !== null) {
            $amount = round((float) $estimated['amount'] * $markup, 6);

            return [
                'amount' => max($amount, $this->resolveMinUsdPerCall($provider, $model)),
                'isExact' => (bool) ($estimated['isExact'] ?? false),
                'source' => 'estimated_tokens',
            ];
        }

        $credits = $this->calculateCredits(
            $provider,
            $model,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
        );
        $legacyRate = max(0.000001, (float) config('services.audit.legacy_credit_usd_value', 0.01));
        $amount = round($credits * $legacyRate * $markup, 6);

        return [
            'amount' => max($amount, $this->resolveMinUsdPerCall($provider, $model)),
            'isExact' => false,
            'source' => 'credit_fallback',
        ];
    }

    private function resolveMinUsdPerCall(string $provider, string $model): float
    {
        $pricing = $this->resolvePricing($provider, $model);

        if (is_numeric($pricing['min_usd_per_call'] ?? null)) {
            return round((float) $pricing['min_usd_per_call'], 6);
        }

        $legacyRate = max(0.000001, (float) config('services.audit.legacy_credit_usd_value', 0.01));

        return round(((int) ($pricing['min_credits_per_call'] ?? 0)) * $legacyRate, 6);
    }

    public function calculateCredits(string $provider, string $model, int $inputTokens, int $outputTokens): int
    {
        $pricing = $this->resolvePricing($provider, $model);

        $raw = (($inputTokens / 1000) * (float) $pricing['credits_per_1k_input'])
            + (($outputTokens / 1000) * (float) $pricing['credits_per_1k_output']);

        return max(0, (int) ceil($raw));
    }

    /**
     * @param  array{
     *   provider:string,
     *   model:string,
     *   input_tokens?:int,
     *   output_tokens?:int,
     *   citation_tokens?:int,
     *   reasoning_tokens?:int,
     *   search_queries?:int
     * }  $usage
     * @return array{amount:?float,isExact:bool,missingComponents:string[]}
     */
    public function estimateUsdForUsage(array $usage): array
    {
        $provider = trim((string) ($usage['provider'] ?? ''));
        $model = trim((string) ($usage['model'] ?? ''));

        if ($provider === '' || $model === '') {
            return [
                'amount' => null,
                'isExact' => false,
                'missingComponents' => ['provider_or_model'],
            ];
        }

        $pricing = $this->resolvePricing($provider, $model, allowFallback: false);
        $inputTokens = max(0, (int) ($usage['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($usage['output_tokens'] ?? 0));
        $citationTokens = max(0, (int) ($usage['citation_tokens'] ?? 0));
        $reasoningTokens = max(0, (int) ($usage['reasoning_tokens'] ?? 0));
        $searchQueries = max(0, (int) ($usage['search_queries'] ?? 0));

        $missingComponents = [];
        $amount = 0.0;

        $inputUsd = $this->estimateScaledUsd($inputTokens, $pricing['usd_per_1m_input'] ?? null, self::PRICE_SCALE_PER_MILLION);
        if ($inputUsd === null && $inputTokens > 0) {
            $missingComponents[] = 'input_tokens';
        } else {
            $amount += $inputUsd ?? 0.0;
        }

        $outputUsd = $this->estimateScaledUsd($outputTokens, $pricing['usd_per_1m_output'] ?? null, self::PRICE_SCALE_PER_MILLION);
        if ($outputUsd === null && $outputTokens > 0) {
            $missingComponents[] = 'output_tokens';
        } else {
            $amount += $outputUsd ?? 0.0;
        }

        $citationUsd = $this->estimateScaledUsd($citationTokens, $pricing['usd_per_1m_citation'] ?? null, self::PRICE_SCALE_PER_MILLION);
        if ($citationUsd === null && $citationTokens > 0) {
            $missingComponents[] = 'citation_tokens';
        } else {
            $amount += $citationUsd ?? 0.0;
        }

        $reasoningUsd = $this->estimateScaledUsd($reasoningTokens, $pricing['usd_per_1m_reasoning'] ?? null, self::PRICE_SCALE_PER_MILLION);
        if ($reasoningUsd === null && $reasoningTokens > 0) {
            $missingComponents[] = 'reasoning_tokens';
        } else {
            $amount += $reasoningUsd ?? 0.0;
        }

        $searchUsd = $this->estimateScaledUsd($searchQueries, $pricing['usd_per_1k_search_queries'] ?? null, self::PRICE_SCALE_PER_THOUSAND);
        if ($searchUsd === null && $searchQueries > 0) {
            $missingComponents[] = 'search_queries';
        } else {
            $amount += $searchUsd ?? 0.0;
        }

        if ($missingComponents !== []) {
            return [
                'amount' => null,
                'isExact' => false,
                'missingComponents' => $missingComponents,
            ];
        }

        return [
            'amount' => round($amount, 6),
            'isExact' => (bool) ($pricing['is_exact_match'] ?? false),
            'missingComponents' => [],
        ];
    }

    public function estimateMinimumCreditsForUrl(string $provider, ?string $model): int
    {
        return $this->estimateMinimumCreditsForBatchRun($provider, $model);
    }

    public function estimateMinimumCreditsForBatchRun(string $provider, ?string $model): int
    {
        return 0;
    }

    public function estimateMinimumCreditsForAiCall(string $provider, ?string $model): int
    {
        return 0;
    }

    public function estimateMinimumCreditsForChunkedRun(
        string $provider,
        ?string $model,
        int $totalUrls,
        int $step2BatchSize,
        int $step3BatchSize,
    ): int {
        if ($totalUrls <= 0) {
            return 0;
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePricing(string $provider, string $model, bool $allowFallback = true): array
    {
        $cacheKey = sprintf('ai_model_pricing.%s.%s.%s', $allowFallback ? 'fallback' : 'exact', $provider, $model);

        return Cache::remember($cacheKey, 300, function () use ($provider, $model, $allowFallback): array {
            $record = AiModelPricing::query()
                ->where('provider', $provider)
                ->where('model', $model)
                ->where('is_active', true)
                ->first();

            if ($record) {
                return $this->serializePricingRecord($record, true);
            }

            if ($allowFallback) {
                $fallback = AiModelPricing::query()
                    ->where('provider', $provider)
                    ->where('is_active', true)
                    ->orderByDesc('min_credits_per_call')
                    ->first();

                if ($fallback) {
                    return $this->serializePricingRecord($fallback, false);
                }
            }

            return [
                'provider' => $provider,
                'model' => $model,
                'label' => $model,
                'credits_per_1k_input' => 2.0,
                'credits_per_1k_output' => 8.0,
                'usd_per_1m_input' => null,
                'usd_per_1m_output' => null,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 2,
                'min_usd_per_call' => null,
                'is_exact_match' => false,
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
                'usdPer1MInput' => $this->normalizeNullableFloat($row->usd_per_1m_input),
                'usdPer1MOutput' => $this->normalizeNullableFloat($row->usd_per_1m_output),
                'usdPer1MReasoning' => $this->normalizeNullableFloat($row->usd_per_1m_reasoning),
                'usdPer1MCitation' => $this->normalizeNullableFloat($row->usd_per_1m_citation),
                'usdPer1kSearchQueries' => $this->normalizeNullableFloat($row->usd_per_1k_search_queries),
                'minCreditsPerCall' => (int) $row->min_credits_per_call,
                'minUsdPerCall' => $this->normalizeNullableFloat($row->min_usd_per_call),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{
     *   provider:string,
     *   model:string,
     *   label?:string|null,
     *   creditsPer1kInput?:float|int|string|null,
     *   creditsPer1kOutput?:float|int|string|null,
     *   usdPer1MInput?:float|int|string|null,
     *   usdPer1MOutput?:float|int|string|null,
     *   usdPer1MReasoning?:float|int|string|null,
     *   usdPer1MCitation?:float|int|string|null,
     *   usdPer1kSearchQueries?:float|int|string|null,
     *   minCreditsPerCall?:int|string|null,
     *   minUsdPerCall?:float|int|string|null
     * }>  $rows
     */
    public function syncPricing(array $rows): void
    {
        foreach ($rows as $row) {
            $provider = trim((string) ($row['provider'] ?? ''));
            $model = trim((string) ($row['model'] ?? ''));

            if ($provider === '' || $model === '') {
                continue;
            }

            AiModelPricing::query()->updateOrCreate(
                [
                    'provider' => $provider,
                    'model' => $model,
                ],
                [
                    'label' => $this->normalizeNullableString($row['label'] ?? null) ?? $model,
                    'credits_per_1k_input' => $this->normalizeNullableFloat($row['creditsPer1kInput'] ?? null) ?? 0.0,
                    'credits_per_1k_output' => $this->normalizeNullableFloat($row['creditsPer1kOutput'] ?? null) ?? 0.0,
                    'usd_per_1m_input' => $this->normalizeNullableFloat($row['usdPer1MInput'] ?? null),
                    'usd_per_1m_output' => $this->normalizeNullableFloat($row['usdPer1MOutput'] ?? null),
                    'usd_per_1m_reasoning' => $this->normalizeNullableFloat($row['usdPer1MReasoning'] ?? null),
                    'usd_per_1m_citation' => $this->normalizeNullableFloat($row['usdPer1MCitation'] ?? null),
                    'usd_per_1k_search_queries' => $this->normalizeNullableFloat($row['usdPer1kSearchQueries'] ?? null),
                    'min_credits_per_call' => max(0, (int) ($row['minCreditsPerCall'] ?? 0)),
                    'min_usd_per_call' => $this->normalizeNullableFloat($row['minUsdPerCall'] ?? null),
                    'is_active' => true,
                ],
            );

            Cache::forget("ai_model_pricing.exact.{$provider}.{$model}");
            Cache::forget("ai_model_pricing.fallback.{$provider}.{$model}");
        }
    }

    private function defaultModelForProvider(string $provider): string
    {
        return match ($provider) {
            'gemini' => (string) config('services.gemini.model', 'gemini-2.5-pro'),
            'gemini_deep_research' => (string) config('services.gemini.deep_research_agent', 'deep-research-pro-preview-12-2025'),
            default => (string) config('services.openai.model', 'gpt-5.5'),
        };
    }

    private function normalizeUsd(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = round((float) $value, 6);

        return $normalized >= 0 ? $normalized : null;
    }

    private function serializePricingRecord(AiModelPricing $record, bool $isExactMatch): array
    {
        return [
            'provider' => $record->provider,
            'model' => $record->model,
            'label' => $record->label ?? $record->model,
            'credits_per_1k_input' => (float) $record->credits_per_1k_input,
            'credits_per_1k_output' => (float) $record->credits_per_1k_output,
            'usd_per_1m_input' => $this->normalizeNullableFloat($record->usd_per_1m_input),
            'usd_per_1m_output' => $this->normalizeNullableFloat($record->usd_per_1m_output),
            'usd_per_1m_reasoning' => $this->normalizeNullableFloat($record->usd_per_1m_reasoning),
            'usd_per_1m_citation' => $this->normalizeNullableFloat($record->usd_per_1m_citation),
            'usd_per_1k_search_queries' => $this->normalizeNullableFloat($record->usd_per_1k_search_queries),
            'min_credits_per_call' => (int) $record->min_credits_per_call,
            'min_usd_per_call' => $this->normalizeNullableFloat($record->min_usd_per_call),
            'is_exact_match' => $isExactMatch,
        ];
    }

    private function estimateScaledUsd(int $quantity, mixed $rate, int $scale): ?float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        if (! is_numeric($rate)) {
            return null;
        }

        return ($quantity / $scale) * (float) $rate;
    }

    private function normalizeNullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = round((float) $value, 6);

        return $normalized >= 0 ? $normalized : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
