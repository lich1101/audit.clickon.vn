<?php

namespace App\Services;

use App\Models\AppUser;
use App\Models\AuditRun;
use App\Models\CaptchaSolveTask;
use App\Models\CreditTransaction;
use App\Models\KeywordRankKeyword;
use App\Models\KeywordRankRun;
use App\Models\PlanRequest;
use App\Models\ProductRequest;
use App\Models\Website;
use App\Models\WebsiteAudit;
use App\Models\WebsiteAuditUrlResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Kreait\Laravel\Firebase\Facades\Firebase;
use RuntimeException;
use Throwable;

class AdminUserManagementService
{
    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    public function createUser(
        string $email,
        string $password,
        ?string $displayName = null,
        string $role = 'user',
        float $initialBalanceUsd = 0.0,
        int $initialCaptchaCredits = 0,
    ): AppUser {
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedPassword = trim($password);
        $normalizedDisplayName = $this->normalizeDisplayName($displayName);
        $normalizedRole = $this->normalizeRole($role);
        $initialBalanceUsd = round(max(0, $initialBalanceUsd), 6);
        $initialCaptchaCredits = max(0, $initialCaptchaCredits);

        if ($normalizedPassword === '') {
            throw new RuntimeException('Mật khẩu không được để trống.');
        }

        $auth = Firebase::auth();

        try {
            $existing = $auth->getUserByEmail($normalizedEmail);
            throw new RuntimeException("Email {$normalizedEmail} đã tồn tại với UID {$existing->uid}.");
        } catch (UserNotFound) {
        }

        $firebaseUser = $auth->createUser(array_filter([
            'email' => $normalizedEmail,
            'password' => $normalizedPassword,
            'displayName' => $normalizedDisplayName,
            'emailVerified' => true,
            'disabled' => false,
        ], static fn (mixed $value): bool => $value !== null));

        try {
            DB::transaction(function () use ($firebaseUser, $normalizedEmail, $normalizedDisplayName, $normalizedRole, $initialBalanceUsd, $initialCaptchaCredits): void {
                $user = $this->creditService->ensureUser($firebaseUser->uid, $normalizedEmail, $normalizedDisplayName);
                $user->forceFill([
                    'role' => $normalizedRole,
                    'captcha_credits' => $initialCaptchaCredits,
                ])->save();

                if ($initialBalanceUsd > 0) {
                    $this->creditService->mutateUsd(
                        firebaseUid: $firebaseUser->uid,
                        type: 'add',
                        amountUsd: $initialBalanceUsd,
                        reason: 'Admin tạo tài khoản thủ công và nạp số dư ban đầu.',
                        source: 'admin',
                        referenceType: 'admin_user_create',
                        referenceId: $firebaseUser->uid,
                    );
                }
            });
        } catch (Throwable $exception) {
            try {
                $auth->deleteUser($firebaseUser->uid);
            } catch (Throwable) {
            }

            throw $exception;
        }

        return AppUser::query()->where('firebase_uid', $firebaseUser->uid)->firstOrFail();
    }

    /**
     * @param  array{email?:string|null,password?:string|null,displayName?:string|null,role?:string|null,captchaCredits?:int|null}  $payload
     */
    public function updateUser(AppUser $user, array $payload): AppUser
    {
        $normalizedRole = array_key_exists('role', $payload)
            ? $this->normalizeRole((string) $payload['role'])
            : ($user->role === 'admin' ? 'admin' : 'user');

        if ($user->role === 'admin' && $normalizedRole !== 'admin' && ! $this->hasAnotherAdmin($user->firebase_uid)) {
            throw new RuntimeException('Không thể hạ quyền admin cuối cùng của hệ thống.');
        }

        $firebasePayload = [];
        $localPayload = [];

        if (array_key_exists('email', $payload)) {
            $normalizedEmail = $this->normalizeEmail((string) $payload['email']);
            $firebasePayload['email'] = $normalizedEmail;
            $localPayload['email'] = $normalizedEmail;
        }

        if (array_key_exists('password', $payload)) {
            $normalizedPassword = trim((string) $payload['password']);

            if ($normalizedPassword !== '') {
                $firebasePayload['password'] = $normalizedPassword;
            }
        }

        if (array_key_exists('displayName', $payload)) {
            $normalizedDisplayName = $this->normalizeDisplayName($payload['displayName']);
            $firebasePayload['displayName'] = $normalizedDisplayName;
            $localPayload['display_name'] = $normalizedDisplayName;
        }

        if ($firebasePayload !== []) {
            Firebase::auth()->updateUser($user->firebase_uid, $firebasePayload);
        }

        if (array_key_exists('role', $payload)) {
            $localPayload['role'] = $normalizedRole;
        }

        if (array_key_exists('captchaCredits', $payload)) {
            $localPayload['captcha_credits'] = max(0, (int) ($payload['captchaCredits'] ?? 0));
        }

        if ($localPayload !== []) {
            $user->forceFill($localPayload)->save();
        }

        return $user->fresh();
    }

    public function deleteUser(AppUser $user, string $mode, ?string $transferToUid, ?string $actorUid = null): void
    {
        $normalizedMode = in_array($mode, ['purge', 'transfer'], true) ? $mode : null;

        if ($normalizedMode === null) {
            throw new RuntimeException('Delete mode không hợp lệ.');
        }

        if ($actorUid !== null && trim($actorUid) !== '' && trim($actorUid) === $user->firebase_uid) {
            throw new RuntimeException('Không thể tự xoá chính tài khoản admin đang dùng.');
        }

        if ($user->role === 'admin' && ! $this->hasAnotherAdmin($user->firebase_uid)) {
            throw new RuntimeException('Không thể xoá admin cuối cùng của hệ thống.');
        }

        $summary = $this->ownedDataSummary($user->firebase_uid);

        if (($summary['activeAuditRunCount'] ?? 0) > 0 || ($summary['activeKeywordRankRunCount'] ?? 0) > 0) {
            throw new RuntimeException('Tài khoản này vẫn còn audit run hoặc keyword rank run đang chạy. Hãy dừng/chờ hoàn tất trước khi xoá.');
        }

        $transferTarget = null;

        if ($normalizedMode === 'transfer') {
            $targetUid = trim((string) $transferToUid);

            if ($targetUid === '' || $targetUid === $user->firebase_uid) {
                throw new RuntimeException('Bạn phải chọn tài khoản đích khác tài khoản đang xoá.');
            }

            $transferTarget = AppUser::query()->where('firebase_uid', $targetUid)->first();

            if (! $transferTarget) {
                throw new RuntimeException('Không tìm thấy tài khoản đích để chuyển giao dữ liệu.');
            }
        }

        try {
            Firebase::auth()->deleteUser($user->firebase_uid);
        } catch (UserNotFound) {
        }

        DB::transaction(function () use ($user, $normalizedMode, $transferTarget): void {
            $source = AppUser::query()->where('firebase_uid', $user->firebase_uid)->lockForUpdate()->first();

            if (! $source) {
                return;
            }

            $websiteIds = Website::query()
                ->where('user_uid', $source->firebase_uid)
                ->pluck('id')
                ->map(static fn (mixed $value): string => (string) $value)
                ->values()
                ->all();

            $keywordRunIds = KeywordRankRun::query()
                ->when($websiteIds !== [], fn ($query) => $query->whereIn('website_id', $websiteIds))
                ->orWhere('user_uid', $source->firebase_uid)
                ->pluck('id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->values()
                ->all();

            if ($normalizedMode === 'transfer') {
                $target = AppUser::query()->where('firebase_uid', $transferTarget?->firebase_uid)->lockForUpdate()->firstOrFail();
                $this->transferOwnedData($source, $target, $websiteIds, $keywordRunIds);

                return;
            }

            $this->purgeOwnedData($source, $websiteIds, $keywordRunIds);
        });
    }

    /**
     * @return array<string, int>
     */
    public function ownedDataSummary(string $firebaseUid): array
    {
        $websiteIds = Website::query()
            ->where('user_uid', $firebaseUid)
            ->pluck('id')
            ->map(static fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
        $keywordRunIds = KeywordRankRun::query()
            ->when($websiteIds !== [], fn ($query) => $query->whereIn('website_id', $websiteIds))
            ->orWhere('user_uid', $firebaseUid)
            ->pluck('id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->values()
            ->all();

        return [
            'websiteCount' => count($websiteIds),
            'auditRunCount' => $this->scopedByWebsiteIdsOrUid(AuditRun::query(), $websiteIds, $firebaseUid)->count(),
            'keywordCount' => $this->scopedByWebsiteIdsOrUid(KeywordRankKeyword::query(), $websiteIds, $firebaseUid)->count(),
            'keywordRunCount' => $this->scopedByWebsiteIdsOrUid(KeywordRankRun::query(), $websiteIds, $firebaseUid)->count(),
            'captchaTaskCount' => CaptchaSolveTask::query()
                ->where('user_uid', $firebaseUid)
                ->when($keywordRunIds !== [], fn ($query) => $query->orWhereIn('keyword_rank_run_id', $keywordRunIds))
                ->count(),
            'creditTransactionCount' => CreditTransaction::query()->where('user_uid', $firebaseUid)->count(),
            'planRequestCount' => PlanRequest::query()->where('firebase_uid', $firebaseUid)->count(),
            'productRequestCount' => ProductRequest::query()->where('firebase_uid', $firebaseUid)->count(),
            'activeAuditRunCount' => $this->scopedByWebsiteIdsOrUid(
                AuditRun::query()->whereIn('status', ['queued', 'processing']),
                $websiteIds,
                $firebaseUid,
            )->count(),
            'activeKeywordRankRunCount' => $this->scopedByWebsiteIdsOrUid(
                KeywordRankRun::query()->whereIn('status', ['queued', 'processing']),
                $websiteIds,
                $firebaseUid,
            )->count(),
        ];
    }

    private function purgeOwnedData(AppUser $source, array $websiteIds, array $keywordRunIds): void
    {
        if ($websiteIds !== []) {
            WebsiteAuditUrlResult::query()->whereIn('website_id', $websiteIds)->delete();
        }

        Website::query()
            ->where('same_day_reaudit_granted_by', $source->firebase_uid)
            ->update(['same_day_reaudit_granted_by' => null]);

        $this->scopedByWebsiteIdsOrUid(AuditRun::query(), $websiteIds, $source->firebase_uid)->delete();
        $this->scopedByWebsiteIdsOrUid(KeywordRankRun::query(), $websiteIds, $source->firebase_uid)->delete();
        $this->scopedByWebsiteIdsOrUid(KeywordRankKeyword::query(), $websiteIds, $source->firebase_uid)->delete();

        CaptchaSolveTask::query()
            ->where('user_uid', $source->firebase_uid)
            ->when($keywordRunIds !== [], fn ($query) => $query->orWhereIn('keyword_rank_run_id', $keywordRunIds))
            ->delete();

        PlanRequest::query()->where('firebase_uid', $source->firebase_uid)->delete();
        ProductRequest::query()->where('firebase_uid', $source->firebase_uid)->delete();
        CreditTransaction::query()->where('user_uid', $source->firebase_uid)->delete();

        if ($websiteIds !== []) {
            Website::query()->whereIn('id', $websiteIds)->delete();
        }

        WebsiteAudit::query()->where('user_uid', $source->firebase_uid)->delete();

        $source->delete();
    }

    private function transferOwnedData(AppUser $source, AppUser $target, array $websiteIds, array $keywordRunIds): void
    {
        Website::query()->where('user_uid', $source->firebase_uid)->update([
            'user_uid' => $target->firebase_uid,
        ]);

        Website::query()
            ->where('same_day_reaudit_granted_by', $source->firebase_uid)
            ->update(['same_day_reaudit_granted_by' => $target->firebase_uid]);

        if ($websiteIds !== []) {
            WebsiteAudit::query()->whereIn('website_id', $websiteIds)->update([
                'user_uid' => $target->firebase_uid,
            ]);
        }

        $this->scopedByWebsiteIdsOrUid(AuditRun::query(), $websiteIds, $source->firebase_uid)->update([
            'user_uid' => $target->firebase_uid,
            'user_email' => $target->email,
        ]);

        $this->scopedByWebsiteIdsOrUid(KeywordRankKeyword::query(), $websiteIds, $source->firebase_uid)->update([
            'user_uid' => $target->firebase_uid,
        ]);

        $this->scopedByWebsiteIdsOrUid(KeywordRankRun::query(), $websiteIds, $source->firebase_uid)->update([
            'user_uid' => $target->firebase_uid,
        ]);

        CaptchaSolveTask::query()
            ->where('user_uid', $source->firebase_uid)
            ->when($keywordRunIds !== [], fn ($query) => $query->orWhereIn('keyword_rank_run_id', $keywordRunIds))
            ->update(['user_uid' => $target->firebase_uid]);

        PlanRequest::query()
            ->where('firebase_uid', $source->firebase_uid)
            ->update([
                'firebase_uid' => $target->firebase_uid,
                'user_email' => $target->email,
            ]);

        ProductRequest::query()
            ->where('firebase_uid', $source->firebase_uid)
            ->update([
                'firebase_uid' => $target->firebase_uid,
                'user_email' => $target->email,
            ]);

        CreditTransaction::query()
            ->where('user_uid', $source->firebase_uid)
            ->update(['user_uid' => $target->firebase_uid]);

        $sourceBalanceUsd = round((float) ($source->balance_usd ?? 0), 6);
        $sourceCredits = (int) ($source->credits ?? 0);
        $sourceCaptchaCredits = (int) ($source->captcha_credits ?? 0);

        if ($sourceBalanceUsd > 0 || $sourceCredits > 0 || $sourceCaptchaCredits > 0) {
            $target->forceFill([
                'balance_usd' => round((float) ($target->balance_usd ?? 0) + $sourceBalanceUsd, 6),
                'credits' => (int) ($target->credits ?? 0) + $sourceCredits,
                'captcha_credits' => (int) ($target->captcha_credits ?? 0) + $sourceCaptchaCredits,
            ])->save();
        }

        if (
            (empty($target->keyword_rank_prefs) || ! is_array($target->keyword_rank_prefs))
            && is_array($source->keyword_rank_prefs)
            && $source->keyword_rank_prefs !== []
        ) {
            $target->forceFill(['keyword_rank_prefs' => $source->keyword_rank_prefs])->save();
        }

        $source->delete();
    }

    private function hasAnotherAdmin(string $excludedUid): bool
    {
        return AppUser::query()
            ->where('role', 'admin')
            ->where('firebase_uid', '!=', $excludedUid)
            ->exists();
    }

    private function normalizeEmail(string $email): string
    {
        $normalizedEmail = mb_strtolower(trim($email));

        if ($normalizedEmail === '') {
            throw new RuntimeException('Email không được để trống.');
        }

        return $normalizedEmail;
    }

    private function normalizeDisplayName(?string $displayName): ?string
    {
        $normalized = trim((string) $displayName);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeRole(string $role): string
    {
        return $role === 'admin' ? 'admin' : 'user';
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopedByWebsiteIdsOrUid(Builder $query, array $websiteIds, string $firebaseUid): Builder
    {
        return $query->where(function (Builder $builder) use ($websiteIds, $firebaseUid): void {
            if ($websiteIds !== []) {
                $builder->whereIn('website_id', $websiteIds)->orWhere('user_uid', $firebaseUid);

                return;
            }

            $builder->where('user_uid', $firebaseUid);
        });
    }
}
