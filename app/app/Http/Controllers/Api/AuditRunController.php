<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAuditRunRequest;
use App\Models\AuditRun;
use App\Services\AuditRunService;
use Illuminate\Http\Request;

class AuditRunController extends Controller
{
    public function __construct(
        private readonly AuditRunService $auditRunService,
    ) {
    }

    public function store(StoreAuditRunRequest $request)
    {
        $run = $this->auditRunService->createRun(
            userUid: (string) $request->attributes->get('firebase_uid'),
            userEmail: (string) $request->attributes->get('firebase_email'),
            payload: $request->validated(),
        );

        return response()->json([
            'message' => 'Audit run queued successfully.',
            'data' => [
                'publicId' => $run->public_id,
                'status' => $run->status,
                'totalUrls' => $run->total_urls,
            ],
        ], 201);
    }

    public function show(Request $request, string $publicId)
    {
        $run = AuditRun::query()
            ->where('public_id', $publicId)
            ->with('items')
            ->firstOrFail();

        $this->auditRunService->authorizeRead($request, $run);

        return response()->json([
            'data' => $this->auditRunService->serializeRun($run->fresh('items')),
        ]);
    }
}
