<?php

declare(strict_types=1);

/**
 * Đo token input trùng lặp mỗi batch qua Gemini countTokens.
 *
 * Payload mẫu (như curl user): generateContentConfig + contents[].parts[text,fileData].
 * REST generativelanguage.googleapis.com không nhận field generateContentConfig →
 * map sang generateContentRequest (systemInstruction + contents giữ nguyên).
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$runId = (int) ($argv[1] ?? 0);
if ($runId <= 0) {
    fwrite(STDERR, "Usage: php scripts/measure-batch-token-overlap.php <audit_run_id>\n");
    exit(1);
}

/** @var \App\Models\AuditRun|null $run */
$run = \App\Models\AuditRun::query()->with('items')->find($runId);
if (! $run) {
    fwrite(STDERR, "Run {$runId} not found\n");
    exit(1);
}

$auditRunService = app(\App\Services\AuditRunService::class);
$pdfService = app(\App\Services\AuditGeminiPdfAttachmentService::class);
$seoAiInstance = app(\App\Services\SeoAiAuditService::class);

$reflection = new ReflectionClass($auditRunService);
$payloadMethod = $reflection->getMethod('step2BatchPagePayload');
$payloadMethod->setAccessible(true);
$buildBundle = (new ReflectionClass(\App\Services\SeoAiAuditService::class))->getMethod('buildFastAuditPromptBundle');
$buildBundle->setAccessible(true);

$model = $run->step2_ai_model ?: config('services.gemini.model', 'gemini-2.5-pro');
$apiKey = config('services.gemini.api_key');

if (! is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "GEMINI_API_KEY missing\n");
    exit(1);
}

/**
 * @param  array<int, string>  $urls
 * @param  array<int, array<string, mixed>>  $batchPages
 */
$countTokens = static function (
    array $urls,
    array $batchPages,
    string $persistStep,
    bool $includePdf,
) use (
    $buildBundle,
    $seoAiInstance,
    $pdfService,
    $run,
    $model,
    $apiKey
): array {
    $bundle = $buildBundle->invoke(
        $seoAiInstance,
        $urls,
        $run->categories ?? [],
        $run->checklist_text,
        $batchPages,
    );
    $prompts = $bundle['prompts'];

    $pdfAttachment = null;
    if ($includePdf) {
        $slot = $pdfService->resolveSlotForPersistStep($persistStep);
        $pdfAttachment = $slot ? $pdfService->getAttachment($slot) : null;
    }

    $userParts = $pdfService->buildGeminiCountTokensParts($prompts['user'], $pdfAttachment);

    // Body đúng như curl user (generateContentConfig + contents không role).
    $userCurlBody = [
        'generateContentConfig' => [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $prompts['system']],
                ],
            ],
        ],
        'contents' => [
            [
                'parts' => $userParts,
            ],
        ],
    ];

    $userCurlResponse = Illuminate\Support\Facades\Http::withHeaders([
        'Content-Type' => 'application/json',
    ])
        ->timeout(180)
        ->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:countTokens?key={$apiKey}",
            $userCurlBody,
        );

    // REST chỉ chấp nhận generateContentRequest; giữ nguyên system + contents + fileData.
    $apiBody = [
        'generateContentRequest' => [
            'model' => 'models/'.$model,
            'systemInstruction' => $userCurlBody['generateContentConfig']['systemInstruction'],
            'contents' => $userCurlBody['contents'],
        ],
    ];

    $apiResponse = Illuminate\Support\Facades\Http::withHeaders([
        'Content-Type' => 'application/json',
    ])
        ->timeout(180)
        ->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:countTokens?key={$apiKey}",
            $apiBody,
        );

    if (! $apiResponse->successful()) {
        throw new RuntimeException('countTokens failed: '.$apiResponse->body());
    }

    $details = $apiResponse->json('promptTokensDetails') ?? [];

    return [
        'total_tokens' => max(0, (int) ($apiResponse->json('totalTokens') ?? 0)),
        'prompt_tokens_details' => is_array($details) ? $details : [],
        'user_curl_body_accepted' => $userCurlResponse->successful(),
        'user_curl_error' => $userCurlResponse->successful()
            ? null
            : ($userCurlResponse->json('error.message') ?? $userCurlResponse->body()),
    ];
};

$fixedResult = $countTokens([], [], 'batch_fast_audit_000_000', true);
$fixed = $fixedResult['total_tokens'];
$fixedNoPdfResult = $countTokens([], [], 'batch_fast_audit_no_pdf_probe', false);
$fixedNoPdf = $fixedNoPdfResult['total_tokens'];

$items = $run->items->sortBy('position')->values();
$pages = [];
foreach ($items as $item) {
    $pages[] = $payloadMethod->invoke($auditRunService, $item);
}

$singleUrlSamples = [];
foreach ($items->take(5) as $index => $item) {
    $page = $pages[$index];
    $step = sprintf('batch_fast_audit_%03d_%03d', $item->position, $item->position);
    $result = $countTokens([$item->target_url], [$page], $step, true);
    $singleUrlSamples[] = [
        'position' => $item->position,
        'url' => $item->target_url,
        'tokens' => $result['total_tokens'],
        'increment_vs_fixed' => $result['total_tokens'] - $fixed,
        'details' => $result['prompt_tokens_details'],
    ];
}

$batchChunks = [];
$stepKeys = array_keys((array) ($run->ai_step_responses ?? []));
foreach ($stepKeys as $stepKey) {
    if (preg_match('/^batch_fast_audit_(\d+)_(\d+)$/', $stepKey, $m)) {
        $batchChunks[] = [
            'step' => $stepKey,
            'min_pos' => (int) $m[1],
            'max_pos' => (int) $m[2],
        ];
    }
}

if ($batchChunks === []) {
    $batchSize = max(1, (int) config('services.audit.batch_size', 5));
    $positions = $items->pluck('position')->map(fn ($p) => (int) $p)->all();
    for ($i = 0; $i < count($positions); $i += $batchSize) {
        $slice = array_slice($positions, $i, $batchSize);
        $batchChunks[] = [
            'step' => sprintf('batch_fast_audit_%03d_%03d', min($slice), max($slice)),
            'min_pos' => min($slice),
            'max_pos' => max($slice),
        ];
    }
}

$reportedByStep = [];
$reported = DB::table('ai_usage_events')
    ->join('audit_run_items', 'audit_run_items.id', '=', 'ai_usage_events.audit_run_item_id')
    ->where('audit_run_items.audit_run_id', $runId)
    ->where('ai_usage_events.step', 'like', 'batch_fast_audit%')
    ->orderBy('ai_usage_events.id')
    ->get([
        'ai_usage_events.step',
        'ai_usage_events.input_tokens',
        'audit_run_items.position',
    ]);

foreach ($reported as $row) {
    $reportedByStep[$row->step] = (int) $row->input_tokens;
}

$batchMeasurements = [];
foreach ($batchChunks as $chunk) {
    $chunkItems = $items
        ->filter(fn ($item) => $item->position >= $chunk['min_pos'] && $item->position <= $chunk['max_pos'])
        ->values();
    $urls = $chunkItems->pluck('target_url')->all();
    $chunkPages = [];
    foreach ($chunkItems as $item) {
        $chunkPages[] = $payloadMethod->invoke($auditRunService, $item);
    }

    $result = $countTokens($urls, $chunkPages, $chunk['step'], true);
    $measured = $result['total_tokens'];
    $urlCount = count($urls);
    $variable = max(0, $measured - $fixed);

    $batchMeasurements[] = [
        'step' => $chunk['step'],
        'url_count' => $urlCount,
        'positions' => [$chunk['min_pos'], $chunk['max_pos']],
        'count_tokens_measured' => $measured,
        'prompt_tokens_details' => $result['prompt_tokens_details'],
        'reported_input_tokens' => $reportedByStep['batch_fast_audit'] ?? $reportedByStep[$chunk['step']] ?? null,
        'fixed_overlap_tokens' => $fixed,
        'variable_tokens' => $variable,
        'overlap_percent' => $measured > 0 ? round(100 * $fixed / $measured, 2) : 0,
        'avg_variable_per_url' => $urlCount > 0 ? (int) round($variable / $urlCount) : 0,
    ];
}

$pdfUri = $pdfService->getAttachment('fast_audit')['geminiFileUri'] ?? null;

echo json_encode([
    'api' => [
        'endpoint' => "https://generativelanguage.googleapis.com/v1beta/models/{$model}:countTokens?key=***",
        'user_curl_body_shape' => 'generateContentConfig.systemInstruction + contents[].parts[text,fileData]',
        'user_curl_body_works' => false,
        'user_curl_error_sample' => $fixedResult['user_curl_error'],
        'rest_accepted_wrapper' => 'generateContentRequest (cùng systemInstruction + contents)',
    ],
    'run' => [
        'id' => $run->id,
        'status' => $run->status,
        'pipeline_mode' => $run->pipeline_mode,
        'item_count' => $items->count(),
        'model' => $model,
        'pdf_file_uri' => $pdfUri,
    ],
    'count_tokens_api' => [
        'fixed_shell_with_pdf' => $fixed,
        'fixed_details' => $fixedResult['prompt_tokens_details'],
        'fixed_shell_text_only' => $fixedNoPdf,
        'pdf_token_estimate' => max(0, $fixed - $fixedNoPdf),
    ],
    'duplicate_per_batch_iteration' => $fixed,
    'waste_across_batches' => count($batchMeasurements) > 1
        ? $fixed * (count($batchMeasurements) - 1)
        : 0,
    'single_url_samples' => $singleUrlSamples,
    'batches' => $batchMeasurements,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
