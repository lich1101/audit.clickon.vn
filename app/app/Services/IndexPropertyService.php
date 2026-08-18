<?php

namespace App\Services;

use App\Jobs\ProcessIndexPublishJob;
use App\Models\IndexProperty;
use App\Models\IndexUrl;
use App\Support\IndexUrlParser;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IndexPropertyService
{
    public function __construct(
        private readonly IndexSettingsService $indexSettingsService,
        private readonly GoogleSearchConsoleService $googleSearchConsoleService,
        private readonly IndexPublishService $indexPublishService,
    ) {
    }

    public function listForUser(string $userUid): array
    {
        $rows = IndexProperty::query()
            ->where('user_uid', $userUid)
            ->orderByDesc('is_owned')
            ->orderByDesc('id')
            ->get();

        return $rows->map(fn (IndexProperty $property) => $this->serializeProperty($property))->all();
    }

    /**
     * @return array{view:string,title:string,total:int,page:int,perPage:int|string,lastPage:int,urls:list<array<string,mixed>>}
     */
    public function listUrlsForUser(string $userUid, string $view, int $page = 1, int|string $perPage = 10): array
    {
        $titles = [
            'indexed' => 'Đã lập chỉ mục (tổng từ khi tạo dự án)',
            'pending' => 'Chưa lập chỉ mục',
            'failed' => 'Lỗi gửi lập chỉ mục',
            'quota_today' => 'Link gửi hôm nay (quota Google)',
        ];

        $page = max(1, $page);
        $all = $perPage === 'all';
        $pageSize = $all ? null : max(1, (int) $perPage);

        if ($view === 'quota_today') {
            $urls = $this->listTodaySendLog($userUid);
            $total = count($urls);
            $lastPage = $all ? 1 : max(1, (int) ceil($total / (int) $pageSize));
            $page = min($page, $lastPage);
            $slice = $all ? $urls : array_slice($urls, ($page - 1) * (int) $pageSize, (int) $pageSize);

            return [
                'view' => $view,
                'title' => $titles[$view],
                'total' => $total,
                'page' => $page,
                'perPage' => $all ? 'all' : (int) $pageSize,
                'lastPage' => $lastPage,
                'urls' => $slice,
            ];
        }

        $query = IndexUrl::query()
            ->with('property')
            ->whereHas('property', fn ($builder) => $builder->where('user_uid', $userUid));

        if ($view === 'indexed') {
            $query->where('status', 'SENT')->orderByDesc('sent_at')->orderByDesc('id');
        } elseif ($view === 'pending') {
            $query->whereIn('status', ['PENDING', 'SENDING'])->orderByDesc('id');
        } else {
            $query->where('status', 'FAILED')->orderByDesc('updated_at')->orderByDesc('id');
        }

        $total = (clone $query)->count();
        $lastPage = $all ? 1 : max(1, (int) ceil($total / (int) $pageSize));
        $page = min($page, $lastPage);

        if (! $all) {
            $query->forPage($page, (int) $pageSize);
        }

        $urls = $query
            ->get()
            ->map(fn (IndexUrl $url) => $this->serializeUrl($url))
            ->all();

        return [
            'view' => $view,
            'title' => $titles[$view] ?? $view,
            'total' => $total,
            'page' => $all ? 1 : $page,
            'perPage' => $all ? 'all' : (int) $pageSize,
            'lastPage' => $lastPage,
            'urls' => $urls,
        ];
    }

    public function getForUser(string $userUid, int $propertyId): ?array
    {
        $property = IndexProperty::query()
            ->where('user_uid', $userUid)
            ->whereKey($propertyId)
            ->first();

        if (! $property) {
            return null;
        }

        $stats = $this->statsForProperty($property->id);
        $urls = IndexUrl::query()
            ->where('property_id', $property->id)
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (IndexUrl $url) => $this->serializeUrl($url))
            ->all();

        return [
            'property' => $this->serializeProperty($property),
            'stats' => $stats,
            'urls' => $urls,
        ];
    }

    public function reportForUser(string $userUid, int $propertyId): ?array
    {
        $property = IndexProperty::query()
            ->where('user_uid', $userUid)
            ->whereKey($propertyId)
            ->first();

        if (! $property) {
            return null;
        }

        $urls = IndexUrl::query()
            ->where('property_id', $property->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (IndexUrl $url) => $this->serializeUrl($url))
            ->all();

        return [
            'property' => $this->serializeProperty($property),
            'stats' => $this->statsForProperty($property->id),
            'urls' => $urls,
        ];
    }

    /**
     * @param  array{site:string,name?:string,confirmOwned?:bool,links?:string}  $input
     */
    public function createProperty(string $userUid, array $input): array
    {
        $site = IndexUrlParser::parseSiteField($input['site']);
        $linkList = IndexUrlParser::parseBulkLinks($input['links'] ?? '');
        $owned = ! empty($input['confirmOwned']);

        return DB::transaction(function () use ($userUid, $site, $linkList, $owned, $input): array {
            $credentialsPath = $this->indexSettingsService->resolveCredentialsPath($userUid);
            if ($credentialsPath && ! $owned) {
                try {
                    $sites = $this->googleSearchConsoleService->listSites($credentialsPath);
                    $owned = $this->googleSearchConsoleService->isOwnedSite($sites, $site['site_host'], $site['site_origin']);
                } catch (\Throwable) {
                    $owned = false;
                }
            }

            $property = IndexProperty::query()
                ->where('user_uid', $userUid)
                ->where('site_host', $site['site_host'])
                ->first();

            $created = false;

            if (! $property) {
                if (! $owned) {
                    return [
                        'ok' => false,
                        'rejected' => true,
                        'message' => "Site {$site['site_origin']} chưa thuộc sở hữu. Tick xác nhận hoặc thêm Service Account vào GSC Owner.",
                    ];
                }

                $code = $this->uniqueCode($userUid, IndexUrlParser::codeFromHost($site['site_host']));
                $property = IndexProperty::query()->create([
                    'user_uid' => $userUid,
                    'code' => $code,
                    'name' => trim((string) ($input['name'] ?? '')) ?: $site['site_host'],
                    'site_url' => $site['site_origin'],
                    'site_origin' => $site['site_origin'],
                    'site_host' => $site['site_host'],
                    'gsc_property' => $site['site_origin'],
                    'gcp_project_key' => config('index.default_gcp_project_key'),
                    'sa_json_path' => config('index.sa_json_path'),
                    'daily_publish_quota' => config('index.daily_publish_quota'),
                    'daily_inspect_quota' => config('index.daily_inspect_quota'),
                    'is_owned' => true,
                    'enabled' => true,
                ]);
                $created = true;
            } elseif ($owned && ! $property->is_owned) {
                $property->forceFill([
                    'is_owned' => true,
                    'site_origin' => $site['site_origin'],
                    'site_url' => $site['site_origin'],
                    'gsc_property' => $site['site_origin'],
                ])->save();
            } elseif (! $property->is_owned) {
                return [
                    'ok' => false,
                    'rejected' => true,
                    'message' => "Site {$site['site_origin']} chưa thuộc sở hữu. Tick xác nhận hoặc thêm Service Account vào GSC Owner.",
                ];
            }

            $import = $this->insertLinks($property, $linkList, $site['site_host']);
            $publish = $this->triggerPublish($userUid, $import['inserted']);

            return [
                'ok' => true,
                'createdProject' => $created,
                'propertyId' => $property->id,
                'propertyCode' => $property->code,
                'siteOrigin' => $site['site_origin'],
                'inserted' => $import['inserted'],
                'duplicates' => $import['duplicates'],
                'invalid' => $import['invalid'],
                'skippedOtherSite' => $import['skippedOtherSite'],
                'publish' => $publish,
                'message' => ($created ? 'Đã tạo dự án. ' : '')."Thêm {$import['inserted']} link, {$import['duplicates']} trùng."
                    .($publish ? " · Gửi lập chỉ mục: {$publish['sent']} thành công, {$publish['failed']} lỗi." : ''),
            ];
        });
    }

    public function previewParse(string $text): array
    {
        $links = IndexUrlParser::parseBulkLinks($text);
        $groups = [];

        foreach ($links as $link) {
            if (! isset($groups[$link['site_host']])) {
                $groups[$link['site_host']] = [
                    'siteOrigin' => $link['site_origin'],
                    'siteHost' => $link['site_host'],
                    'urls' => [],
                ];
            }
            $groups[$link['site_host']]['urls'][] = $link['url_exact'];
        }

        return [
            'count' => count($links),
            'groups' => array_values($groups),
        ];
    }

    public function importForProperty(string $userUid, int $propertyId, string $text): array
    {
        $property = IndexProperty::query()
            ->where('user_uid', $userUid)
            ->whereKey($propertyId)
            ->first();

        if (! $property) {
            return ['ok' => false, 'message' => 'Dự án không tồn tại.'];
        }

        $links = IndexUrlParser::parseBulkLinks($text);
        if ($links === []) {
            return ['ok' => false, 'message' => 'Không có URL hợp lệ.'];
        }

        $import = $this->insertLinks($property, $links, (string) $property->site_host);
        $canPublish = $property->is_owned && $property->enabled;
        $publish = $this->triggerPublish($userUid, $canPublish ? $import['inserted'] : 0);

        return [
            'ok' => true,
            'inserted' => $import['inserted'],
            'duplicates' => $import['duplicates'],
            'skippedOtherSite' => $import['skippedOtherSite'],
            'publish' => $publish,
            'message' => "Thêm {$import['inserted']} link mới, {$import['duplicates']} trùng (link cũ đã lập chỉ mục được giữ nguyên)"
                .($import['skippedOtherSite'] ? ", bỏ {$import['skippedOtherSite']} link khác site" : '')
                .(! $canPublish ? '. Dự án đang mất quyền GSC nên chưa gửi Google' : '')
                .($publish ? " · Gửi lập chỉ mục: {$publish['sent']} thành công, {$publish['failed']} lỗi" : '')
                .'.',
        ];
    }

    public function importGlobal(string $userUid, string $text): array
    {
        $links = IndexUrlParser::parseBulkLinks($text);
        if ($links === []) {
            return ['ok' => false, 'message' => 'Không có URL hợp lệ', 'results' => []];
        }

        $byHost = [];
        foreach ($links as $link) {
            $byHost[$link['site_host']][] = $link;
        }

        $results = [];
        $totalInserted = 0;
        foreach ($byHost as $host => $group) {
            $property = IndexProperty::query()
                ->where('user_uid', $userUid)
                ->where('site_host', $host)
                ->first();

            if (! $property) {
                $results[] = [
                    'ok' => false,
                    'rejected' => true,
                    'siteOrigin' => $group[0]['site_origin'],
                    'siteHost' => $host,
                    'message' => "Site {$group[0]['site_origin']} chưa có trong dự án. Đồng bộ GSC trước.",
                    'linkCount' => count($group),
                ];
                continue;
            }

            $import = $this->insertLinks($property, $group, $host);
            if ($property->is_owned && $property->enabled) {
                $totalInserted += $import['inserted'];
            }
            $results[] = [
                'ok' => true,
                'siteOrigin' => $group[0]['site_origin'],
                'propertyId' => $property->id,
                'propertyCode' => $property->code,
                'inserted' => $import['inserted'],
                'duplicates' => $import['duplicates'],
                'linkCount' => count($group),
                'owned' => (bool) $property->is_owned,
                'message' => $property->is_owned
                    ? "Đã thêm vào «{$property->name}» (giữ nguyên link đã lập chỉ mục)."
                    : "Đã lưu vào «{$property->name}» nhưng chưa gửi vì mất quyền GSC.",
            ];
        }

        $publish = $this->triggerPublish($userUid, $totalInserted);

        return [
            'ok' => collect($results)->contains(fn (array $row) => $row['ok'] ?? false),
            'totalLinks' => count($links),
            'results' => $results,
            'publish' => $publish,
            'message' => collect($results)->contains(fn (array $row) => ! empty($row['rejected']))
                ? 'Một số site chưa sở hữu.'
                : 'Hoàn tất.'
                    .($publish ? " Gửi lập chỉ mục: {$publish['sent']} thành công, {$publish['failed']} lỗi." : ''),
        ];
    }

    /**
     * @return array{sent:int,failed:int,properties:list<array<string,mixed>>}|null
     */
    public function runPublish(string $userUid, int $batchSize = 50): array
    {
        return $this->indexPublishService->runPublishBatch($userUid, $batchSize);
    }

    /**
     * @return array{sent:int,failed:int,properties:list<array<string,mixed>>}|null
     */
    private function triggerPublish(string $userUid, int $inserted): ?array
    {
        if ($inserted <= 0) {
            return null;
        }

        try {
            $batchSize = (int) config('index.publish_batch_size', 50);
            $result = $this->indexPublishService->runPublishBatch($userUid, $batchSize);
            ProcessIndexPublishJob::dispatch($userUid, $batchSize);

            return $result;
        } catch (\Throwable $exception) {
            ProcessIndexPublishJob::dispatch($userUid, (int) config('index.publish_batch_size', 50));

            return [
                'sent' => 0,
                'failed' => 0,
                'queued' => true,
                'properties' => [],
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function quotaStatus(?string $userUid = null): array
    {
        $projectKey = config('index.default_gcp_project_key');
        $publishQuota = config('index.daily_publish_quota');
        $inspectQuota = config('index.daily_inspect_quota');
        $dayPt = now('America/Los_Angeles')->toDateString();
        $dryRun = $userUid ? $this->indexSettingsService->isDryRun($userUid) : (bool) config('index.dry_run');

        $publishUsed = (int) DB::table('index_quota_ledger')
            ->where('gcp_project_key', $projectKey)
            ->where('quota_type', 'PUBLISH')
            ->where('day_pt', $dayPt)
            ->value('used_count');

        $inspectUsed = (int) DB::table('index_quota_ledger')
            ->where('gcp_project_key', $projectKey)
            ->where('quota_type', 'INSPECT')
            ->where('day_pt', $dayPt)
            ->value('used_count');

        return [
            'dayPt' => $dayPt,
            'gcpProjectKey' => $projectKey,
            'dryRun' => $dryRun,
            'publish' => [
                'used' => $publishUsed,
                'limit' => $publishQuota,
                'remaining' => max(0, $publishQuota - $publishUsed),
            ],
            'inspect' => [
                'used' => $inspectUsed,
                'limit' => $inspectQuota,
                'remaining' => max(0, $inspectQuota - $inspectUsed),
            ],
        ];
    }

    public function verifyOwnership(string $userUid, string $siteRaw): array
    {
        $site = IndexUrlParser::parseSiteField($siteRaw);
        $credentialsPath = $this->indexSettingsService->resolveCredentialsPath($userUid);

        if (! $credentialsPath) {
            return [
                'ok' => true,
                'siteOrigin' => $site['site_origin'],
                'siteHost' => $site['site_host'],
                'owned' => false,
                'configured' => false,
                'message' => 'Chưa cấu hình Service Account. Bấm biểu tượng bánh răng để nhập JSON key.',
            ];
        }

        try {
            $sites = $this->googleSearchConsoleService->listSites($credentialsPath);
            $match = $this->googleSearchConsoleService->findMatchingSite($sites, $site['site_host'], $site['site_origin']);
            $owned = $match !== null;

            return [
                'ok' => true,
                'siteOrigin' => $site['site_origin'],
                'siteHost' => $site['site_host'],
                'owned' => $owned,
                'configured' => true,
                'permission' => $match['permissionLevel'] ?? null,
                'gscSiteUrl' => $match['siteUrl'] ?? null,
                'message' => $owned
                    ? 'Site đã được xác minh trong Google Search Console.'
                    : 'Site chưa có trong GSC của Service Account này. Thêm SA làm Owner trong GSC.',
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'siteOrigin' => $site['site_origin'],
                'siteHost' => $site['site_host'],
                'owned' => false,
                'configured' => true,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok:bool,created:int,updated:int,revoked:int,siteCount:int,sites:list<array<string,mixed>>,message:string}
     */
    public function syncFromGsc(string $userUid): array
    {
        $credentialsPath = $this->indexSettingsService->resolveCredentialsPath($userUid);
        if (! $credentialsPath) {
            throw new RuntimeException('Chưa cấu hình Service Account JSON.');
        }

        $sites = $this->googleSearchConsoleService->listSites($credentialsPath);
        $ownedHosts = [];
        $created = 0;
        $updated = 0;
        $results = [];

        foreach ($sites as $site) {
            $permission = $site['permissionLevel'] ?? null;
            if (! $permission || $permission === 'siteUnverifiedUser') {
                continue;
            }

            $siteHost = $this->googleSearchConsoleService->siteHostFromGscEntry($site['siteUrl']);
            if (! $siteHost) {
                continue;
            }

            $ownedHosts[$siteHost] = true;

            $siteOrigin = str_starts_with($site['siteUrl'], 'sc-domain:')
                ? "https://{$siteHost}/"
                : rtrim($site['siteUrl'], '/').'/';

            $existing = IndexProperty::query()
                ->where('user_uid', $userUid)
                ->where('site_host', $siteHost)
                ->first();

            if ($existing) {
                $existing->forceFill([
                    'is_owned' => true,
                    'enabled' => true,
                    'gsc_property' => $site['siteUrl'],
                    'site_origin' => $siteOrigin,
                    'site_url' => $siteOrigin,
                    'permission_level' => $permission,
                ])->save();
                $updated++;
                $results[] = [
                    'siteUrl' => $site['siteUrl'],
                    'status' => 'updated',
                    'propertyId' => $existing->id,
                ];
                continue;
            }

            $property = IndexProperty::query()->create([
                'user_uid' => $userUid,
                'code' => $this->uniqueCode($userUid, IndexUrlParser::codeFromHost($siteHost)),
                'name' => $siteHost,
                'site_url' => $siteOrigin,
                'site_origin' => $siteOrigin,
                'site_host' => $siteHost,
                'gsc_property' => $site['siteUrl'],
                'gcp_project_key' => config('index.default_gcp_project_key'),
                'sa_json_path' => $credentialsPath,
                'daily_publish_quota' => config('index.daily_publish_quota'),
                'daily_inspect_quota' => config('index.daily_inspect_quota'),
                'is_owned' => true,
                'permission_level' => $permission,
                'enabled' => true,
            ]);
            $created++;
            $results[] = [
                'siteUrl' => $site['siteUrl'],
                'status' => 'created',
                'propertyId' => $property->id,
            ];
        }

        $revokedQuery = IndexProperty::query()
            ->where('user_uid', $userUid)
            ->where('is_owned', true);

        if ($ownedHosts !== []) {
            $revokedQuery->whereNotIn('site_host', array_keys($ownedHosts));
        }

        $revokedIds = $revokedQuery->pluck('id');
        $revoked = $revokedIds->count();

        if ($revoked > 0) {
            IndexProperty::query()
                ->whereIn('id', $revokedIds)
                ->update([
                    'is_owned' => false,
                    'enabled' => false,
                    'permission_level' => 'revoked',
                ]);
        }

        $parts = [];
        if ($created > 0) {
            $parts[] = "thêm {$created}";
        }
        if ($updated > 0) {
            $parts[] = "cập nhật {$updated}";
        }
        if ($revoked > 0) {
            $parts[] = "gỡ {$revoked} site đã mất quyền";
        }

        $siteCount = $this->indexSettingsService->persistOwnedSiteCount($userUid);

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'revoked' => $revoked,
            'siteCount' => $siteCount,
            'sites' => $results,
            'message' => $parts === []
                ? 'Đồng bộ GSC: không có thay đổi.'
                : 'Đồng bộ GSC: '.implode(', ', $parts).'.',
        ];
    }

    /**
     * @param  list<array{url_exact:string,site_origin:string,site_host:string}>  $links
     * @return array{inserted:int,duplicates:int,invalid:int,skippedOtherSite:int}
     */
    private function insertLinks(IndexProperty $property, array $links, string $siteHost): array
    {
        $inserted = 0;
        $duplicates = 0;
        $invalid = 0;
        $skippedOtherSite = 0;

        foreach ($links as $link) {
            if ($link['site_host'] !== $siteHost) {
                $skippedOtherSite++;
                continue;
            }

            try {
                $exact = IndexUrlParser::normalizeUrlInput($link['url_exact']);
            } catch (RuntimeException) {
                $invalid++;
                continue;
            }

            $hash = IndexUrlParser::urlHash($exact);
            $existing = IndexUrl::query()
                ->where('property_id', $property->id)
                ->where('url_hash', $hash)
                ->first();

            if ($existing) {
                $duplicates++;
                continue;
            }

            IndexUrl::query()->create([
                'property_id' => $property->id,
                'url_exact' => $exact,
                'url_hash' => $hash,
                'status' => 'PENDING',
                'priority' => 100,
                'notification_type' => 'URL_UPDATED',
            ]);
            $inserted++;
        }

        return compact('inserted', 'duplicates', 'invalid', 'skippedOtherSite');
    }

    private function uniqueCode(string $userUid, string $baseCode): string
    {
        $code = $baseCode;
        $n = 1;

        while (IndexProperty::query()->where('user_uid', $userUid)->where('code', $code)->exists()) {
            $n++;
            $code = substr("{$baseCode}-{$n}", 0, 64);
        }

        return $code;
    }

    private function statsForProperty(int $propertyId): array
    {
        $row = IndexUrl::query()
            ->where('property_id', $propertyId)
            ->selectRaw("
                SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'SENT' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'SENDING' THEN 1 ELSE 0 END) AS sending,
                COUNT(*) AS total
            ")
            ->first();

        return [
            'pending' => (int) ($row->pending ?? 0),
            'sent' => (int) ($row->sent ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'sending' => (int) ($row->sending ?? 0),
            'total' => (int) ($row->total ?? 0),
        ];
    }

    private function serializeProperty(IndexProperty $property): array
    {
        $stats = $this->statsForProperty($property->id);

        return [
            'id' => $property->id,
            'code' => $property->code,
            'name' => $property->name,
            'siteUrl' => $property->site_url,
            'siteOrigin' => $property->site_origin,
            'siteHost' => $property->site_host,
            'gscProperty' => $property->gsc_property,
            'isOwned' => (bool) $property->is_owned,
            'enabled' => (bool) $property->enabled,
            'permissionLevel' => $property->permission_level,
            'dailyPublishQuota' => $property->daily_publish_quota,
            'dailyInspectQuota' => $property->daily_inspect_quota,
            'pendingCount' => $stats['pending'],
            'sentCount' => $stats['sent'],
            'failedCount' => $stats['failed'],
            'sendingCount' => $stats['sending'],
            'totalUrls' => $stats['total'],
            'createdAt' => $property->created_at?->toIso8601String(),
            'updatedAt' => $property->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listTodaySendLog(string $userUid): array
    {
        $start = now('America/Los_Angeles')->startOfDay()->utc();
        $end = now('America/Los_Angeles')->endOfDay()->utc();

        $rows = DB::table('index_send_log as log')
            ->join('index_properties as property', 'property.id', '=', 'log.property_id')
            ->leftJoin('index_urls as url', 'url.id', '=', 'log.url_id')
            ->where('property.user_uid', $userUid)
            ->whereBetween('log.created_at', [$start, $end])
            ->orderByDesc('log.created_at')
            ->limit(2000)
            ->get([
                'log.id',
                'log.url_exact',
                'log.http_status',
                'log.error_message',
                'log.created_at',
                'url.status as url_status',
                'url.sent_at',
                'property.name as site_name',
                'property.site_host',
                'property.site_origin',
            ]);

        return $rows->map(function (object $row): array {
            $failed = filled($row->error_message);
            $status = $failed ? 'FAILED' : (string) ($row->url_status ?: 'SENT');

            return [
                'id' => (int) $row->id,
                'urlExact' => (string) $row->url_exact,
                'status' => $status,
                'priority' => 0,
                'lastError' => $row->error_message,
                'httpStatus' => $row->http_status !== null ? (int) $row->http_status : null,
                'inspectVerdict' => null,
                'sentAt' => $row->sent_at ? (string) $row->sent_at : (string) $row->created_at,
                'createdAt' => (string) $row->created_at,
                'siteName' => (string) $row->site_name,
                'siteHost' => (string) $row->site_host,
                'siteOrigin' => (string) $row->site_origin,
            ];
        })->all();
    }

    private function serializeUrl(IndexUrl $url): array
    {
        $property = $url->relationLoaded('property') ? $url->property : null;

        return [
            'id' => $url->id,
            'urlExact' => $url->url_exact,
            'status' => $url->status,
            'priority' => $url->priority,
            'lastError' => $url->last_error,
            'httpStatus' => $url->last_http_status,
            'inspectVerdict' => $url->inspect_verdict,
            'sentAt' => $url->sent_at?->toIso8601String(),
            'createdAt' => $url->created_at?->toIso8601String(),
            'siteName' => $property?->name,
            'siteHost' => $property?->site_host,
            'siteOrigin' => $property?->site_origin,
        ];
    }
}
