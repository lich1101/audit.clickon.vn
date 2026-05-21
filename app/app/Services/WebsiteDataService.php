<?php

namespace App\Services;

use App\Models\Website;
use App\Models\WebsiteAudit;
use Illuminate\Support\Str;

class WebsiteDataService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(string $firebaseUid, bool $isAdmin): array
    {
        $query = Website::query()->with('audit')->orderByDesc('updated_at');

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
        $website = Website::query()->with('audit')->find($websiteId);

        if ($website) {
            return $this->serializeWebsite($website);
        }

        return $this->importLegacyWebsite($websiteId);
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
        return [
            'id' => $website->id,
            'userId' => $website->user_uid,
            'name' => $website->name,
            'url' => $website->url,
            'createdAt' => optional($website->created_at)?->toIso8601String(),
            'updatedAt' => optional($website->updated_at)?->toIso8601String(),
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
