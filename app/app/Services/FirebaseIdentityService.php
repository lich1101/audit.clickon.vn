<?php

namespace App\Services;

use Illuminate\Auth\AuthenticationException;
use Kreait\Laravel\Firebase\Facades\Firebase;

class FirebaseIdentityService
{
    public function __construct(
        private readonly CreditService $creditService,
        private readonly WebsiteDataService $websiteDataService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function authenticate(string $idToken): array
    {
        try {
            $verifiedToken = Firebase::auth()->verifyIdToken($idToken, true);
            $uid = (string) $verifiedToken->claims()->get('sub');
            $email = (string) $verifiedToken->claims()->get('email', '');
            $displayName = (string) $verifiedToken->claims()->get('name', '');
            $user = $this->creditService->ensureUser($uid, $email, $displayName !== '' ? $displayName : null);

            return [
                'firebase_uid' => $uid,
                'firebase_email' => $email,
                'firebase_role' => $user->role === 'admin' ? 'admin' : 'user',
                'firebase_profile' => $this->creditService->serializeUser($user),
            ];
        } catch (\Throwable $exception) {
            throw new AuthenticationException($exception->getMessage(), [$exception->getMessage()]);
        }
    }
}
