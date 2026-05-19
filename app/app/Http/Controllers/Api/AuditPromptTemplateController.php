<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditPromptTemplateRequest;
use App\Services\AuditPromptTemplateService;

class AuditPromptTemplateController extends Controller
{
    public function __construct(
        private readonly AuditPromptTemplateService $promptTemplateService,
    ) {
    }

    public function index()
    {
        return response()->json([
            'data' => $this->promptTemplateService->all(),
        ]);
    }

    public function update(AuditPromptTemplateRequest $request, string $step)
    {
        return response()->json([
            'message' => 'Audit prompt template updated.',
            'data' => $this->promptTemplateService->upsert($step, $request->validated()),
        ]);
    }

    public function reset(string $step)
    {
        return response()->json([
            'message' => 'Audit prompt template reset to default.',
            'data' => $this->promptTemplateService->reset($step),
        ]);
    }
}
