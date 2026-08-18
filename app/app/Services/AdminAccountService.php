<?php

namespace App\Services;

use Illuminate\Support\Facades\Hash;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Kreait\Laravel\Firebase\Facades\Firebase;
use RuntimeException;

class AdminAccountService
{
    public function __construct(
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

        if (LocalAuthToken::enabled()) {
            $uid = $normalizedUid !== null && $normalizedUid !== ''
                ? $normalizedUid
                : 'local_'.substr(hash('sha256', $normalizedEmail), 0, 24);
            $user = $this->creditService->ensureUser($uid, $normalizedEmail, $normalizedDisplayName !== '' ? $normalizedDisplayName : 'Admin');
            $created = $user->wasRecentlyCreated;
            $user->forceFill([
                'password_hash' => Hash::make($normalizedPassword),
                'role' => 'admin',
                'display_name' => $normalizedDisplayName !== '' ? $normalizedDisplayName : ($user->display_name ?: 'Admin'),
                'balance_usd' => max((float) $user->balance_usd, 100),
            ])->save();

            return [
                'uid' => $user->firebase_uid,
                'email' => $normalizedEmail,
                'displayName' => $user->display_name,
                'created' => $created,
            ];
        }

        try {
            $auth = Firebase::auth();
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
        $user = $this->creditService->ensureUser($uid, $email, $displayName);
        $user->forceFill(['role' => 'admin'])->save();
    }
}
