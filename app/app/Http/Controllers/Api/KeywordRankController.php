<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KeywordRankRun;
use App\Models\Website;
use App\Services\KeywordRankService;
use App\Services\WebsiteDataService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class KeywordRankController extends Controller
{
    public function __construct(
        private readonly WebsiteDataService $websiteDataService,
        private readonly KeywordRankService $keywordRankService,
    ) {
    }

    public function board(Request $request, string $websiteId)
    {
        $website = $this->authorizedWebsite($request, $websiteId);
        $uid = (string) $request->attributes->get('firebase_uid');

        return response()->json([
            'data' => $this->keywordRankService->board($website, $uid),
        ]);
    }

    public function replaceKeywords(Request $request, string $websiteId)
    {
        $website = $this->authorizedWebsite($request, $websiteId);
        $uid = (string) $request->attributes->get('firebase_uid');

        $validated = $request->validate([
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['required', 'string', 'max:255'],
        ]);

        return response()->json([
            'data' => $this->keywordRankService->replaceKeywords($website, $uid, $validated['keywords']),
        ]);
    }

    public function createRun(Request $request, string $websiteId)
    {
        $website = $this->authorizedWebsite($request, $websiteId);
        $uid = (string) $request->attributes->get('firebase_uid');

        $validated = $request->validate([
            'keywordIds' => ['required', 'array', 'min:1'],
            'keywordIds.*' => ['required', 'string', 'max:64'],
            'captchaEnabled' => ['nullable', 'boolean'],
        ]);

        try {
            $run = $this->keywordRankService->createRun(
                website: $website,
                userUid: $uid,
                keywordIds: $validated['keywordIds'],
                captchaEnabled: (bool) ($validated['captchaEnabled'] ?? false),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Đã tạo phiên check thứ hạng keyword.',
            'data' => $this->keywordRankService->serializeRun($run, true),
        ], 201);
    }

    public function recordItem(Request $request, string $publicId)
    {
        $run = $this->authorizedRun($request, $publicId);

        $validated = $request->validate([
            'keywordId' => ['nullable', 'string', 'max:64'],
            'keyword' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:found,not_found,blocked,error,stopped'],
            'rank' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'matchedUrl' => ['nullable', 'string', 'max:4096'],
            'title' => ['nullable', 'string', 'max:2048'],
            'error' => ['nullable', 'string', 'max:4096'],
            'checkedAt' => ['nullable', 'date'],
        ]);

        $item = $this->keywordRankService->recordRunItem($run, $validated);

        return response()->json([
            'data' => $this->keywordRankService->serializeRunItem($item),
        ]);
    }

    public function completeRun(Request $request, string $publicId)
    {
        $run = $this->authorizedRun($request, $publicId);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:completed,partial,failed,stopped'],
            'error' => ['nullable', 'string', 'max:4096'],
        ]);

        $completed = $this->keywordRankService->completeRun(
            run: $run,
            status: $validated['status'] ?? null,
            error: $validated['error'] ?? null,
        );

        return response()->json([
            'data' => $this->keywordRankService->serializeRun($completed, true),
        ]);
    }

    public function updatePreferences(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');

        $validated = $request->validate([
            'delayMin' => ['nullable', 'integer', 'min:1', 'max:120'],
            'delayMax' => ['nullable', 'integer', 'min:1', 'max:180'],
            'autoCaptcha' => ['nullable', 'boolean'],
            'googleHost' => ['nullable', 'string', 'in:https://www.google.com,https://www.google.com.vn'],
            'hl' => ['nullable', 'string', 'max:8'],
            'gl' => ['nullable', 'string', 'max:8'],
            'updatedAt' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json([
            'data' => $this->keywordRankService->updateUserPreferences($uid, $validated),
        ]);
    }

    private function authorizedWebsite(Request $request, string $websiteId): Website
    {
        $website = $this->websiteDataService->findWebsiteModel($websiteId);

        if (! $website) {
            throw new NotFoundHttpException('Website not found.');
        }

        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role');

        if ($role !== 'admin' && $website->user_uid !== $uid) {
            throw new AccessDeniedHttpException('You do not have access to this website.');
        }

        return $website;
    }

    private function authorizedRun(Request $request, string $publicId): KeywordRankRun
    {
        $run = KeywordRankRun::query()->where('public_id', $publicId)->first();

        if (! $run) {
            throw new NotFoundHttpException('Keyword rank run not found.');
        }

        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role');

        if ($role !== 'admin' && $run->user_uid !== $uid) {
            throw new AccessDeniedHttpException('You do not have access to this run.');
        }

        return $run;
    }
}
