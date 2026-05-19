<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiModelCatalogService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AiModelController extends Controller
{
    public function __construct(
        private readonly AiModelCatalogService $catalogService,
    ) {
    }

    public function index(Request $request, string $provider)
    {
        if (! in_array($provider, ['openai', 'gemini', 'gemini_deep_research'], true)) {
            throw new NotFoundHttpException('AI provider not found.');
        }

        return response()->json([
            'data' => $this->catalogService->listForProvider($provider),
        ]);
    }
}
