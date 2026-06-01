<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Services\AdminUserManagementService;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly CreditService $creditService,
        private readonly AdminUserManagementService $userManagementService,
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
            'data' => array_merge(
                $this->creditService->serializeUser($user),
                ['ownedDataSummary' => $this->userManagementService->ownedDataSummary($user->firebase_uid)],
            ),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'displayName' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'in:user,admin'],
            'captchaCredits' => ['nullable', 'integer', 'min:0'],
            'balanceUsd' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $this->userManagementService->createUser(
            email: (string) $validated['email'],
            password: (string) $validated['password'],
            displayName: $validated['displayName'] ?? null,
            role: (string) ($validated['role'] ?? 'user'),
            initialBalanceUsd: round((float) ($validated['balanceUsd'] ?? 0), 6),
            initialCaptchaCredits: (int) ($validated['captchaCredits'] ?? 0),
        );

        return response()->json([
            'data' => array_merge(
                $this->creditService->serializeUser($user),
                ['ownedDataSummary' => $this->userManagementService->ownedDataSummary($user->firebase_uid)],
            ),
        ], 201);
    }

    public function update(Request $request, string $firebaseUid)
    {
        $user = AppUser::query()->where('firebase_uid', $firebaseUid)->firstOrFail();

        $validated = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'displayName' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'in:user,admin'],
            'captchaCredits' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = $this->userManagementService->updateUser($user, $validated);

        return response()->json([
            'data' => array_merge(
                $this->creditService->serializeUser($user->fresh()),
                ['ownedDataSummary' => $this->userManagementService->ownedDataSummary($user->firebase_uid)],
            ),
        ]);
    }

    public function destroy(Request $request, string $firebaseUid)
    {
        $user = AppUser::query()->where('firebase_uid', $firebaseUid)->first();

        if (! $user) {
            throw new NotFoundHttpException('User not found.');
        }

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:purge,transfer'],
            'transferToUid' => ['nullable', 'string', 'max:255'],
        ]);

        $this->userManagementService->deleteUser(
            user: $user,
            mode: (string) $validated['mode'],
            transferToUid: $validated['transferToUid'] ?? null,
            actorUid: (string) $request->attributes->get('firebase_uid', ''),
        );

        return response()->json([
            'ok' => true,
            'message' => 'Đã xoá tài khoản người dùng.',
        ]);
    }
}
