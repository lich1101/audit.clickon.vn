<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Services\CreditService;
use App\Services\LocalAuthToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LocalAuthController extends Controller
{
    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    public function login(Request $request)
    {
        if (! LocalAuthToken::enabled()) {
            return response()->json(['message' => 'Local auth is disabled.'], 404);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        /** @var AppUser|null $user */
        $user = AppUser::query()->where('email', $email)->first();

        if (! $user || ! $user->password_hash || ! Hash::check($validated['password'], $user->password_hash)) {
            return response()->json(['message' => 'Email hoặc mật khẩu không đúng.'], 401);
        }

        return response()->json([
            'token' => LocalAuthToken::issue($user),
            'user' => $this->creditService->serializeUser($user),
        ]);
    }
}
