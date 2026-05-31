<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KeywordRankSettingsService;
use Illuminate\Http\Request;

class KeywordRankSettingsController extends Controller
{
    public function __construct(
        private readonly KeywordRankSettingsService $keywordRankSettingsService,
    ) {
    }

    public function showPublic()
    {
        return response()->json([
            'data' => $this->keywordRankSettingsService->getSettings(),
        ]);
    }

    public function showAdmin()
    {
        return response()->json([
            'data' => $this->keywordRankSettingsService->getSettings(),
        ]);
    }

    public function updateAdmin(Request $request)
    {
        $validated = $request->validate([
            'extensionInstallUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        return response()->json([
            'data' => $this->keywordRankSettingsService->updateSettings($validated),
        ]);
    }
}
