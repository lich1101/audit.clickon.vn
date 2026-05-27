<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiModelCatalogService
{
    /**
     * @return array{provider:string,defaultModel:string,models:array<int,array{id:string,label:string,default?:bool}>,source:string}
     */
    public function listForProvider(string $provider): array
    {
        return Cache::remember("ai_models.{$provider}", now()->addHour(), function () use ($provider): array {
            return match ($provider) {
                'openai' => $this->listOpenAiModels(),
                'gemini' => $this->listGeminiModels(),
                'gemini_deep_research' => $this->listDeepResearchAgents(),
                'perplexity' => $this->listPerplexityModels(),
                default => throw new RuntimeException("Unsupported AI provider [{$provider}]."),
            };
        });
    }

    /**
     * @return array{provider:string,defaultModel:string,models:array<int,array{id:string,label:string,default?:bool}>,source:string}
     */
    private function listOpenAiModels(): array
    {
        $defaultModel = (string) config('services.openai.model', 'gpt-5.5');
        $fallback = $this->uniqueModels([
            $defaultModel,
            'gpt-5.5',
            'gpt-4.1',
            'gpt-4o',
            'gpt-4o-mini',
            'o3',
            'o4-mini',
        ]);

        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            return $this->payload('openai', $defaultModel, $fallback, 'fallback');
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(20)
                ->get('https://api.openai.com/v1/models');

            if (! $response->successful()) {
                return $this->payload('openai', $defaultModel, $fallback, 'fallback');
            }

            $models = collect($response->json('data', []))
                ->pluck('id')
                ->filter(fn (mixed $id): bool => is_string($id) && $this->isUsefulOpenAiModel($id))
                ->values()
                ->all();

            if ($models === []) {
                return $this->payload('openai', $defaultModel, $fallback, 'fallback');
            }

            $models = $this->uniqueModels([$defaultModel, ...$models]);

            return $this->payload('openai', $defaultModel, $models, 'api');
        } catch (\Throwable) {
            return $this->payload('openai', $defaultModel, $fallback, 'fallback');
        }
    }

    /**
     * @return array{provider:string,defaultModel:string,models:array<int,array{id:string,label:string,default?:bool}>,source:string}
     */
    private function listGeminiModels(): array
    {
        $defaultModel = (string) config('services.gemini.model', 'gemini-2.5-pro');
        $fallback = $this->uniqueModels([
            $defaultModel,
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.0-flash',
            'gemini-1.5-pro',
        ]);

        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            return $this->payload('gemini', $defaultModel, $fallback, 'fallback');
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->timeout(20)
                ->get('https://generativelanguage.googleapis.com/v1beta/models', [
                    'pageSize' => 100,
                ]);

            if (! $response->successful()) {
                return $this->payload('gemini', $defaultModel, $fallback, 'fallback');
            }

            $models = collect($response->json('models', []))
                ->filter(function (mixed $model): bool {
                    if (! is_array($model)) {
                        return false;
                    }

                    $methods = $model['supportedGenerationMethods'] ?? [];

                    return is_array($methods) && in_array('generateContent', $methods, true);
                })
                ->map(function (array $model): ?string {
                    $name = $model['name'] ?? null;

                    if (! is_string($name)) {
                        return null;
                    }

                    return str_starts_with($name, 'models/') ? substr($name, 7) : $name;
                })
                ->filter(fn (?string $id): bool => is_string($id) && $id !== '' && str_contains($id, 'gemini'))
                ->values()
                ->all();

            if ($models === []) {
                return $this->payload('gemini', $defaultModel, $fallback, 'fallback');
            }

            $models = $this->uniqueModels([$defaultModel, ...$models]);

            return $this->payload('gemini', $defaultModel, $models, 'api');
        } catch (\Throwable) {
            return $this->payload('gemini', $defaultModel, $fallback, 'fallback');
        }
    }

    /**
     * @return array{provider:string,defaultModel:string,models:array<int,array{id:string,label:string,default?:bool}>,source:string}
     */
    private function listDeepResearchAgents(): array
    {
        $defaultModel = (string) config('services.gemini.deep_research_agent', 'deep-research-pro-preview-12-2025');
        $configuredAgents = $this->csvModels((string) config('services.gemini.deep_research_agents', ''));
        $models = $this->uniqueModels([
            $defaultModel,
            ...$configuredAgents,
            'deep-research-pro-preview-12-2025',
            'deep-research-preview-04-2026',
        ]);

        return $this->payload('gemini_deep_research', $defaultModel, $models, 'config');
    }

    /**
     * @return array{provider:string,defaultModel:string,models:array<int,array{id:string,label:string,default?:bool}>,source:string}
     */
    private function listPerplexityModels(): array
    {
        $defaultModel = (string) config('services.perplexity.model', 'sonar-deep-research');
        $configuredModels = $this->csvModels((string) config('services.perplexity.models', ''));
        $models = $this->uniqueModels([
            $defaultModel,
            ...$configuredModels,
            'sonar-deep-research',
            'sonar-reasoning-pro',
            'sonar-pro',
            'sonar',
        ]);

        return $this->payload('perplexity', $defaultModel, $models, 'config');
    }

    private function isUsefulOpenAiModel(string $id): bool
    {
        return preg_match('/^(gpt-|o[0-9]|chatgpt)/i', $id) === 1
            && ! str_contains($id, 'realtime')
            && ! str_contains($id, 'audio')
            && ! str_contains($id, 'transcribe')
            && ! str_contains($id, 'tts')
            && ! str_contains($id, 'search')
            && ! str_contains($id, 'instruct');
    }

    /**
     * @param  array<int, string>  $models
     * @return array{provider:string,defaultModel:string,models:array<int,array{id:string,label:string,default?:bool}>,source:string}
     */
    private function payload(string $provider, string $defaultModel, array $models, string $source): array
    {
        return [
            'provider' => $provider,
            'defaultModel' => $defaultModel,
            'models' => array_map(
                fn (string $id): array => [
                    'id' => $id,
                    'label' => $id,
                    'default' => $id === $defaultModel,
                ],
                $models
            ),
            'source' => $source,
        ];
    }

    /**
     * @param  array<int, string>  $models
     * @return array<int, string>
     */
    private function uniqueModels(array $models): array
    {
        $seen = [];

        return array_values(array_filter($models, function (string $model) use (&$seen): bool {
            $model = trim($model);

            if ($model === '' || isset($seen[$model])) {
                return false;
            }

            $seen[$model] = true;

            return true;
        }));
    }

    /**
     * @return array<int, string>
     */
    private function csvModels(string $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $model): string => trim($model),
            explode(',', $value)
        )));
    }
}
