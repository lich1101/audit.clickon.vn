<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KeywordRankProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class KeywordRankProxyController extends Controller
{
    public function __construct(
        private readonly KeywordRankProxyService $keywordRankProxyService,
    ) {
    }

    /**
     * User Run: áp dụng cấu hình proxy do admin quản lý (không nhận proxy từ client).
     */
    public function resolveForRun(Request $request)
    {
        try {
            $data = $this->keywordRankProxyService->resolveForRun();
        } catch (RuntimeException $exception) {
            throw new ServiceUnavailableHttpException(null, $exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('Keyword rank proxy resolve failed.', [
                'error' => $exception->getMessage(),
            ]);

            throw new HttpException(503, 'Không thể chuẩn bị proxy. Thử lại sau vài phút.');
        }

        if (! $data['proxyEnabled']) {
            return response()->json([
                'message' => 'Proxy đang tắt (cấu hình admin). Run sẽ dùng IP trình duyệt của bạn.',
                'data' => $data,
            ]);
        }

        $cacheNote = ! empty($data['usedCache']) ? ' (một phần dùng pool GitHub đã lưu)' : '';

        return response()->json([
            'message' => sprintf(
                'Admin đã bật proxy: %d endpoint cho lần Run (thủ công %d · pool %d).%s',
                $data['runProxyCount'],
                $data['manualCount'],
                $data['totalCount'] - $data['manualCount'],
                $cacheNote,
            ),
            'data' => $data,
        ]);
    }

    public function showAdmin(Request $request)
    {
        if ((string) $request->attributes->get('firebase_role') !== 'admin') {
            throw new AccessDeniedHttpException('Admin only.');
        }

        $config = $this->keywordRankProxyService->getAdminConfig();
        $pool = $this->keywordRankProxyService->getPool();

        return response()->json([
            'data' => [
                'config' => [
                    ...$config,
                    'manualProxiesText' => implode("\n", $config['manualProxies']),
                ],
                'pool' => [
                    ...$pool,
                    'sources' => [
                        'http' => KeywordRankProxyService::HTTP_SOURCE_URL,
                        'socks5' => KeywordRankProxyService::SOCKS5_SOURCE_URL,
                    ],
                ],
            ],
        ]);
    }

    public function updateAdmin(Request $request)
    {
        if ((string) $request->attributes->get('firebase_role') !== 'admin') {
            throw new AccessDeniedHttpException('Admin only.');
        }

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'useGithubHttp' => ['nullable', 'boolean'],
            'useGithubSocks5' => ['nullable', 'boolean'],
            'refreshGithubOnRun' => ['nullable', 'boolean'],
            'runSampleSize' => ['nullable', 'integer', 'min:10', 'max:300'],
            'manualProxiesText' => ['nullable', 'string', 'max:65536'],
        ]);

        $config = $this->keywordRankProxyService->updateAdminConfig($validated);

        return response()->json([
            'message' => 'Đã lưu cấu hình proxy keyword rank.',
            'data' => [
                ...$config,
                'manualProxiesText' => implode("\n", $config['manualProxies']),
            ],
        ]);
    }

    public function refreshGithubAdmin(Request $request)
    {
        if ((string) $request->attributes->get('firebase_role') !== 'admin') {
            throw new AccessDeniedHttpException('Admin only.');
        }

        $config = $this->keywordRankProxyService->getAdminConfig();

        try {
            $pool = $this->keywordRankProxyService->refreshGithubPool(
                $config['useGithubHttp'],
                $config['useGithubSocks5'],
            );
        } catch (RuntimeException $exception) {
            throw new ServiceUnavailableHttpException(null, $exception->getMessage());
        }

        return response()->json([
            'message' => sprintf(
                'Đã cào GitHub: lưu %d proxy (HTTP %d · SOCKS5 %d).',
                $pool['totalCount'],
                $pool['httpCount'],
                $pool['socks5Count'],
            ),
            'data' => $pool,
        ]);
    }
}
