<?php

namespace App\Services;

use App\Models\AppUser;
use App\Models\CreditTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreditService
{
    public function ensureUser(string $firebaseUid, string $email, ?string $displayName = null): AppUser
    {
        $user = AppUser::query()->where('firebase_uid', $firebaseUid)->first();

        if ($user) {
            $user->forceFill([
                'email' => $email !== '' ? $email : $user->email,
                'display_name' => $displayName ?? $user->display_name,
            ])->save();

            return $user->fresh();
        }

        return AppUser::query()->create([
            'firebase_uid' => $firebaseUid,
            'email' => $email,
            'display_name' => $displayName,
            'role' => 'user',
            'credits' => 0,
        ]);
    }

    public function getBalance(string $firebaseUid): int
    {
        return (int) (AppUser::query()->where('firebase_uid', $firebaseUid)->value('credits') ?? 0);
    }

    /**
     * @return array{credits:int,log:array<string,mixed>}
     */
    public function mutate(
        string $firebaseUid,
        string $type,
        int $amount,
        string $reason,
        string $source = 'system',
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): array {
        if (! in_array($type, ['add', 'subtract'], true)) {
            throw new RuntimeException('Invalid credit mutation type.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Credit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($firebaseUid, $type, $amount, $reason, $source, $referenceType, $referenceId): array {
            /** @var AppUser $user */
            $user = AppUser::query()->where('firebase_uid', $firebaseUid)->lockForUpdate()->firstOrFail();
            $before = (int) $user->credits;
            $after = $type === 'add' ? $before + $amount : $before - $amount;

            if ($after < 0) {
                throw new RuntimeException('Insufficient credits.');
            }

            $user->forceFill(['credits' => $after])->save();

            $transaction = CreditTransaction::query()->create([
                'public_id' => (string) Str::ulid(),
                'user_uid' => $firebaseUid,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason' => $reason,
                'source' => $source,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            return [
                'credits' => $after,
                'log' => $this->serializeTransaction($transaction),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTransaction(CreditTransaction $transaction): array
    {
        return [
            'id' => $transaction->public_id,
            'userId' => $transaction->user_uid,
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'balanceBefore' => $transaction->balance_before,
            'balanceAfter' => $transaction->balance_after,
            'reason' => $transaction->reason,
            'source' => $transaction->source,
            'referenceType' => $transaction->reference_type,
            'referenceId' => $transaction->reference_id,
            'createdAt' => optional($transaction->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeUser(AppUser $user): array
    {
        return [
            'uid' => $user->firebase_uid,
            'email' => $user->email,
            'displayName' => $user->display_name,
            'role' => $user->role === 'admin' ? 'admin' : 'user',
            'credits' => (int) $user->credits,
            'createdAt' => optional($user->created_at)?->toIso8601String(),
            'updatedAt' => optional($user->updated_at)?->toIso8601String(),
        ];
    }
}
