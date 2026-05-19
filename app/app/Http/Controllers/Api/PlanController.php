<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $activeOnly = $request->boolean('activeOnly', true);

        $query = Plan::query()->orderBy('price');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Plan $plan): array => $this->serialize($plan))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:0'],
            'credits' => ['required', 'integer', 'min:1'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $plan = Plan::query()->create([
            'id' => strtolower(str_replace('-', '', (string) Str::ulid())),
            'name' => $validated['name'],
            'price' => (int) $validated['price'],
            'credits' => (int) $validated['credits'],
            'is_active' => $validated['isActive'] ?? true,
        ]);

        return response()->json(['data' => $this->serialize($plan)], 201);
    }

    public function show(string $planId)
    {
        $plan = Plan::query()->find($planId);

        if (! $plan) {
            throw new NotFoundHttpException('Plan not found.');
        }

        return response()->json(['data' => $this->serialize($plan)]);
    }

    public function update(Request $request, string $planId)
    {
        $plan = Plan::query()->findOrFail($planId);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'credits' => ['sometimes', 'integer', 'min:1'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $plan->forceFill([
            'name' => $validated['name'] ?? $plan->name,
            'price' => array_key_exists('price', $validated) ? (int) $validated['price'] : $plan->price,
            'credits' => array_key_exists('credits', $validated) ? (int) $validated['credits'] : $plan->credits,
            'is_active' => array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : $plan->is_active,
        ])->save();

        return response()->json(['data' => $this->serialize($plan->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'price' => (int) $plan->price,
            'credits' => (int) $plan->credits,
            'isActive' => (bool) $plan->is_active,
            'createdAt' => optional($plan->created_at)?->toIso8601String(),
            'updatedAt' => optional($plan->updated_at)?->toIso8601String(),
        ];
    }
}
