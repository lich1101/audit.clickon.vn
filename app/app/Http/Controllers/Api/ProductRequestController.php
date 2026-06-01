<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlanRequestDecisionRequest;
use App\Models\Product;
use App\Models\ProductRequest;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductRequestController extends Controller
{
    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    public function index(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');

        return response()->json([
            'data' => ProductRequest::query()
                ->where('firebase_uid', $uid)
                ->latest()
                ->get()
                ->map(fn (ProductRequest $row): array => $this->transform($row))
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'productId' => ['required', 'string'],
        ]);

        $product = Product::query()->find($validated['productId']);

        if (! $product || ! $product->is_active) {
            return response()->json([
                'message' => 'Product does not exist or is inactive.',
            ], 422);
        }

        $productRequest = ProductRequest::query()->create([
            'firebase_uid' => (string) $request->attributes->get('firebase_uid'),
            'user_email' => (string) $request->attributes->get('firebase_email'),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_type' => $product->type,
            'price' => (int) $product->price,
            'captcha_credits' => (int) $product->captcha_credits,
            'balance_usd' => round((float) $product->balance_usd, 2),
            'credits' => (int) $product->credits,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Product request created successfully.',
            'data' => $this->transform($productRequest),
        ], 201);
    }

    public function adminIndex()
    {
        return response()->json([
            'data' => ProductRequest::query()
                ->latest()
                ->get()
                ->map(fn (ProductRequest $row): array => $this->transform($row))
                ->values(),
        ]);
    }

    public function approve(PlanRequestDecisionRequest $request, ProductRequest $productRequest)
    {
        try {
            $approved = DB::transaction(function () use ($request, $productRequest): ProductRequest {
                /** @var ProductRequest $lockedProductRequest */
                $lockedProductRequest = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->id);

                if ($lockedProductRequest->status !== 'pending') {
                    throw new RuntimeException('Only pending requests can be approved.');
                }

                if ($lockedProductRequest->product_type === Product::TYPE_CAPTCHA_PACK) {
                    $this->creditService->addCaptchaCredits(
                        $lockedProductRequest->firebase_uid,
                        (int) $lockedProductRequest->captcha_credits,
                    );
                } elseif ($lockedProductRequest->product_type === Product::TYPE_AUDIT_CREDIT) {
                    $amountUsd = (float) $lockedProductRequest->balance_usd;
                    if ($amountUsd <= 0 && (int) $lockedProductRequest->credits > 0) {
                        $amountUsd = $this->creditService->usdForCredits((int) $lockedProductRequest->credits);
                    }

                    if ($amountUsd > 0) {
                        $this->creditService->mutateUsd(
                            firebaseUid: $lockedProductRequest->firebase_uid,
                            type: 'add',
                            amountUsd: $amountUsd,
                            reason: "Approved product {$lockedProductRequest->product_name}",
                            source: 'plan',
                            referenceType: 'product_request',
                            referenceId: (string) $lockedProductRequest->id,
                        );
                    }
                }

                $lockedProductRequest->forceFill([
                    'status' => 'approved',
                    'note' => $request->validated('note'),
                    'approved_by' => (string) $request->attributes->get('firebase_uid', 'system'),
                    'approved_at' => now(),
                ])->save();

                return $lockedProductRequest->fresh();
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Product request approved.',
            'data' => $this->transform($approved),
        ]);
    }

    public function reject(PlanRequestDecisionRequest $request, ProductRequest $productRequest)
    {
        try {
            $rejected = DB::transaction(function () use ($request, $productRequest): ProductRequest {
                /** @var ProductRequest $lockedProductRequest */
                $lockedProductRequest = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->id);

                if ($lockedProductRequest->status !== 'pending') {
                    throw new RuntimeException('Only pending requests can be rejected.');
                }

                $lockedProductRequest->forceFill([
                    'status' => 'rejected',
                    'note' => $request->validated('note'),
                    'approved_by' => (string) $request->attributes->get('firebase_uid', 'system'),
                    'approved_at' => now(),
                ])->save();

                return $lockedProductRequest->fresh();
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Product request rejected.',
            'data' => $this->transform($rejected),
        ]);
    }

    private function transform(ProductRequest $productRequest): array
    {
        return [
            'id' => $productRequest->id,
            'firebaseUid' => $productRequest->firebase_uid,
            'productId' => $productRequest->product_id,
            'productName' => $productRequest->product_name,
            'productType' => $productRequest->product_type,
            'price' => $productRequest->price,
            'captchaCredits' => (int) $productRequest->captcha_credits,
            'balanceUsd' => round((float) $productRequest->balance_usd, 2),
            'credits' => (int) $productRequest->credits,
            'status' => $productRequest->status,
            'note' => $productRequest->note,
            'approvedBy' => $productRequest->approved_by,
            'approvedAt' => optional($productRequest->approved_at)?->toIso8601String(),
            'createdAt' => optional($productRequest->created_at)?->toIso8601String(),
            'updatedAt' => optional($productRequest->updated_at)?->toIso8601String(),
        ];
    }
}
