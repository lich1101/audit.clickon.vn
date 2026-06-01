<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KeywordRankProxyService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
        }

        return response()->json([
            'message' => sprintf(
                'Đã cập nhật %d proxy (HTTP %d, SOCKS5 %d). Run dùng %d proxy xoay.',
                $data['totalCount'],
                $data['httpCount'],
                $data['socks5Count'],
                $data['runProxyCount'],
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
