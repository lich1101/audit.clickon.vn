<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiUsageBillingReconciliationService;
use Illuminate\Http\Request;

class AiUsageReconciliationController extends Controller
{
    public function __construct(
        private readonly AiUsageBillingReconciliationService $reconciliationService,
    ) {
    }

    public function index(Request $request)
    {
        $report = $this->reconciliationService->report([
            'status' => (string) $request->query('status', 'undercharged'),
            'provider' => $this->nullableString($request->query('provider')),
            'userUid' => $this->nullableString($request->query('userUid')),
            'runPublicId' => $this->nullableString($request->query('runPublicId')),
            'limit' => min(5000, max(1, (int) $request->query('limit', 500))),
        ]);

        return response()->json($report);
    }

    public function backfill(Request $request)
    {
        $payload = $request->validate([
            'provider' => ['nullable', 'string', 'max:64'],
            'userUid' => ['nullable', 'string', 'max:191'],
            'runPublicId' => ['nullable', 'string', 'max:64'],
            'runPublicIds' => ['nullable', 'array'],
            'runPublicIds.*' => ['string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $result = $this->reconciliationService->backfill([
            'provider' => $payload['provider'] ?? null,
            'userUid' => $payload['userUid'] ?? null,
            'runPublicId' => $payload['runPublicId'] ?? null,
            'runPublicIds' => $payload['runPublicIds'] ?? [],
            'limit' => (int) ($payload['limit'] ?? 500),
        ]);

        return response()->json($result);
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
