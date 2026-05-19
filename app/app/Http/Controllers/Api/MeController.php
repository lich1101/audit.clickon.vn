<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CreditService;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    public function show(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $email = (string) $request->attributes->get('firebase_email');
        $profile = $request->attributes->get('firebase_profile');
        $displayName = is_array($profile) ? (string) ($profile['displayName'] ?? '') : '';

        $user = $this->creditService->ensureUser($uid, $email, $displayName !== '' ? $displayName : null);

        return response()->json([
            'data' => $this->creditService->serializeUser($user),
        ]);
    }
}
