<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlanRequestDecisionRequest;
use App\Http\Requests\PlanRequestStoreRequest;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Services\CreditService;
use Illuminate\Http\Request;

class PlanRequestController extends Controller
{
    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    public function index(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');

        return response()->json([
            'data' => PlanRequest::query()
                ->where('firebase_uid', $uid)
                ->latest()
                ->get()
                ->map(fn (PlanRequest $planRequest): array => $this->transform($planRequest))
                ->values(),
        ]);
    }

    public function store(PlanRequestStoreRequest $request)
    {
        $plan = Plan::query()->find($request->validated('planId'));

        if (! $plan || ! $plan->is_active) {
            return response()->json([
                'message' => 'Plan does not exist or is inactive.',
            ], 422);
        }

        $planRequest = PlanRequest::query()->create([
            'firebase_uid' => (string) $request->attributes->get('firebase_uid'),
            'user_email' => (string) $request->attributes->get('firebase_email'),
            'plan_id' => $request->validated('planId'),
            'plan_name' => $plan->name,
            'price' => (int) $plan->price,
            'credits' => (int) $plan->credits,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Plan request created successfully.',
            'data' => $this->transform($planRequest),
        ], 201);
    }

    public function adminIndex()
    {
        return response()->json([
            'data' => PlanRequest::query()
                ->latest()
                ->get()
                ->map(fn (PlanRequest $planRequest): array => $this->transform($planRequest))
                ->values(),
        ]);
    }

    public function approve(PlanRequestDecisionRequest $request, PlanRequest $planRequest)
    {
        if ($planRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending requests can be approved.',
            ], 422);
        }

        $this->creditService->mutate(
            firebaseUid: $planRequest->firebase_uid,
            type: 'add',
            amount: $planRequest->credits,
            reason: "Approved plan {$planRequest->plan_name}",
            source: 'plan',
            referenceType: 'plan_request',
            referenceId: (string) $planRequest->id,
        );

        $planRequest->forceFill([
            'status' => 'approved',
            'note' => $request->validated('note'),
            'approved_by' => (string) $request->attributes->get('firebase_uid', 'system'),
            'approved_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Plan request approved.',
            'data' => $this->transform($planRequest->fresh()),
        ]);
    }

    public function reject(PlanRequestDecisionRequest $request, PlanRequest $planRequest)
    {
        if ($planRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending requests can be rejected.',
            ], 422);
        }

        $planRequest->forceFill([
            'status' => 'rejected',
            'note' => $request->validated('note'),
            'approved_by' => (string) $request->attributes->get('firebase_uid', 'system'),
            'approved_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Plan request rejected.',
            'data' => $this->transform($planRequest->fresh()),
        ]);
    }

    private function transform(PlanRequest $planRequest): array
    {
        return [
            'id' => $planRequest->id,
            'firebaseUid' => $planRequest->firebase_uid,
            'planId' => $planRequest->plan_id,
            'planName' => $planRequest->plan_name,
            'price' => $planRequest->price,
            'credits' => $planRequest->credits,
            'status' => $planRequest->status,
            'note' => $planRequest->note,
            'approvedBy' => $planRequest->approved_by,
            'approvedAt' => optional($planRequest->approved_at)?->toIso8601String(),
            'createdAt' => optional($planRequest->created_at)?->toIso8601String(),
            'updatedAt' => optional($planRequest->updated_at)?->toIso8601String(),
        ];
    }
}
