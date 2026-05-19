<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = AppUser::query()->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('email', 'like', "%{$search}%")
                    ->orWhere('firebase_uid', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->limit(500)->get()->map(fn (AppUser $user): array => $this->creditService->serializeUser($user))->values(),
        ]);
    }

    public function show(string $firebaseUid)
    {
        $user = AppUser::query()->where('firebase_uid', $firebaseUid)->first();

        if (! $user) {
            throw new NotFoundHttpException('User not found.');
        }

        return response()->json([
            'data' => $this->creditService->serializeUser($user),
        ]);
    }

    public function update(Request $request, string $firebaseUid)
    {
        $user = AppUser::query()->where('firebase_uid', $firebaseUid)->firstOrFail();

        $validated = $request->validate([
            'displayName' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'in:user,admin'],
        ]);

        $user->forceFill([
            'display_name' => array_key_exists('displayName', $validated) ? $validated['displayName'] : $user->display_name,
            'role' => $validated['role'] ?? $user->role,
        ])->save();

        return response()->json([
            'data' => $this->creditService->serializeUser($user->fresh()),
        ]);
    }
}
