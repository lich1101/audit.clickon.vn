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
            'captcha_credits' => 0,
            'balance_usd' => 0,
        ]);
    }

    public function getBalance(string $firebaseUid): int
    {
        return (int) (AppUser::query()->where('firebase_uid', $firebaseUid)->value('credits') ?? 0);
    }

    public function getBalanceUsd(string $firebaseUid): float
    {
        return round((float) (AppUser::query()->where('firebase_uid', $firebaseUid)->value('balance_usd') ?? 0), 6);
    }

    public function legacyCreditUsdRate(): float
    {
        return max(0.000001, (float) config('services.audit.legacy_credit_usd_value', 0.01));
    }

    public function usdForCredits(int $credits): float
    {
        return round(max(0, $credits) * $this->legacyCreditUsdRate(), 6);
    }

    public function creditsForUsd(float $amountUsd): int
    {
        return (int) ceil(max(0.0, $amountUsd) / $this->legacyCreditUsdRate());
    }

    public function getCaptchaCredits(string $firebaseUid): int
    {
        return (int) (AppUser::query()->where('firebase_uid', $firebaseUid)->value('captcha_credits') ?? 0);
    }

    public function addCaptchaCredits(string $firebaseUid, int $amount): int
    {
        if ($amount <= 0) {
            return $this->getCaptchaCredits($firebaseUid);
        }

        return DB::transaction(function () use ($firebaseUid, $amount): int {
            /** @var AppUser $user */
            $user = AppUser::query()->where('firebase_uid', $firebaseUid)->lockForUpdate()->firstOrFail();
            $user->forceFill([
                'captcha_credits' => (int) $user->captcha_credits + $amount,
            ])->save();

            return (int) $user->captcha_credits;
        });
    }

    public function consumeCaptchaCredit(string $firebaseUid): int
    {
        return DB::transaction(function () use ($firebaseUid): int {
            /** @var AppUser $user */
            $user = AppUser::query()->where('firebase_uid', $firebaseUid)->lockForUpdate()->firstOrFail();
            $before = (int) $user->captcha_credits;

            if ($before <= 0) {
                throw new RuntimeException('Insufficient captcha credits.');
            }

            $user->forceFill([
                'captcha_credits' => $before - 1,
            ])->save();

            return (int) $user->captcha_credits;
        });
    }

    /**
     * @return array{credits:int,balanceUsd:float,log:array<string,mixed>}
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
        return $this->mutateUsd(
            firebaseUid: $firebaseUid,
            type: $type,
            amountUsd: $this->usdForCredits($amount),
            reason: $reason,
            source: $source,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    /**
     * @return array{credits:int,balanceUsd:float,log:array<string,mixed>}
     */
    public function mutateUsd(
        string $firebaseUid,
        string $type,
        float $amountUsd,
        string $reason,
        string $source = 'system',
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): array {
        if (! in_array($type, ['add', 'subtract'], true)) {
            throw new RuntimeException('Invalid credit mutation type.');
        }

        $amountUsd = round($amountUsd, 6);

        if ($amountUsd <= 0) {
            throw new RuntimeException('USD amount must be greater than zero.');
        }

        return DB::transaction(function () use ($firebaseUid, $type, $amountUsd, $reason, $source, $referenceType, $referenceId): array {
            /** @var AppUser $user */
            $user = AppUser::query()->where('firebase_uid', $firebaseUid)->lockForUpdate()->firstOrFail();
            $beforeUsd = round((float) $user->balance_usd, 6);
            $afterUsd = round($type === 'add' ? $beforeUsd + $amountUsd : $beforeUsd - $amountUsd, 6);

            if ($afterUsd < -0.000001) {
                throw new RuntimeException('Insufficient balance.');
            }

            $beforeCredits = (int) $user->credits;
            $creditDelta = $this->creditsForUsd($amountUsd);
            $afterCredits = $type === 'add' ? $beforeCredits + $creditDelta : max(0, $beforeCredits - $creditDelta);

            $user->forceFill([
                'balance_usd' => max(0, $afterUsd),
                'credits' => $afterCredits,
            ])->save();

            $transaction = CreditTransaction::query()->create([
                'public_id' => (string) Str::ulid(),
                'user_uid' => $firebaseUid,
                'type' => $type,
                'amount' => $creditDelta,
                'amount_usd' => $amountUsd,
                'balance_before' => $beforeCredits,
                'balance_after' => $afterCredits,
                'balance_before_usd' => $beforeUsd,
                'balance_after_usd' => max(0, $afterUsd),
                'reason' => $reason,
                'source' => $source,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            return [
                'credits' => $afterCredits,
                'balanceUsd' => max(0, $afterUsd),
                'log' => $this->serializeTransaction($transaction),
            ];
        });
    }

    /**
     * Apply an exact USD + credits delta without re-deriving credits from USD.
     * Useful for billing reconciliation where the legacy ceil() conversion would
     * otherwise introduce a second rounding error on the delta itself.
     *
     * @return array{credits:int,balanceUsd:float,log:array<string,mixed>}
     */
    public function mutateUsdWithExactCredits(
        string $firebaseUid,
        string $type,
        float $amountUsd,
        int $creditDelta,
        string $reason,
        string $source = 'system',
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): array {
        if (! in_array($type, ['add', 'subtract'], true)) {
            throw new RuntimeException('Invalid credit mutation type.');
        }

        $amountUsd = round($amountUsd, 6);
        $creditDelta = max(0, $creditDelta);

        if ($amountUsd <= 0 && $creditDelta <= 0) {
            throw new RuntimeException('USD amount or credit delta must be greater than zero.');
        }

        return DB::transaction(function () use ($firebaseUid, $type, $amountUsd, $creditDelta, $reason, $source, $referenceType, $referenceId): array {
            /** @var AppUser $user */
            $user = AppUser::query()->where('firebase_uid', $firebaseUid)->lockForUpdate()->firstOrFail();
            $beforeUsd = round((float) $user->balance_usd, 6);
            $afterUsd = round($type === 'add' ? $beforeUsd + $amountUsd : $beforeUsd - $amountUsd, 6);

            if ($afterUsd < -0.000001) {
                throw new RuntimeException('Insufficient balance.');
            }

            $beforeCredits = (int) $user->credits;
            $afterCredits = $type === 'add'
                ? $beforeCredits + $creditDelta
                : max(0, $beforeCredits - $creditDelta);

            $user->forceFill([
                'balance_usd' => max(0, $afterUsd),
                'credits' => $afterCredits,
            ])->save();

            $transaction = CreditTransaction::query()->create([
                'public_id' => (string) Str::ulid(),
                'user_uid' => $firebaseUid,
                'type' => $type,
                'amount' => $creditDelta,
                'amount_usd' => $amountUsd,
                'balance_before' => $beforeCredits,
                'balance_after' => $afterCredits,
                'balance_before_usd' => $beforeUsd,
                'balance_after_usd' => max(0, $afterUsd),
                'reason' => $reason,
                'source' => $source,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            return [
                'credits' => $afterCredits,
                'balanceUsd' => max(0, $afterUsd),
                'log' => $this->serializeTransaction($transaction),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTransaction(CreditTransaction $transaction): array
    {
        $amountUsd = is_numeric($transaction->amount_usd ?? null)
            ? round((float) $transaction->amount_usd, 6)
            : $this->usdForCredits((int) $transaction->amount);
        $balanceBeforeUsd = is_numeric($transaction->balance_before_usd ?? null)
            ? round((float) $transaction->balance_before_usd, 6)
            : $this->usdForCredits((int) $transaction->balance_before);
        $balanceAfterUsd = is_numeric($transaction->balance_after_usd ?? null)
            ? round((float) $transaction->balance_after_usd, 6)
            : $this->usdForCredits((int) $transaction->balance_after);

        return [
            'id' => $transaction->public_id,
            'userId' => $transaction->user_uid,
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'amountUsd' => $amountUsd,
            'balanceBefore' => $transaction->balance_before,
            'balanceAfter' => $transaction->balance_after,
            'balanceBeforeUsd' => $balanceBeforeUsd,
            'balanceAfterUsd' => $balanceAfterUsd,
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
        $balanceUsd = round((float) ($user->balance_usd ?? 0), 6);

        return [
            'uid' => $user->firebase_uid,
            'email' => $user->email,
            'displayName' => $user->display_name,
            'role' => $user->role === 'admin' ? 'admin' : 'user',
            'balanceUsd' => $balanceUsd,
            'credits' => (int) $user->credits,
            'captchaCredits' => (int) $user->captcha_credits,
            'legacyCreditsPerUsd' => (int) round(1 / $this->legacyCreditUsdRate()),
            'createdAt' => optional($user->created_at)?->toIso8601String(),
            'updatedAt' => optional($user->updated_at)?->toIso8601String(),
        ];
    }
}
