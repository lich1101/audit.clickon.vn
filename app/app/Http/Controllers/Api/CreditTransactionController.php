<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CreditTransactionController extends Controller
{
    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    public function index(Request $request)
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role', 'user');
        $targetUid = (string) $request->query('userId', $uid);
        $limit = min(200, max(1, (int) $request->query('limit', 50)));

        if ($role !== 'admin' && $targetUid !== $uid) {
            throw new AccessDeniedHttpException('You cannot read credit logs for another user.');
        }

        $rows = CreditTransaction::query()
            ->when($targetUid !== '', fn ($query) => $query->where('user_uid', $targetUid))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (CreditTransaction $row): array => $this->creditService->serializeTransaction($row))
            ->values();

        return response()->json(['data' => $rows]);
    }
}
