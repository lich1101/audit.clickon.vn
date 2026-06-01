<?php

namespace App\Services;

use App\Models\CaptchaSolveTask;
use App\Models\AppUser;
use App\Models\KeywordRankKeyword;
use App\Models\KeywordRankRun;
use App\Models\KeywordRankRunItem;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class KeywordRankService
{
    private const STALE_RUN_SECONDS = 180;
    private const STALE_RUN_ERROR = 'Keyword rank extension đã dừng hoặc tab chạy đã bị đóng trước khi hoàn tất.';

    public function __construct(
        private readonly CreditService $creditService,
        private readonly TwoCaptchaService $twoCaptchaService,
        private readonly KeywordRankSettingsService $keywordRankSettingsService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function board(Website $website, string $userUid): array
    {
        $this->recoverStaleRunsForWebsite($website);

        $keywords = KeywordRankKeyword::query()
            ->where('website_id', $website->id)
            ->orderBy('keyword')
            ->get()
            ->map(fn (KeywordRankKeyword $keyword): array => $this->serializeKeyword($keyword))
            ->values();

        $latestRun = KeywordRankRun::query()
            ->where('website_id', $website->id)
            ->latest()
            ->with('items')
            ->first();

        $rankSettings = $this->keywordRankSettingsService->getSettings();

        return [
            'website' => [
                'id' => $website->id,
                'name' => $website->name,
                'url' => $website->url,
                'userId' => $website->user_uid,
            ],
            'targetDomain' => $this->domainFromUrl($website->url),
            'keywords' => $keywords,
            'latestRun' => $latestRun ? $this->serializeRun($latestRun, true) : null,
            'captchaCredits' => $this->creditService->getCaptchaCredits($userUid),
            'preferences' => $this->getUserPreferences($userUid),
            'extension' => [
                'bridgeMessageVersion' => 1,
                'required' => true,
                'installUrl' => $rankSettings['extensionInstallUrl'],
            ],
            'serpPages' => KeywordRankSettingsService::SERP_PAGES,
        ];
    }

    /**
     * @return array{delayMin:int,delayMax:int,autoCaptcha:bool,googleHost:string,hl:string,gl:string}
     */
    public function getUserPreferences(string $userUid): array
    {
        $defaults = $this->defaultPreferences();
        $user = AppUser::query()->where('firebase_uid', $userUid)->first();
        $stored = is_array($user?->keyword_rank_prefs) ? $user->keyword_rank_prefs : [];

        return $this->normalizePreferences(array_merge($defaults, $stored));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{delayMin:int,delayMax:int,autoCaptcha:bool,googleHost:string,hl:string,gl:string}
     */
    public function updateUserPreferences(string $userUid, array $payload): array
    {
        $user = AppUser::query()->where('firebase_uid', $userUid)->firstOrFail();
        $merged = $this->normalizePreferences(array_merge($this->getUserPreferences($userUid), $payload));

        if (isset($payload['updatedAt']) && is_string($payload['updatedAt']) && trim($payload['updatedAt']) !== '') {
            $merged['updatedAt'] = trim($payload['updatedAt']);
        } else {
            $merged['updatedAt'] = now()->toIso8601String();
        }

        $user->forceFill(['keyword_rank_prefs' => $merged])->save();

        return $merged;
    }

    /**
     * @return array{delayMin:int,delayMax:int,autoCaptcha:bool,googleHost:string,hl:string,gl:string}
     */
    private function defaultPreferences(): array
    {
        return [
            'delayMin' => 3,
            'delayMax' => 6,
            'autoCaptcha' => false,
            'googleHost' => 'https://www.google.com',
            'hl' => 'vi',
            'gl' => 'vn',
            'proxyEnabled' => false,
            'proxyUrls' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $prefs
     * @return array{delayMin:int,delayMax:int,autoCaptcha:bool,googleHost:string,hl:string,gl:string,proxyEnabled:bool,proxyUrls:array<int,string>}
     */
    private function normalizePreferences(array $prefs): array
    {
        $delayMin = max(2, min(120, (int) ($prefs['delayMin'] ?? 3)));
        $delayMax = max($delayMin, min(180, (int) ($prefs['delayMax'] ?? 6)));
        $googleHost = trim((string) ($prefs['googleHost'] ?? 'https://www.google.com'));
        if (! in_array($googleHost, ['https://www.google.com', 'https://www.google.com.vn'], true)) {
            $googleHost = 'https://www.google.com';
        }

        $proxyUrls = [];
        if (isset($prefs['proxyUrls']) && is_array($prefs['proxyUrls'])) {
            foreach ($prefs['proxyUrls'] as $line) {
                $line = trim((string) $line);
                if ($line !== '' && strlen($line) <= 512) {
                    $proxyUrls[] = $line;
                }
            }
        }

        $proxyUrls = array_values(array_unique($proxyUrls));
        $proxyUrls = array_slice($proxyUrls, 0, 50);

        return [
            'delayMin' => $delayMin,
            'delayMax' => $delayMax,
            'autoCaptcha' => (bool) ($prefs['autoCaptcha'] ?? false),
            'googleHost' => $googleHost,
            'hl' => preg_replace('/[^a-z-]/', '', strtolower((string) ($prefs['hl'] ?? 'vi'))) ?: 'vi',
            'gl' => preg_replace('/[^a-z-]/', '', strtolower((string) ($prefs['gl'] ?? 'vn'))) ?: 'vn',
            'proxyEnabled' => (bool) ($prefs['proxyEnabled'] ?? false) && $proxyUrls !== [],
            'proxyUrls' => $proxyUrls,
            'updatedAt' => isset($prefs['updatedAt']) && is_string($prefs['updatedAt']) && trim($prefs['updatedAt']) !== ''
                ? trim($prefs['updatedAt'])
                : null,
        ];
    }

    /**
     * @param  array<int, string>  $keywords
     * @return array<int, array<string, mixed>>
     */
    public function replaceKeywords(Website $website, string $userUid, array $keywords): array
    {
        $normalized = collect($keywords)
            ->map(fn ($keyword): string => $this->normalizeKeyword((string) $keyword))
            ->filter(fn (string $keyword): bool => $keyword !== '')
            ->unique(fn (string $keyword): string => mb_strtolower($keyword, 'UTF-8'))
            ->take(5000)
            ->values();

        return DB::transaction(function () use ($website, $userUid, $normalized): array {
            $keepIds = [];

            foreach ($normalized as $keyword) {
                /** @var KeywordRankKeyword $model */
                $model = KeywordRankKeyword::query()->firstOrNew([
                    'website_id' => $website->id,
                    'keyword' => $keyword,
                ]);

                if (! $model->exists) {
                    $model->id = strtolower(str_replace('-', '', (string) Str::ulid()));
                }

                $model->user_uid = $userUid;
                $model->save();

                $keepIds[] = $model->id;
            }

            KeywordRankKeyword::query()
                ->where('website_id', $website->id)
                ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
                ->delete();

            return KeywordRankKeyword::query()
                ->where('website_id', $website->id)
                ->orderBy('keyword')
                ->get()
                ->map(fn (KeywordRankKeyword $keyword): array => $this->serializeKeyword($keyword))
                ->values()
                ->all();
        });
    }

    /**
     * @param  array<int, string>  $keywordIds
     */
    public function createRun(Website $website, string $userUid, array $keywordIds, bool $captchaEnabled): KeywordRankRun
    {
        $this->recoverStaleRunsForWebsite($website);

        $keywords = KeywordRankKeyword::query()
            ->where('website_id', $website->id)
            ->whereIn('id', $keywordIds)
            ->orderBy('keyword')
            ->get();

        if ($keywords->isEmpty()) {
            throw new RuntimeException('Chọn ít nhất một keyword để check thứ hạng.');
        }

        if ($captchaEnabled && $this->creditService->getCaptchaCredits($userUid) <= 0) {
            throw new RuntimeException('Không còn lượt giải captcha tự động.');
        }

        return DB::transaction(function () use ($website, $userUid, $keywords, $captchaEnabled): KeywordRankRun {
            Website::query()
                ->where('id', $website->id)
                ->lockForUpdate()
                ->first();

            $activeRun = KeywordRankRun::query()
                ->where('website_id', $website->id)
                ->whereIn('status', ['queued', 'processing'])
                ->lockForUpdate()
                ->first();

            if ($activeRun) {
                throw new RuntimeException('Website này đang có một phiên check keyword rank khác đang chạy. Hãy chờ phiên hiện tại hoàn tất hoặc dừng nó trước.');
            }

            $run = KeywordRankRun::query()->create([
                'public_id' => (string) Str::ulid(),
                'website_id' => $website->id,
                'user_uid' => $userUid,
                'target_domain' => $this->domainFromUrl($website->url),
                'status' => 'processing',
                'captcha_enabled' => $captchaEnabled,
                'total_keywords' => $keywords->count(),
                'started_at' => now(),
            ]);

            foreach ($keywords as $keyword) {
                KeywordRankRunItem::query()->create([
                    'keyword_rank_run_id' => $run->id,
                    'keyword_rank_keyword_id' => $keyword->id,
                    'keyword' => $keyword->keyword,
                    'status' => 'queued',
                ]);
            }

            return $run->fresh('items');
        });
    }

    public function recordRunItem(KeywordRankRun $run, array $payload): KeywordRankRunItem
    {
        $keywordId = (string) ($payload['keywordId'] ?? '');
        $keyword = trim((string) ($payload['keyword'] ?? ''));

        if ($keywordId === '' && $keyword === '') {
            throw new RuntimeException('Thiếu keywordId hoặc keyword.');
        }

        $status = $this->normalizeStatus((string) ($payload['status'] ?? 'failed'));
        $parsedCheckedAt = isset($payload['checkedAt']) ? date_create((string) $payload['checkedAt']) : false;
        $checkedAt = $parsedCheckedAt ?: now();

        return DB::transaction(function () use ($run, $payload, $keywordId, $keyword, $status, $checkedAt): KeywordRankRunItem {
            /** @var KeywordRankRun $lockedRun */
            $lockedRun = KeywordRankRun::query()->lockForUpdate()->findOrFail($run->id);

            $item = KeywordRankRunItem::query()
                ->where('keyword_rank_run_id', $lockedRun->id)
                ->when($keywordId !== '', fn ($query) => $query->where('keyword_rank_keyword_id', $keywordId))
                ->when($keywordId === '', fn ($query) => $query->where('keyword', $keyword))
                ->lockForUpdate()
                ->first();

            if (! $item) {
                $item = KeywordRankRunItem::query()->create([
                    'keyword_rank_run_id' => $lockedRun->id,
                    'keyword_rank_keyword_id' => $keywordId ?: null,
                    'keyword' => $keyword,
                    'status' => 'queued',
                ]);
            }

            $wasProcessed = $item->status !== 'queued';
            $wasCompleted = $item->status === 'found' || $item->status === 'not_found';
            $wasFailed = in_array($item->status, ['blocked', 'error', 'stopped'], true);

            $item->forceFill([
                'status' => $status,
                'rank' => is_numeric($payload['rank'] ?? null) ? (int) $payload['rank'] : null,
                'page' => is_numeric($payload['page'] ?? null) ? (int) $payload['page'] : null,
                'matched_url' => $payload['matchedUrl'] ?? null,
                'title' => $payload['title'] ?? null,
                'error_message' => $payload['error'] ?? null,
                'raw_payload' => $payload,
                'checked_at' => $checkedAt,
            ])->save();

            if ($keywordId !== '') {
                KeywordRankKeyword::query()->where('id', $keywordId)->update([
                    'latest_status' => $status,
                    'latest_rank' => $item->rank,
                    'latest_page' => $item->page,
                    'latest_url' => $item->matched_url,
                    'latest_title' => $item->title,
                    'latest_error' => $item->error_message,
                    'latest_checked_at' => $checkedAt,
                    'updated_at' => now(),
                ]);
            }

            $isCompleted = $status === 'found' || $status === 'not_found';
            $isFailed = in_array($status, ['blocked', 'error', 'stopped'], true);

            $lockedRun->forceFill([
                'processed_keywords' => max(0, (int) $lockedRun->processed_keywords + ($wasProcessed ? 0 : 1)),
                'completed_keywords' => max(0, (int) $lockedRun->completed_keywords + ($isCompleted ? 1 : 0) - ($wasCompleted ? 1 : 0)),
                'failed_keywords' => max(0, (int) $lockedRun->failed_keywords + ($isFailed ? 1 : 0) - ($wasFailed ? 1 : 0)),
                'last_error' => $isFailed ? ($item->error_message ?: $lockedRun->last_error) : $lockedRun->last_error,
            ])->save();

            $this->refreshRunCompletion($lockedRun);

            return $item->fresh();
        });
    }

    public function heartbeatRun(KeywordRankRun $run): KeywordRankRun
    {
        if ($run->status !== 'processing') {
            return $run->fresh('items');
        }

        $run->touch();

        return $run->fresh('items');
    }

    public function completeRun(KeywordRankRun $run, ?string $status = null, ?string $error = null): KeywordRankRun
    {
        $run->forceFill([
            'status' => $status ?: ((int) $run->failed_keywords > 0 ? 'partial' : 'completed'),
            'last_error' => $error ?: $run->last_error,
            'completed_at' => now(),
        ])->save();

        return $run->fresh('items');
    }

    public function createCaptchaTask(KeywordRankRun $run, string $userUid, array $payload): CaptchaSolveTask
    {
        if (! $run->captcha_enabled) {
            throw new RuntimeException('Run này không bật giải captcha tự động.');
        }

        if ($this->creditService->getCaptchaCredits($userUid) <= 0) {
            throw new RuntimeException('Không còn lượt giải captcha tự động.');
        }

        $created = $this->twoCaptchaService->createRecaptchaV2Task($payload);

        return DB::transaction(function () use ($run, $userUid, $payload, $created): CaptchaSolveTask {
            /** @var KeywordRankRun $lockedRun */
            $lockedRun = KeywordRankRun::query()->lockForUpdate()->findOrFail($run->id);
            $lockedRun->increment('captcha_solve_attempts');

            return CaptchaSolveTask::query()->create([
                'public_id' => (string) Str::ulid(),
                'user_uid' => $userUid,
                'keyword_rank_run_id' => $lockedRun->id,
                'provider_task_id' => $created['taskId'],
                'status' => 'processing',
                'website_url' => (string) $payload['websiteUrl'],
                'website_key' => (string) $payload['websiteKey'],
                'recaptcha_data_s_value' => $payload['recaptchaDataSValue'] ?? null,
            ]);
        });
    }

    public function pollCaptchaTask(CaptchaSolveTask $task): CaptchaSolveTask
    {
        if ($task->status !== 'processing') {
            return $task;
        }

        $result = $this->twoCaptchaService->getTaskResult((string) $task->provider_task_id);

        if ($result['status'] === 'processing') {
            return $task->fresh();
        }

        return DB::transaction(function () use ($task, $result): CaptchaSolveTask {
            /** @var CaptchaSolveTask $lockedTask */
            $lockedTask = CaptchaSolveTask::query()->lockForUpdate()->findOrFail($task->id);

            if ($lockedTask->status !== 'processing') {
                return $lockedTask;
            }

            if ($result['status'] === 'ready') {
                $this->creditService->consumeCaptchaCredit($lockedTask->user_uid);

                if ($lockedTask->keywordRankRun) {
                    $lockedTask->keywordRankRun->increment('captcha_solve_successes');
                }

                $lockedTask->forceFill([
                    'status' => 'ready',
                    'solution_token' => $result['solutionToken'] ?? null,
                    'cost_usd' => $result['costUsd'] ?? null,
                    'charged' => true,
                ])->save();

                return $lockedTask->fresh();
            }

            $lockedTask->forceFill([
                'status' => 'failed',
                'error_message' => $result['errorMessage'] ?? '2captcha không giải được captcha.',
            ])->save();

            return $lockedTask->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeKeyword(KeywordRankKeyword $keyword): array
    {
        return [
            'id' => $keyword->id,
            'websiteId' => $keyword->website_id,
            'keyword' => $keyword->keyword,
            'latestStatus' => $keyword->latest_status,
            'latestRank' => $keyword->latest_rank,
            'latestPage' => $keyword->latest_page,
            'latestUrl' => $keyword->latest_url,
            'latestTitle' => $keyword->latest_title,
            'latestError' => $keyword->latest_error,
            'latestCheckedAt' => optional($keyword->latest_checked_at)?->toIso8601String(),
            'updatedAt' => optional($keyword->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRun(KeywordRankRun $run, bool $withItems = false): array
    {
        return [
            'publicId' => $run->public_id,
            'websiteId' => $run->website_id,
            'targetDomain' => $run->target_domain,
            'status' => $run->status,
            'captchaEnabled' => (bool) $run->captcha_enabled,
            'totalKeywords' => (int) $run->total_keywords,
            'processedKeywords' => (int) $run->processed_keywords,
            'completedKeywords' => (int) $run->completed_keywords,
            'failedKeywords' => (int) $run->failed_keywords,
            'captchaSolveAttempts' => (int) $run->captcha_solve_attempts,
            'captchaSolveSuccesses' => (int) $run->captcha_solve_successes,
            'lastError' => $run->last_error,
            'startedAt' => optional($run->started_at)?->toIso8601String(),
            'completedAt' => optional($run->completed_at)?->toIso8601String(),
            'items' => $withItems
                ? $run->items->map(fn (KeywordRankRunItem $item): array => $this->serializeRunItem($item))->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRunItem(KeywordRankRunItem $item): array
    {
        return [
            'keywordId' => $item->keyword_rank_keyword_id,
            'keyword' => $item->keyword,
            'status' => $item->status,
            'rank' => $item->rank,
            'page' => $item->page,
            'matchedUrl' => $item->matched_url,
            'title' => $item->title,
            'error' => $item->error_message,
            'checkedAt' => optional($item->checked_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCaptchaTask(CaptchaSolveTask $task): array
    {
        return [
            'id' => $task->public_id,
            'status' => $task->status,
            'solutionToken' => $task->status === 'ready' ? $task->solution_token : null,
            'costUsd' => $task->cost_usd,
            'charged' => (bool) $task->charged,
            'errorMessage' => $task->error_message,
            'captchaCredits' => $this->creditService->getCaptchaCredits($task->user_uid),
        ];
    }

    public function recoverStaleRunsForWebsite(Website $website): void
    {
        $staleBefore = now()->subSeconds(self::STALE_RUN_SECONDS);

        KeywordRankRun::query()
            ->where('website_id', $website->id)
            ->where('status', 'processing')
            ->where('updated_at', '<=', $staleBefore)
            ->orderBy('id')
            ->get()
            ->each(function (KeywordRankRun $run) use ($staleBefore): void {
                DB::transaction(function () use ($run, $staleBefore): void {
                    /** @var KeywordRankRun $lockedRun */
                    $lockedRun = KeywordRankRun::query()->lockForUpdate()->findOrFail($run->id);

                    if ($lockedRun->status !== 'processing' || $lockedRun->updated_at === null || $lockedRun->updated_at->gt($staleBefore)) {
                        return;
                    }

                    KeywordRankRunItem::query()
                        ->where('keyword_rank_run_id', $lockedRun->id)
                        ->where('status', 'queued')
                        ->update([
                            'status' => 'stopped',
                            'error_message' => self::STALE_RUN_ERROR,
                            'checked_at' => now(),
                            'updated_at' => now(),
                        ]);

                    $completedCount = KeywordRankRunItem::query()
                        ->where('keyword_rank_run_id', $lockedRun->id)
                        ->whereIn('status', ['found', 'not_found'])
                        ->count();
                    $failedCount = KeywordRankRunItem::query()
                        ->where('keyword_rank_run_id', $lockedRun->id)
                        ->whereIn('status', ['blocked', 'error', 'stopped'])
                        ->count();

                    $lockedRun->forceFill([
                        'processed_keywords' => (int) $lockedRun->total_keywords,
                        'completed_keywords' => $completedCount,
                        'failed_keywords' => $failedCount,
                        'status' => $completedCount > 0 ? 'partial' : 'stopped',
                        'last_error' => self::STALE_RUN_ERROR,
                        'completed_at' => $lockedRun->completed_at ?: now(),
                    ])->save();
                });
            });
    }

    private function refreshRunCompletion(KeywordRankRun $run): void
    {
        if ((int) $run->processed_keywords < (int) $run->total_keywords) {
            return;
        }

        $run->forceFill([
            'status' => (int) $run->failed_keywords > 0 ? 'partial' : 'completed',
            'completed_at' => $run->completed_at ?: now(),
        ])->save();
    }

    private function normalizeKeyword(string $keyword): string
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return '';
        }

        return (string) preg_replace('/\s+/u', ' ', $keyword);
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['found', 'not_found', 'blocked', 'error', 'stopped'], true)
            ? $status
            : 'error';
    }

    private function domainFromUrl(string $url): string
    {
        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        return preg_replace('/^www\./i', '', strtolower((string) $host)) ?: $url;
    }
}
