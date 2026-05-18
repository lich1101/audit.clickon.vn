<?php

namespace App\Services;

use Illuminate\Auth\AuthenticationException;
use Kreait\Laravel\Firebase\Facades\Firebase;

class FirebaseIdentityService
{
    public function __construct(
        private readonly FirestoreService $firestoreService,
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
            $profile = $this->firestoreService->getUser($uid) ?? [];

            return [
                'firebase_uid' => $uid,
                'firebase_email' => $email,
                'firebase_role' => ($profile['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
                'firebase_profile' => $profile,
            ];
        } catch (\Throwable $exception) {
            throw new AuthenticationException($exception->getMessage(), [$exception->getMessage()]);
        }
    }
}
