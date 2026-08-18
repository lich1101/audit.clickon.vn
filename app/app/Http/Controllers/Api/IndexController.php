<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IndexPropertyService;
use App\Services\IndexSettingsService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IndexController extends Controller
{
    public function __construct(
        private readonly IndexPropertyService $indexPropertyService,
        private readonly IndexSettingsService $indexSettingsService,
    ) {
    }

    public function index(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');

        return response()->json([
            'data' => $this->indexPropertyService->listForUser($uid),
            'quota' => $this->indexPropertyService->quotaStatus($uid),
        ]);
    }

    public function show(Request $request, int $propertyId)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $data = $this->indexPropertyService->getForUser($uid, $propertyId);

        if (! $data) {
            throw new NotFoundHttpException('Không tìm thấy dự án lập chỉ mục.');
        }

        return response()->json(['data' => $data]);
    }

    public function urls(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validate([
            'view' => ['required', 'string', 'in:indexed,pending,failed,quota_today'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable'],
        ]);

        $perPageRaw = $validated['perPage'] ?? 10;
        $perPage = is_string($perPageRaw) && strtolower($perPageRaw) === 'all'
            ? 'all'
            : (in_array((int) $perPageRaw, [10, 20, 50], true) ? (int) $perPageRaw : 10);

        return response()->json([
            'data' => $this->indexPropertyService->listUrlsForUser(
                $uid,
                $validated['view'],
                (int) ($validated['page'] ?? 1),
                $perPage,
            ),
        ]);
    }

    public function report(Request $request, int $propertyId)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $data = $this->indexPropertyService->reportForUser($uid, $propertyId);

        if (! $data) {
            throw new NotFoundHttpException('Không tìm thấy dự án lập chỉ mục.');
        }

        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validate([
            'site' => ['required', 'string', 'max:2048'],
            'name' => ['nullable', 'string', 'max:255'],
            'links' => ['nullable', 'string'],
            'confirmOwned' => ['nullable', 'boolean'],
        ]);

        $result = $this->indexPropertyService->createProperty($uid, $validated);

        return response()->json($result, ($result['ok'] ?? false) ? 201 : 422);
    }

    public function preview(Request $request)
    {
        $validated = $request->validate([
            'text' => ['required', 'string'],
        ]);

        return response()->json([
            'data' => $this->indexPropertyService->previewParse($validated['text']),
        ]);
    }

    public function import(Request $request, int $propertyId)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validate([
            'text' => ['required', 'string'],
        ]);

        $result = $this->indexPropertyService->importForProperty($uid, $propertyId, $validated['text']);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function importGlobal(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validate([
            'text' => ['required', 'string'],
        ]);

        $result = $this->indexPropertyService->importGlobal($uid, $validated['text']);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function verifyOwnership(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validate([
            'site' => ['required', 'string', 'max:2048'],
        ]);

        return response()->json([
            'data' => $this->indexPropertyService->verifyOwnership($uid, $validated['site']),
        ]);
    }

    public function settings(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');

        return response()->json([
            'data' => $this->indexSettingsService->getSettings($uid),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validate([
            'serviceAccountJson' => ['nullable', 'string'],
            'dryRun' => ['sometimes', 'boolean'],
        ]);

        try {
            $dryRun = $request->has('dryRun') ? $request->boolean('dryRun') : null;

            if (! empty($validated['serviceAccountJson'])) {
                $result = $this->indexSettingsService->saveCredentials(
                    $uid,
                    $validated['serviceAccountJson'],
                    $dryRun,
                );

                return response()->json($result);
            }

            if ($request->has('dryRun')) {
                return response()->json(
                    $this->indexSettingsService->updatePreferences($uid, $dryRun)
                );
            }

            return response()->json([
                'ok' => false,
                'message' => 'Không có dữ liệu cần lưu.',
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function testSettings(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');

        try {
            return response()->json([
                'data' => $this->indexSettingsService->testConnection($uid),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function syncGsc(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');

        try {
            $result = $this->indexPropertyService->syncFromGsc($uid);

            return response()->json($result);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function publish(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $validated = $request->validate([
            'batchSize' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        try {
            $result = $this->indexPropertyService->runPublish($uid, (int) ($validated['batchSize'] ?? 50));

            return response()->json([
                'ok' => true,
                'data' => $result,
                'message' => "Gửi lập chỉ mục: {$result['sent']} thành công, {$result['failed']} lỗi.",
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function quota(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');

        return response()->json([
            'data' => $this->indexPropertyService->quotaStatus($uid),
        ]);
    }
}
