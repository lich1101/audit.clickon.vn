<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebsiteAuditRequest;
use App\Http\Requests\StoreWebsiteRequest;
use App\Services\FirestoreService;
use App\Support\CategoryInputParser;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WebsiteController extends Controller
{
    public function __construct(
        private readonly FirestoreService $firestoreService,
    ) {
    }

    public function index(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role');
        $websites = $this->firestoreService->listWebsitesForUser($uid, $role === 'admin');

        return response()->json([
            'data' => array_map(fn (array $website): array => $this->serializeWebsite($website), $websites),
        ]);
    }

    public function show(Request $request, string $websiteId)
    {
        $website = $this->firestoreService->getWebsite($websiteId);

        if (! $website) {
            throw new NotFoundHttpException('Website not found.');
        }

        $this->authorizeWebsiteAccess($request, $website);

        return response()->json([
            'data' => $this->serializeWebsite([...$website, 'id' => $websiteId]),
        ]);
    }

    public function store(StoreWebsiteRequest $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validated();
        $website = $this->firestoreService->createWebsite(
            userId: $uid,
            name: trim($validated['name']),
            url: trim($validated['url']),
        );

        return response()->json([
            'message' => 'Website created.',
            'data' => $website,
        ], 201);
    }

    public function showAudit(Request $request, string $websiteId)
    {
        $website = $this->firestoreService->getWebsite($websiteId);

        if (! $website) {
            throw new NotFoundHttpException('Website not found.');
        }

        $this->authorizeWebsiteAccess($request, $website);

        $audit = $this->firestoreService->getWebsiteAuditByWebsiteId($websiteId);

        return response()->json([
            'data' => $audit ? $this->serializeAudit($audit) : null,
        ]);
    }

    public function storeAudit(StoreWebsiteAuditRequest $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validated();
        $website = $this->firestoreService->getWebsite($validated['websiteId']);

        if (! $website) {
            throw new NotFoundHttpException('Website not found.');
        }

        $this->authorizeWebsiteAccess($request, $website);

        $existingAudit = $this->firestoreService->getWebsiteAuditByWebsiteId($validated['websiteId']);

        $audit = $this->firestoreService->upsertWebsiteAudit(
            websiteId: $validated['websiteId'],
            userId: $uid,
            articleUrls: $this->parseLines($validated['articleUrlsInput']),
            categories: CategoryInputParser::parse($validated['categoriesInput']),
            auditId: $validated['auditId'] ?? ($existingAudit['id'] ?? null),
            checklistText: trim((string) ($validated['checklistText'] ?? '')) ?: null,
            aiProvider: $validated['aiProvider'] ?? 'openai',
            aiModel: trim((string) ($validated['aiModel'] ?? '')) ?: null,
        );

        return response()->json([
            'message' => 'Website audit saved.',
            'data' => $audit,
        ]);
    }

    /**
     * @param  array<string, mixed>  $website
     */
    private function authorizeWebsiteAccess(Request $request, array $website): void
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role');
        $ownerId = (string) ($website['userId'] ?? '');

        if ($role !== 'admin' && $ownerId !== $uid) {
            throw new AccessDeniedHttpException('You do not have access to this website.');
        }
    }

    /**
     * @param  array<string, mixed>  $website
     * @return array<string, mixed>
     */
    private function serializeWebsite(array $website): array
    {
        return [
            'id' => (string) ($website['id'] ?? ''),
            'userId' => (string) ($website['userId'] ?? ''),
            'name' => (string) ($website['name'] ?? ''),
            'url' => (string) ($website['url'] ?? ''),
            'createdAt' => $this->serializeTimestamp($website['createdAt'] ?? null),
            'updatedAt' => $this->serializeTimestamp($website['updatedAt'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<string, mixed>
     */
    private function serializeAudit(array $audit): array
    {
        return [
            'id' => (string) ($audit['id'] ?? ''),
            'websiteId' => (string) ($audit['websiteId'] ?? ''),
            'userId' => (string) ($audit['userId'] ?? ''),
            'articleUrls' => is_array($audit['articleUrls'] ?? null) ? array_values($audit['articleUrls']) : [],
            'categories' => is_array($audit['categories'] ?? null) ? array_values($audit['categories']) : [],
            'checklistText' => isset($audit['checklistText']) ? (string) $audit['checklistText'] : null,
            'aiProvider' => in_array($audit['aiProvider'] ?? null, ['openai', 'gemini', 'gemini_deep_research'], true)
                ? $audit['aiProvider']
                : 'openai',
            'aiModel' => isset($audit['aiModel']) && $audit['aiModel'] !== ''
                ? (string) $audit['aiModel']
                : null,
            'createdAt' => $this->serializeTimestamp($audit['createdAt'] ?? null),
            'updatedAt' => $this->serializeTimestamp($audit['updatedAt'] ?? null),
        ];
    }

    private function serializeTimestamp(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return now()->toIso8601String();
    }

    /**
     * @return array<int, string>
     */
    private function parseLines(string $input): array
    {
        return collect(preg_split('/\R/', $input) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->unique()
            ->values()
            ->all();
    }
}
