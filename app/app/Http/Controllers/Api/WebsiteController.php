<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebsiteAuditRequest;
use App\Http\Requests\StoreWebsiteRequest;
use App\Services\WebsiteDataService;
use App\Support\CategoryInputParser;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WebsiteController extends Controller
{
    public function __construct(
        private readonly WebsiteDataService $websiteDataService,
    ) {
    }

    public function index(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role');

        return response()->json([
            'data' => $this->websiteDataService->listForUser($uid, $role === 'admin'),
        ]);
    }

    public function show(Request $request, string $websiteId)
    {
        $website = $this->websiteDataService->getWebsite($websiteId);

        if (! $website) {
            throw new NotFoundHttpException('Website not found.');
        }

        $this->authorizeWebsiteAccess($request, $website);

        return response()->json(['data' => $website]);
    }

    public function store(StoreWebsiteRequest $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validated();
        $website = $this->websiteDataService->createWebsite(
            firebaseUid: $uid,
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
        $website = $this->websiteDataService->getWebsite($websiteId);

        if (! $website) {
            throw new NotFoundHttpException('Website not found.');
        }

        $this->authorizeWebsiteAccess($request, $website);

        return response()->json([
            'data' => $this->websiteDataService->getAuditByWebsiteId($websiteId),
        ]);
    }

    public function storeAudit(StoreWebsiteAuditRequest $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validated();
        $website = $this->websiteDataService->getWebsite($validated['websiteId']);

        if (! $website) {
            throw new NotFoundHttpException('Website not found.');
        }

        $this->authorizeWebsiteAccess($request, $website);

        $existingAudit = $this->websiteDataService->getAuditByWebsiteId($validated['websiteId']);

        $audit = $this->websiteDataService->upsertAudit(
            websiteId: $validated['websiteId'],
            firebaseUid: $uid,
            articleUrls: $this->parseLines($validated['articleUrlsInput']),
            categories: CategoryInputParser::parse($validated['categoriesInput']),
            auditId: $validated['auditId'] ?? ($existingAudit['id'] ?? null),
            checklistText: trim((string) ($validated['checklistText'] ?? '')) ?: null,
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
