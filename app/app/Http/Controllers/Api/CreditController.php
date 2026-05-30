<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditMutationRequest;
use App\Services\CreditService;
use Illuminate\Http\Request;
use RuntimeException;

class CreditController extends Controller
{
    public function __construct(
        private readonly CreditService $creditService,
    ) {
    }

    public function add(CreditMutationRequest $request)
    {
        $payload = $request->validated();
        $source = (string) $request->attributes->get('actor_source', 'api');
        $result = $this->creditService->mutateUsd(
            firebaseUid: $payload['userId'],
            type: 'add',
            amountUsd: (float) $payload['amountUsd'],
            reason: $payload['reason'],
            source: $source === 'admin' ? 'admin' : 'api',
        );

        return response()->json([
            'message' => 'Balance added successfully.',
            'balanceUsd' => $result['balanceUsd'],
            'credits' => $result['credits'],
            'log' => $result['log'],
        ]);
    }

    public function subtract(CreditMutationRequest $request)
    {
        try {
            $payload = $request->validated();
            $source = (string) $request->attributes->get('actor_source', 'api');
            $result = $this->creditService->mutateUsd(
                firebaseUid: $payload['userId'],
                type: 'subtract',
                amountUsd: (float) $payload['amountUsd'],
                reason: $payload['reason'],
                source: $source === 'admin' ? 'admin' : 'api',
            );

            return response()->json([
                'message' => 'Balance subtracted successfully.',
                'balanceUsd' => $result['balanceUsd'],
                'credits' => $result['credits'],
                'log' => $result['log'],
            ]);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Insufficient balance.') {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            throw $exception;
        }
    }

    public function balance(Request $request)
    {
        $userId = (string) $request->query('userId');

        if ($userId === '') {
            return response()->json([
                'message' => 'userId is required.',
            ], 422);
        }

        return response()->json([
            'userId' => $userId,
            'balanceUsd' => $this->creditService->getBalanceUsd($userId),
            'credits' => $this->creditService->getBalance($userId),
        ]);
    }
}
