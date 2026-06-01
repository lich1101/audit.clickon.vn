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

    public function refreshForRun(Request $request)
    {
        try {
            $data = $this->keywordRankProxyService->refreshPool();
        } catch (RuntimeException $exception) {
            throw new ServiceUnavailableHttpException(null, $exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('Keyword rank proxy refresh failed.', [
                'error' => $exception->getMessage(),
            ]);

            throw new HttpException(503, 'Không thể tải proxy. Thử lại sau vài phút.');
        }

        $cacheNote = ! empty($data['usedCache']) ? ' (dùng pool đã lưu — GitHub tạm không phản hồi)' : '';

        return response()->json([
            'message' => sprintf(
                'Đã cập nhật %d proxy (HTTP %d, SOCKS5 %d). Run dùng %d proxy xoay.%s',
                $data['totalCount'],
                $data['httpCount'],
                $data['socks5Count'],
                $data['runProxyCount'],
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

        $pool = $this->keywordRankProxyService->getPool();

        return response()->json([
            'data' => [
                ...$pool,
                'sources' => [
                    'http' => KeywordRankProxyService::HTTP_SOURCE_URL,
                    'socks5' => KeywordRankProxyService::SOCKS5_SOURCE_URL,
                ],
            ],
        ]);
    }
}
