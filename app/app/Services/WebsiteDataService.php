<?php

namespace App\Services;

use App\Models\AuditRun;
use App\Models\Website;
use App\Models\WebsiteAudit;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use RuntimeException;

class WebsiteDataService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(string $firebaseUid, bool $isAdmin): array
    {
        $query = Website::query()->with(['audit', 'activeRun'])->orderByDesc('updated_at');

        if (! $isAdmin) {
            $query->where('user_uid', $firebaseUid);
        }

        return $query->get()->map(fn (Website $website): array => $this->serializeWebsite($website))->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getWebsite(string $websiteId): ?array
    {
        $website = Website::query()->with(['audit', 'activeRun'])->find($websiteId);

        if ($website) {
            return $this->serializeWebsite($website);
        }

        return $this->importLegacyWebsite($websiteId);
    }

    public function findWebsiteModel(string $websiteId): ?Website
    {
        $website = Website::query()->find($websiteId);

        if ($website) {
            return $website;
        }

        $this->importLegacyWebsite($websiteId);

        return Website::query()->find($websiteId);
    }

    /**
     * @return array<string, mixed>
     */
    public function createWebsite(string $firebaseUid, string $name, string $url): array
    {
        $website = Website::query()->create([
            'id' => strtolower(str_replace('-', '', (string) Str::ulid())),
            'user_uid' => $firebaseUid,
            'name' => $name,
            'url' => $url,
        ]);

        return $this->serializeWebsite($website);
    }

    /**
     * @return array<string, mixed>
     */
    public function grantSameDayReaudit(string $websiteId, ?string $grantedByUid): array
    {
        $website = $this->findWebsiteModel($websiteId);

        if (! $website) {
            throw new RuntimeException('Website not found.');
        }

        $website->forceFill([
            'same_day_reaudit_granted_until' => now()->endOfDay(),
            'same_day_reaudit_granted_by' => $grantedByUid,
        ])->save();

        return $this->serializeWebsite($website->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeSameDayReaudit(string $websiteId): array
    {
        $website = $this->findWebsiteModel($websiteId);

        if (! $website) {
            throw new RuntimeException('Website not found.');
        }

        $website->forceFill([
            'same_day_reaudit_granted_until' => null,
            'same_day_reaudit_granted_by' => null,
        ])->save();

        return $this->serializeWebsite($website->fresh());
    }

    /**
     * @param  array<int, string>  $articleUrls
     * @param  array<int, array{name:string,url:string}>  $categories
     * @return array<string, mixed>
     */
    public function upsertAudit(
        string $websiteId,
        string $firebaseUid,
        array $articleUrls,
        array $categories,
        ?string $auditId = null,
        ?string $checklistText = null,
    ): array {
        $website = Website::query()->findOrFail($websiteId);
        $documentId = $auditId ?: ($website->audit?->id ?? strtolower(str_replace('-', '', (string) Str::ulid())));

        $audit = WebsiteAudit::query()->updateOrCreate(
            ['website_id' => $websiteId],
            [
                'id' => $documentId,
                'user_uid' => $firebaseUid,
                'article_urls' => array_values($articleUrls),
                'categories' => array_values($categories),
                'checklist_text' => $checklistText,
            ],
        );

        return $this->serializeAudit($audit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAuditByWebsiteId(string $websiteId): ?array
    {
        $audit = WebsiteAudit::query()->where('website_id', $websiteId)->first();

        if ($audit) {
            return $this->serializeAudit($audit);
        }

        $this->importLegacyWebsite($websiteId);

        $audit = WebsiteAudit::query()->where('website_id', $websiteId)->first();

        return $audit ? $this->serializeAudit($audit) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function importLegacyWebsite(string $websiteId): ?array
    {
        if (! config('services.audit.firestore_fallback', false)) {
            return null;
        }

        $legacy = app(FirestoreService::class)->getWebsite($websiteId);

        if (! $legacy) {
            return null;
        }

        $website = Website::query()->updateOrCreate(
            ['id' => $websiteId],
            [
                'user_uid' => (string) ($legacy['userId'] ?? ''),
                'name' => (string) ($legacy['name'] ?? ''),
                'url' => (string) ($legacy['url'] ?? ''),
            ],
        );

        $legacyAudit = app(FirestoreService::class)->getWebsiteAuditByWebsiteId($websiteId);

        if (is_array($legacyAudit)) {
            WebsiteAudit::query()->updateOrCreate(
                ['website_id' => $websiteId],
                [
                    'id' => (string) ($legacyAudit['id'] ?? strtolower(str_replace('-', '', (string) Str::ulid()))),
                    'user_uid' => (string) ($legacyAudit['userId'] ?? $website->user_uid),
                    'article_urls' => is_array($legacyAudit['articleUrls'] ?? null) ? array_values($legacyAudit['articleUrls']) : [],
                    'categories' => is_array($legacyAudit['categories'] ?? null) ? array_values($legacyAudit['categories']) : [],
                    'checklist_text' => $legacyAudit['checklistText'] ?? null,
                ],
            );
        }

        return $this->serializeWebsite($website->fresh('audit'));
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeWebsite(Website $website): array
    {
        $grantUntil = $website->same_day_reaudit_granted_until;

        return [
            'id' => $website->id,
            'userId' => $website->user_uid,
            'name' => $website->name,
            'url' => $website->url,
            'sameDayReauditGrantedUntil' => $grantUntil instanceof CarbonInterface
                ? $grantUntil->toIso8601String()
                : null,
            'sameDayReauditGrantedBy' => $website->same_day_reaudit_granted_by,
            'createdAt' => optional($website->created_at)?->toIso8601String(),
            'updatedAt' => optional($website->updated_at)?->toIso8601String(),
            'activeRun' => $website->relationLoaded('activeRun') && $website->activeRun
                ? $this->serializeActiveRun($website->activeRun)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeActiveRun(AuditRun $run): array
    {
        return [
            'publicId' => $run->public_id,
            'status' => $run->status,
            'totalUrls' => $run->total_urls,
            'processedUrls' => $run->processed_urls,
            'completedUrls' => $run->completed_urls,
            'failedUrls' => $run->failed_urls,
            'createdAt' => optional($run->created_at)?->toIso8601String(),
            'updatedAt' => optional($run->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAudit(WebsiteAudit $audit): array
    {
        return [
            'id' => $audit->id,
            'websiteId' => $audit->website_id,
            'userId' => $audit->user_uid,
            'articleUrls' => $audit->article_urls ?? [],
            'categories' => $audit->categories ?? [],
            'checklistText' => $audit->checklist_text,
            'createdAt' => optional($audit->created_at)?->toIso8601String(),
            'updatedAt' => optional($audit->updated_at)?->toIso8601String(),
        ];
    }
}
