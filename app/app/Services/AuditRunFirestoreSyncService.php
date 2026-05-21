<?php

namespace App\Services;

use App\Models\AuditRun;
use App\Models\AuditRunItem;

class AuditRunFirestoreSyncService
{
    public function __construct(
        private readonly FirestoreService $firestoreService,
    ) {
    }

    public function syncRun(AuditRun $run): void
    {
        $this->firestoreService->touchAuditRunSignal([
            'publicId' => $run->public_id,
            'websiteId' => $run->website_id,
            'userId' => $run->user_uid,
            'status' => $run->status,
            'totalUrls' => $run->total_urls,
            'processedUrls' => $run->processed_urls,
            'completedUrls' => $run->completed_urls,
            'failedUrls' => $run->failed_urls,
            'updatedAt' => optional($run->updated_at)?->toIso8601String(),
        ]);
    }

    public function syncItem(AuditRunItem $item): void
    {
        $this->firestoreService->touchAuditRunSignal([
            'publicId' => $item->run->public_id,
            'websiteId' => $item->run->website_id,
            'userId' => $item->run->user_uid,
            'status' => $item->run->status,
            'totalUrls' => $item->run->total_urls,
            'processedUrls' => $item->run->processed_urls,
            'completedUrls' => $item->run->completed_urls,
            'failedUrls' => $item->run->failed_urls,
            'lastItemPublicId' => $item->public_id,
            'lastItemStatus' => $item->status,
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  mixed  $snapshots
     * @return array<string, mixed>
     */
    private function compactPromptSnapshots(mixed $snapshots): array
    {
        if (! is_array($snapshots)) {
            return [];
        }

        $compact = [];

        foreach ($snapshots as $key => $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            $compact[(string) $key] = [
                'step' => $snapshot['step'] ?? (string) $key,
                'provider' => $snapshot['provider'] ?? null,
                'model' => $snapshot['model'] ?? null,
                'createdAt' => $snapshot['createdAt'] ?? null,
                'systemPromptPreview' => mb_substr((string) ($snapshot['systemPrompt'] ?? ''), 0, 1000),
                'userPromptPreview' => mb_substr((string) ($snapshot['userPrompt'] ?? ''), 0, 1000),
            ];
        }

        return $compact;
    }

    /**
     * @param  mixed  $contexts
     * @return array<int, array<string, mixed>>
     */
    private function compactCategoryContexts(mixed $contexts): array
    {
        if (! is_array($contexts)) {
            return [];
        }

        return array_values(array_map(
            fn (array $context): array => [
                'name' => $context['name'] ?? null,
                'url' => $context['url'] ?? null,
                'title' => $context['title'] ?? null,
                'source' => $context['source'] ?? null,
                'error' => $context['error'] ?? null,
                'contentExcerpt' => mb_substr((string) ($context['contentExcerpt'] ?? ''), 0, 1000),
            ],
            array_filter($contexts, 'is_array')
        ));
    }
}
