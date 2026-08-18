<?php

namespace App\Services;

use App\Models\IndexProperty;
use App\Models\IndexUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class IndexPublishService
{
    public function __construct(
        private readonly GoogleIndexingService $googleIndexingService,
        private readonly IndexSettingsService $indexSettingsService,
    ) {
    }

    public function todayPacificDate(): string
    {
        return now('America/Los_Angeles')->toDateString();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function runPendingQueue(?int $batchSize = null): array
    {
        $batchSize ??= (int) config('index.publish_batch_size', 50);
        $this->recoverStaleSending();

        $userUids = IndexProperty::query()
            ->where('enabled', true)
            ->where('is_owned', true)
            ->whereHas('urls', function ($query): void {
                $query->whereIn('status', ['PENDING', 'FAILED', 'SENDING']);
            })
            ->distinct()
            ->pluck('user_uid');

        $results = [];
        foreach ($userUids as $userUid) {
            try {
                $results[] = [
                    'userUid' => $userUid,
                    ...$this->runPublishBatch((string) $userUid, $batchSize),
                ];
            } catch (\Throwable $exception) {
                $results[] = [
                    'userUid' => $userUid,
                    'ok' => false,
                    'sent' => 0,
                    'failed' => 0,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @return array{sent:int,failed:int,skipped:string,properties:list<array<string,mixed>>}
     */
    public function runPublishBatch(string $userUid, int $batchSize = 50): array
    {
        $lock = Cache::lock('index-publish:'.$userUid, 180);

        if (! $lock->get()) {
            return [
                'sent' => 0,
                'failed' => 0,
                'skipped' => 'locked',
                'properties' => [],
            ];
        }

        try {
            $this->recoverStaleSending($userUid);

            $credentialsPath = $this->indexSettingsService->resolveCredentialsPath($userUid);
            if (! $credentialsPath) {
                throw new RuntimeException('Chưa cấu hình Service Account JSON.');
            }

            $dryRun = $this->indexSettingsService->isDryRun($userUid);
            $properties = IndexProperty::query()
                ->where('user_uid', $userUid)
                ->where('enabled', true)
                ->where('is_owned', true)
                ->orderBy('id')
                ->get();

            $summary = ['sent' => 0, 'failed' => 0, 'skipped' => '', 'properties' => []];

            foreach ($properties as $property) {
                $result = $this->publishForProperty($property, $credentialsPath, $dryRun, $batchSize);
                $summary['sent'] += $result['sent'];
                $summary['failed'] += $result['failed'];
                $summary['properties'][] = $result;

                if (($result['quotaRemain'] ?? 1) <= 0) {
                    $summary['skipped'] = 'quota';
                    break;
                }
            }

            DB::table('index_job_runs')->insert([
                'job_name' => 'publish_batch',
                'started_at' => now(),
                'finished_at' => now(),
                'ok' => true,
                'detail_json' => json_encode([
                    'userUid' => $userUid,
                    'dryRun' => $dryRun,
                    'summary' => $summary,
                ]),
            ]);

            return $summary;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{code:string,claimed:int,sent:int,failed:int,quotaRemain:int,skipped?:string}
     */
    private function publishForProperty(IndexProperty $property, string $credentialsPath, bool $dryRun, int $batchSize): array
    {
        $remain = $this->remainingQuota((string) $property->gcp_project_key, 'PUBLISH', (int) $property->daily_publish_quota);
        $take = min($batchSize, $remain);

        if ($take <= 0) {
            return [
                'code' => $property->code,
                'claimed' => 0,
                'sent' => 0,
                'failed' => 0,
                'quotaRemain' => 0,
                'skipped' => 'quota',
            ];
        }

        $claimed = $this->claimUrls((int) $property->id, $take);
        if ($claimed === []) {
            return [
                'code' => $property->code,
                'claimed' => 0,
                'sent' => 0,
                'failed' => 0,
                'quotaRemain' => $remain,
                'skipped' => 'empty',
            ];
        }

        $sent = 0;
        $failed = 0;
        $stop = false;

        foreach ($claimed as $url) {
            if ($stop) {
                $this->releaseClaim($url);
                continue;
            }

            $url->refresh();
            if ($url->status === 'SENT' || $this->hasSuccessfulSendLog((int) $url->id)) {
                $this->markAlreadySent($url);
                continue;
            }

            if ($this->remainingQuota((string) $property->gcp_project_key, 'PUBLISH', (int) $property->daily_publish_quota) <= 0) {
                $this->releaseClaim($url);
                $stop = true;
                continue;
            }

            try {
                if ($dryRun) {
                    $this->markSent($url, 200, ['dry_run' => true]);
                } else {
                    $response = $this->googleIndexingService->publishUrl(
                        $credentialsPath,
                        (string) $url->url_exact,
                        (string) $url->notification_type,
                    );
                    $this->markSent($url, $response['status'], $response['data']);
                }

                $this->consumeQuota((string) $property->gcp_project_key, 'PUBLISH', 1);
                $sent++;
            } catch (\Throwable $exception) {
                $this->markFailed($url, null, $exception->getMessage());
                $failed++;

                if (str_contains($exception->getMessage(), '403') || str_contains($exception->getMessage(), '429')) {
                    $stop = true;
                }
            }

            usleep(400_000);
        }

        return [
            'code' => $property->code,
            'claimed' => count($claimed),
            'sent' => $sent,
            'failed' => $failed,
            'quotaRemain' => $this->remainingQuota((string) $property->gcp_project_key, 'PUBLISH', (int) $property->daily_publish_quota),
        ];
    }

    /**
     * @return list<IndexUrl>
     */
    private function claimUrls(int $propertyId, int $limit): array
    {
        $token = (string) Str::uuid();

        return DB::transaction(function () use ($propertyId, $limit, $token) {
            $candidates = IndexUrl::query()
                ->where('property_id', $propertyId)
                ->where(function ($query): void {
                    $query->where('status', 'PENDING')
                        ->orWhere(function ($nested): void {
                            $nested->where('status', 'FAILED')
                                ->whereColumn('attempt_count', '<', 'max_attempts');
                        });
                })
                ->orderByDesc('priority')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($candidates->isEmpty()) {
                return [];
            }

            IndexUrl::query()
                ->whereIn('id', $candidates->pluck('id'))
                ->whereIn('status', ['PENDING', 'FAILED'])
                ->update([
                    'status' => 'SENDING',
                    'claim_token' => $token,
                    'claimed_at' => now(),
                    'attempt_count' => DB::raw('attempt_count + 1'),
                ]);

            return IndexUrl::query()
                ->whereIn('id', $candidates->pluck('id'))
                ->where('claim_token', $token)
                ->where('status', 'SENDING')
                ->get()
                ->all();
        });
    }

    public function recoverStaleSending(?string $userUid = null): int
    {
        $minutes = max(5, (int) config('index.stale_sending_minutes', 15));
        $query = IndexUrl::query()
            ->where('status', 'SENDING')
            ->where(function ($builder) use ($minutes): void {
                $builder->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<', now()->subMinutes($minutes));
            });

        if ($userUid) {
            $query->whereHas('property', fn ($builder) => $builder->where('user_uid', $userUid));
        }

        $recovered = 0;
        foreach ($query->get() as $url) {
            if ($this->hasSuccessfulSendLog((int) $url->id)) {
                $this->markAlreadySent($url);
            } else {
                $this->releaseClaim($url);
            }
            $recovered++;
        }

        return $recovered;
    }

    private function hasSuccessfulSendLog(int $urlId): bool
    {
        return DB::table('index_send_log')
            ->where('url_id', $urlId)
            ->whereNotNull('http_status')
            ->where(function ($query): void {
                $query->whereNull('error_message')->orWhere('error_message', '');
            })
            ->exists();
    }

    private function markAlreadySent(IndexUrl $url): void
    {
        $log = DB::table('index_send_log')
            ->where('url_id', $url->id)
            ->whereNotNull('http_status')
            ->orderByDesc('id')
            ->first();

        $url->forceFill([
            'status' => 'SENT',
            'sent_at' => $url->sent_at ?? ($log->created_at ?? now()),
            'last_http_status' => $log->http_status ?? $url->last_http_status,
            'last_error' => null,
            'claim_token' => null,
        ])->save();
    }

    private function releaseClaim(IndexUrl $url): void
    {
        if ($url->status === 'SENT') {
            return;
        }

        $url->forceFill([
            'status' => 'PENDING',
            'claim_token' => null,
            'claimed_at' => null,
        ])->save();
    }

    private function remainingQuota(string $gcpProjectKey, string $quotaType, int $dailyLimit): int
    {
        $day = $this->todayPacificDate();
        $used = (int) DB::table('index_quota_ledger')
            ->where('gcp_project_key', $gcpProjectKey)
            ->where('quota_type', $quotaType)
            ->where('day_pt', $day)
            ->value('used_count');

        return max(0, $dailyLimit - $used);
    }

    private function consumeQuota(string $gcpProjectKey, string $quotaType, int $count = 1): void
    {
        $day = $this->todayPacificDate();
        $updated = DB::table('index_quota_ledger')
            ->where('gcp_project_key', $gcpProjectKey)
            ->where('quota_type', $quotaType)
            ->where('day_pt', $day)
            ->increment('used_count', $count);

        if ($updated === 0) {
            DB::table('index_quota_ledger')->insert([
                'gcp_project_key' => $gcpProjectKey,
                'quota_type' => $quotaType,
                'day_pt' => $day,
                'used_count' => $count,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function markSent(IndexUrl $url, int $httpStatus, array $response): void
    {
        $url->forceFill([
            'status' => 'SENT',
            'sent_at' => now(),
            'last_http_status' => $httpStatus,
            'last_error' => null,
            'claim_token' => null,
        ])->save();

        DB::table('index_send_log')->insert([
            'url_id' => $url->id,
            'property_id' => $url->property_id,
            'url_exact' => $url->url_exact,
            'notification_type' => $url->notification_type,
            'http_status' => $httpStatus,
            'response_json' => json_encode($response),
            'created_at' => now(),
        ]);
    }

    private function markFailed(IndexUrl $url, ?int $httpStatus, string $error): void
    {
        $url->forceFill([
            'status' => 'FAILED',
            'last_http_status' => $httpStatus,
            'last_error' => Str::limit($error, 1000, ''),
            'claim_token' => null,
        ])->save();

        DB::table('index_send_log')->insert([
            'url_id' => $url->id,
            'property_id' => $url->property_id,
            'url_exact' => $url->url_exact,
            'notification_type' => $url->notification_type,
            'http_status' => $httpStatus,
            'error_message' => Str::limit($error, 1000, ''),
            'created_at' => now(),
        ]);
    }
}
