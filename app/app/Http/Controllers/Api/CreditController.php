<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditMutationRequest;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use RuntimeException;

class CreditController extends Controller
{
    public function __construct(
        private readonly FirestoreService $firestoreService,
    ) {
    }

    public function add(CreditMutationRequest $request)
    {
        $payload = $request->validated();
        $source = (string) $request->attributes->get('actor_source', 'api');
        $result = $this->firestoreService->mutateCredits(
            $payload['userId'],
            'add',
            (int) $payload['amount'],
            $payload['reason'],
            $source === 'admin' ? 'admin' : 'api',
        );

        return response()->json([
            'message' => 'Credits added successfully.',
            'credits' => $result['credits'],
            'log' => $result['log'],
        ]);
    }

    public function subtract(CreditMutationRequest $request)
    {
        try {
            $payload = $request->validated();
            $source = (string) $request->attributes->get('actor_source', 'api');
            $result = $this->firestoreService->mutateCredits(
                $payload['userId'],
                'subtract',
                (int) $payload['amount'],
                $payload['reason'],
                $source === 'admin' ? 'admin' : 'api',
            );

            return response()->json([
                'message' => 'Credits subtracted successfully.',
                'credits' => $result['credits'],
                'log' => $result['log'],
            ]);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Insufficient credits.') {
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
            'credits' => $this->firestoreService->getBalance($userId),
        ]);
    }
}
