<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
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

    public function update(Request $request)
    {
        $validated = $request->validate([
            'displayName' => ['nullable', 'string', 'max:255'],
        ]);

        $uid = (string) $request->attributes->get('firebase_uid');
        $email = (string) $request->attributes->get('firebase_email');

        /** @var AppUser $user */
        $user = $this->creditService->ensureUser($uid, $email);
        $user->forceFill([
            'display_name' => array_key_exists('displayName', $validated) ? $validated['displayName'] : $user->display_name,
        ])->save();

        return response()->json([
            'data' => $this->creditService->serializeUser($user->fresh()),
        ]);
    }
}
