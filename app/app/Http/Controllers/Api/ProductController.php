<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductController extends Controller
{
    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    public function index(Request $request)
    {
        $activeOnly = $request->boolean('activeOnly', true);

        $query = Product::query()->orderBy('price');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Product $product): array => $this->serialize($product))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $product = Product::query()->create([
            'id' => strtolower(str_replace('-', '', (string) Str::ulid())),
            'name' => $validated['name'],
            'type' => $validated['type'],
            'price' => (int) $validated['price'],
            'captcha_credits' => (int) ($validated['captchaCredits'] ?? 0),
            'balance_usd' => round((float) ($validated['balanceUsd'] ?? 0), 2),
            'credits' => $this->resolveCredits($validated),
            'is_active' => $validated['isActive'] ?? true,
        ]);

        return response()->json(['data' => $this->serialize($product)], 201);
    }

    public function show(string $productId)
    {
        $product = Product::query()->find($productId);

        if (! $product) {
            throw new NotFoundHttpException('Product not found.');
        }

        return response()->json(['data' => $this->serialize($product)]);
    }

    public function update(Request $request, string $productId)
    {
        $product = Product::query()->findOrFail($productId);
        $validated = $this->validatePayload($request, partial: true);

        $product->forceFill([
            'name' => $validated['name'] ?? $product->name,
            'type' => $validated['type'] ?? $product->type,
            'price' => array_key_exists('price', $validated) ? (int) $validated['price'] : $product->price,
            'captcha_credits' => array_key_exists('captchaCredits', $validated) ? (int) $validated['captchaCredits'] : $product->captcha_credits,
            'balance_usd' => array_key_exists('balanceUsd', $validated) ? round((float) $validated['balanceUsd'], 2) : $product->balance_usd,
            'credits' => array_key_exists('credits', $validated) || array_key_exists('balanceUsd', $validated)
                ? $this->resolveCredits(array_merge(['type' => $product->type], $validated))
                : $product->credits,
            'is_active' => array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : $product->is_active,
        ])->save();

        return response()->json(['data' => $this->serialize($product->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'type' => $product->type,
            'price' => (int) $product->price,
            'captchaCredits' => (int) $product->captcha_credits,
            'balanceUsd' => round((float) $product->balance_usd, 2),
            'credits' => (int) $product->credits,
            'isActive' => (bool) $product->is_active,
            'createdAt' => optional($product->created_at)?->toIso8601String(),
            'updatedAt' => optional($product->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => [$partial ? 'sometimes' : 'required', 'in:captcha_pack,audit_credit'],
            'price' => [$partial ? 'sometimes' : 'required', 'integer', 'min:0'],
            'captchaCredits' => ['nullable', 'integer', 'min:0'],
            'balanceUsd' => ['nullable', 'numeric', 'min:0'],
            'credits' => ['nullable', 'integer', 'min:0'],
            'isActive' => ['nullable', 'boolean'],
        ];

        $validated = $request->validate($rules);
        $type = (string) ($validated['type'] ?? '');

        if ($type === Product::TYPE_CAPTCHA_PACK && ! $partial) {
            $request->validate(['captchaCredits' => ['required', 'integer', 'min:1']]);
        }

        if ($type === Product::TYPE_AUDIT_CREDIT && ! $partial) {
            $request->validate([
                'balanceUsd' => ['required_without:credits', 'numeric', 'min:0.01'],
                'credits' => ['required_without:balanceUsd', 'integer', 'min:1'],
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveCredits(array $validated): int
    {
        if (isset($validated['credits']) && is_numeric($validated['credits'])) {
            return max(0, (int) $validated['credits']);
        }

        return max(0, $this->creditService->creditsForUsd((float) ($validated['balanceUsd'] ?? 0)));
    }
}
