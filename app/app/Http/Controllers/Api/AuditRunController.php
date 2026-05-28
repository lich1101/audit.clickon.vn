<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAuditRunRequest;
use App\Models\AuditRun;
use App\Models\WebsiteAuditUrlResult;
use App\Services\AuditRunService;
use App\Services\AuditSettingsService;
use App\Services\WebsiteAuditUrlResultService;
use App\Services\WebsiteDataService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AuditRunController extends Controller
{
    public function __construct(
        private readonly AuditRunService $auditRunService,
        private readonly WebsiteDataService $websiteDataService,
        private readonly WebsiteAuditUrlResultService $urlResultService,
        private readonly AuditSettingsService $auditSettingsService,
    ) {
    }

    public function indexByWebsite(Request $request, string $websiteId)
    {
        $website = $this->websiteDataService->getWebsite($websiteId);

        if (! $website) {
            throw new NotFoundHttpException('Website not found.');
        }

        $this->authorizeWebsiteAccess($request, $website);

        $runs = AuditRun::query()
            ->where('website_id', $websiteId)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn (AuditRun $run): array => $this->auditRunService->serializeRunSummary($run))
            ->values()
            ->all();

        return response()->json([
            'data' => $runs,
        ]);
    }

    public function board(Request $request, string $websiteId)
    {
        $website = $this->websiteDataService->getWebsite($websiteId);

        if (! $website) {
            throw new NotFoundHttpException('Website not found.');
        }

        $this->authorizeWebsiteAccess($request, $website);

        $audit = $this->websiteDataService->getAuditByWebsiteId($websiteId);

        /** @var AuditRun|null $latestRun */
        $activeRun = AuditRun::query()
            ->where('website_id', $websiteId)
            ->whereIn('status', ['queued', 'processing'])
            ->orderByDesc('created_at')
            ->with(['items' => fn ($query) => $query->orderBy('position')])
            ->first();

        $latestRun = $activeRun ?? AuditRun::query()
            ->where('website_id', $websiteId)
            ->orderByDesc('created_at')
            ->with(['items' => fn ($query) => $query->orderBy('position')])
            ->first();

        $runPayload = null;

        if ($latestRun) {
            $runPayload = [
                ...$this->auditRunService->serializeRunSummary($latestRun),
                'items' => $latestRun->items
                    ->map(fn ($item): array => $this->auditRunService->serializeItemSummary($item, $latestRun))
                    ->values()
                    ->all(),
            ];
        }

        $urlResults = WebsiteAuditUrlResult::query()
            ->where('website_id', $websiteId)
            ->orderBy('target_url')
            ->get()
            ->map(fn (WebsiteAuditUrlResult $result): array => $this->urlResultService->serialize($result))
            ->values()
            ->all();

        $systemSettings = $this->auditSettingsService->getAuditSettings();
        $auditUrlCount = is_array($audit['articleUrls'] ?? null) ? count($audit['articleUrls']) : 0;
        $minimumCreditsPerRun = $this->auditRunService->minimumCreditsPerRun(
            $systemSettings['aiProvider'],
            $systemSettings['aiModel'],
            $auditUrlCount,
            $systemSettings,
        );

        return response()->json([
            'data' => [
                'website' => [
                    'id' => $websiteId,
                    'name' => (string) ($website['name'] ?? ''),
                    'url' => (string) ($website['url'] ?? ''),
                ],
                'audit' => $audit ? $this->serializeAuditDocument($audit) : null,
                'run' => $runPayload,
                'urlResults' => $urlResults,
                'systemAi' => [
                    'aiProvider' => $systemSettings['aiProvider'],
                    'aiModel' => $systemSettings['aiModel'],
                    'step2AiProvider' => $systemSettings['step2AiProvider'],
                    'step2AiModel' => $systemSettings['step2AiModel'],
                    'step3AiProvider' => $systemSettings['step3AiProvider'],
                    'step3AiModel' => $systemSettings['step3AiModel'],
                    'step2FormatterProvider' => $systemSettings['step2FormatterProvider'],
                    'step2FormatterModel' => $systemSettings['step2FormatterModel'],
                    'step3FormatterProvider' => $systemSettings['step3FormatterProvider'],
                    'step3FormatterModel' => $systemSettings['step3FormatterModel'],
                    'step3FlowMode' => $systemSettings['step3FlowMode'],
                    'maxParallelItems' => $systemSettings['maxParallelItems'],
                    'step2BatchSize' => $systemSettings['step2BatchSize'],
                    'step3BatchSize' => $systemSettings['step3BatchSize'],
                    'deepResearchBatchSize' => $systemSettings['deepResearchBatchSize'],
                    'deepResearchResearchProvider' => $systemSettings['deepResearchResearchProvider'],
                    'deepResearchResearchModel' => $systemSettings['deepResearchResearchModel'],
                    'deepResearchReasoningProvider' => $systemSettings['deepResearchReasoningProvider'],
                    'deepResearchReasoningModel' => $systemSettings['deepResearchReasoningModel'],
                    'deepResearchFormatterProvider' => $systemSettings['deepResearchFormatterProvider'],
                    'deepResearchFormatterModel' => $systemSettings['deepResearchFormatterModel'],
                    'minCreditsPerAiCall' => $this->auditRunService->minimumCreditsPerAiCall($systemSettings['aiProvider'], $systemSettings['aiModel']),
                    'minCreditsPerRun' => $minimumCreditsPerRun,
                    'minCreditsPerUrl' => $minimumCreditsPerRun,
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<string, mixed>
     */
    private function serializeAuditDocument(array $audit): array
    {
        return [
            'id' => (string) ($audit['id'] ?? ''),
            'websiteId' => (string) ($audit['websiteId'] ?? ''),
            'articleUrls' => is_array($audit['articleUrls'] ?? null) ? array_values($audit['articleUrls']) : [],
            'categories' => is_array($audit['categories'] ?? null) ? array_values($audit['categories']) : [],
            'checklistText' => isset($audit['checklistText']) ? (string) $audit['checklistText'] : null,
            'updatedAt' => is_string($audit['updatedAt'] ?? null) ? $audit['updatedAt'] : now()->toIso8601String(),
        ];
    }

    public function store(StoreAuditRunRequest $request)
    {
        $payload = $request->validated();
        $requestedTargetUrls = $this->auditRunService->requestedTargetUrlsForRun($payload);
        $startFromStep = $this->auditRunService->normalizeStartFromStep($payload['startFromStep'] ?? null);
        $stopAfterStep = $this->auditRunService->normalizeStopAfterStep($payload['stopAfterStep'] ?? null, $startFromStep);

        try {
            $run = $this->auditRunService->createRun(
                userUid: (string) $request->attributes->get('firebase_uid'),
                userEmail: (string) $request->attributes->get('firebase_email'),
                payload: $payload,
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $status = str_contains($message, 'đang chạy') ? 409 : 422;

            return response()->json([
                'message' => $message,
            ], $status);
        }

        $queuedTargetUrls = array_values($run->target_urls ?? []);
        $skippedTargetUrls = array_values(array_filter(
            $requestedTargetUrls,
            fn (string $url): bool => ! in_array($url, $queuedTargetUrls, true),
        ));
        $message = 'Audit run queued successfully.';

        if ($startFromStep === AuditRunService::START_FROM_STEP_3) {
            $message = count($skippedTargetUrls) > 0
                ? sprintf(
                    'Đã đưa %d URL đủ dữ liệu bước 2 vào hàng đợi bước 3. Bỏ qua %d URL chưa có đủ keyword + danh mục từ bước 2.',
                    count($queuedTargetUrls),
                    count($skippedTargetUrls),
                )
                : sprintf('Đã đưa %d URL vào hàng đợi bước 3.', count($queuedTargetUrls));
        } elseif ($stopAfterStep === AuditRunService::STOP_AFTER_STEP_2) {
            $message = sprintf(
                'Đã đưa %d URL vào hàng đợi chạy bước 2 và formatter 2.5. Run sẽ dừng sau khi hoàn tất bước 2.',
                count($queuedTargetUrls),
            );
        }

        return response()->json([
            'message' => $message,
            'data' => [
                'publicId' => $run->public_id,
                'status' => $run->status,
                'workflow' => $run->workflow,
                'startFromStep' => $startFromStep,
                'stopAfterStep' => $stopAfterStep,
                'requestedTotalUrls' => count($requestedTargetUrls),
                'totalUrls' => $run->total_urls,
                'queuedTargetUrls' => $queuedTargetUrls,
                'skippedTargetUrls' => $skippedTargetUrls,
            ],
        ], 201);
    }

    public function show(Request $request, string $publicId)
    {
        $run = AuditRun::query()
            ->where('public_id', $publicId)
            ->with('items')
            ->firstOrFail();

        $this->auditRunService->authorizeRead($request, $run);

        return response()->json([
            'data' => $this->auditRunService->serializeRun($run->fresh('items')),
        ]);
    }

    public function stop(Request $request, string $publicId)
    {
        $run = AuditRun::query()
            ->where('public_id', $publicId)
            ->firstOrFail();

        $this->auditRunService->authorizeRead($request, $run);

        if (in_array($run->status, ['completed', 'failed', 'partial'], true)) {
            return response()->json([
                'message' => 'Audit run is already finished.',
                'data' => [
                    'publicId' => $run->public_id,
                    'status' => $run->status,
                ],
            ]);
        }

        $this->auditRunService->stopRun($run, 'Audit run stopped by user.');

        return response()->json([
            'message' => 'Audit run stopped.',
            'data' => [
                'publicId' => $run->public_id,
                'status' => 'failed',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $website
     */
    private function authorizeWebsiteAccess(Request $request, array $website): void
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role', 'user');
        $ownerId = (string) ($website['userId'] ?? '');

        if ($role !== 'admin' && $ownerId !== $uid) {
            throw new AccessDeniedHttpException('You do not have access to this website.');
        }
    }
}
