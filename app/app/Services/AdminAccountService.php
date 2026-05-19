<?php

namespace App\Services;

use Kreait\Firebase\Exception\Auth\UserNotFound;
use Kreait\Laravel\Firebase\Facades\Firebase;
use RuntimeException;

class AdminAccountService
{
    public function __construct(
        private readonly FirestoreService $firestoreService,
        private readonly CreditService $creditService,
    ) {
    }

    /**
     * @return array{uid:string,email:string,displayName:?string,created:bool}
     */
    public function createOrUpdateAdmin(
        string $email,
        string $password,
        ?string $displayName = null,
        ?string $uid = null,
        bool $emailVerified = true,
    ): array {
        $auth = Firebase::auth();
        $normalizedEmail = mb_strtolower(trim($email));
        $normalizedPassword = trim($password);
        $normalizedDisplayName = $displayName !== null ? trim($displayName) : null;
        $normalizedUid = $uid !== null ? trim($uid) : null;

        if ($normalizedEmail === '') {
            throw new RuntimeException('Email admin không được để trống.');
        }

        if ($normalizedPassword === '') {
            throw new RuntimeException('Mật khẩu admin không được để trống.');
        }

        try {
            $user = $auth->getUserByEmail($normalizedEmail);

            if ($normalizedUid !== null && $normalizedUid !== '' && $user->uid !== $normalizedUid) {
                throw new RuntimeException("Email {$normalizedEmail} đang thuộc UID {$user->uid}, không khớp UID bạn yêu cầu.");
            }

            $auth->updateUser($user->uid, array_filter([
                'email' => $normalizedEmail,
                'password' => $normalizedPassword,
                'displayName' => $normalizedDisplayName !== '' ? $normalizedDisplayName : null,
                'emailVerified' => $emailVerified,
                'disabled' => false,
            ], static fn (mixed $value): bool => $value !== null));

            $this->seedAdminProfile(
                uid: $user->uid,
                email: $normalizedEmail,
                displayName: $normalizedDisplayName !== '' ? $normalizedDisplayName : null,
            );

            return [
                'uid' => $user->uid,
                'email' => $normalizedEmail,
                'displayName' => $normalizedDisplayName !== '' ? $normalizedDisplayName : null,
                'created' => false,
            ];
        } catch (UserNotFound) {
            $payload = array_filter([
                'uid' => $normalizedUid !== '' ? $normalizedUid : null,
                'email' => $normalizedEmail,
                'password' => $normalizedPassword,
                'displayName' => $normalizedDisplayName !== '' ? $normalizedDisplayName : null,
                'emailVerified' => $emailVerified,
                'disabled' => false,
            ], static fn (mixed $value): bool => $value !== null);

            $user = $auth->createUser($payload);

            $this->seedAdminProfile(
                uid: $user->uid,
                email: $normalizedEmail,
                displayName: $normalizedDisplayName !== '' ? $normalizedDisplayName : null,
            );

            return [
                'uid' => $user->uid,
                'email' => $normalizedEmail,
                'displayName' => $normalizedDisplayName !== '' ? $normalizedDisplayName : null,
                'created' => true,
            ];
        }
    }

    public function seedExistingAdminProfile(string $uid, string $email, ?string $displayName = null): void
    {
        $normalizedUid = trim($uid);
        $normalizedEmail = mb_strtolower(trim($email));
        $normalizedDisplayName = $displayName !== null ? trim($displayName) : null;

        if ($normalizedUid === '') {
            throw new RuntimeException('Firebase UID admin không được để trống.');
        }

        if ($normalizedEmail === '') {
            throw new RuntimeException('Email admin không được để trống.');
        }

        $this->seedAdminProfile(
            uid: $normalizedUid,
            email: $normalizedEmail,
            displayName: $normalizedDisplayName !== '' ? $normalizedDisplayName : null,
        );
    }

    private function seedAdminProfile(string $uid, string $email, ?string $displayName = null): void
    {
        $this->firestoreService->seedAdmin($uid, $email, $displayName);

        $user = $this->creditService->ensureUser($uid, $email, $displayName);
        $user->forceFill(['role' => 'admin'])->save();
    }
}
