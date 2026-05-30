<?php

namespace App\Services;

use App\Models\AppUser;
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
    public function authenticate(string $idToken, ?string $impersonateUid = null): array
    {
        try {
            $verifiedToken = Firebase::auth()->verifyIdToken($idToken, true);
            $uid = (string) $verifiedToken->claims()->get('sub');
            $email = (string) $verifiedToken->claims()->get('email', '');
            $displayName = (string) $verifiedToken->claims()->get('name', '');
            $actor = $this->creditService->ensureUser($uid, $email, $displayName !== '' ? $displayName : null);
            $actorRole = $actor->role === 'admin' ? 'admin' : 'user';

            if ($impersonateUid !== null && trim($impersonateUid) !== '' && trim($impersonateUid) !== $uid) {
                if ($actorRole !== 'admin') {
                    throw new AuthenticationException('Admin role required for impersonation.');
                }

                /** @var AppUser|null $target */
                $target = AppUser::query()->where('firebase_uid', trim($impersonateUid))->first();

                if (! $target) {
                    throw new AuthenticationException('Impersonation target user not found.');
                }

                return [
                    'firebase_uid' => $target->firebase_uid,
                    'firebase_email' => $target->email,
                    'firebase_role' => $target->role === 'admin' ? 'admin' : 'user',
                    'firebase_profile' => $this->creditService->serializeUser($target),
                    'actor_uid' => $actor->firebase_uid,
                    'actor_email' => $actor->email,
                    'actor_role' => $actorRole,
                    'actor_profile' => $this->creditService->serializeUser($actor),
                    'impersonated_by_admin' => true,
                ];
            }

            return [
                'firebase_uid' => $uid,
                'firebase_email' => $email,
                'firebase_role' => $actorRole,
                'firebase_profile' => $this->creditService->serializeUser($actor),
                'actor_uid' => $actor->firebase_uid,
                'actor_email' => $actor->email,
                'actor_role' => $actorRole,
                'actor_profile' => $this->creditService->serializeUser($actor),
                'impersonated_by_admin' => false,
            ];
        } catch (\Throwable $exception) {
            throw new AuthenticationException($exception->getMessage(), [$exception->getMessage()]);
        }
    }
}
