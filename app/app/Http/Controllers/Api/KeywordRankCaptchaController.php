<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptchaSolveTask;
use App\Models\KeywordRankRun;
use App\Services\KeywordRankService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class KeywordRankCaptchaController extends Controller
{
    public function __construct(
        private readonly KeywordRankService $keywordRankService,
    ) {
    }

    public function createTask(Request $request)
    {
        $validated = $request->validate([
            'runPublicId' => ['required', 'string', 'max:32'],
            'websiteUrl' => ['required', 'url', 'max:4096'],
            'websiteKey' => ['required', 'string', 'max:255'],
            'recaptchaDataSValue' => ['nullable', 'string', 'max:4096'],
            'isInvisible' => ['nullable', 'boolean'],
            'userAgent' => ['nullable', 'string', 'max:1024'],
            'cookies' => ['nullable', 'string', 'max:8192'],
        ]);

        $run = $this->authorizedRun($request, $validated['runPublicId']);

        try {
            $task = $this->keywordRankService->createCaptchaTask(
                run: $run,
                userUid: (string) $request->attributes->get('firebase_uid'),
                payload: $validated,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->keywordRankService->serializeCaptchaTask($task),
        ], 201);
    }

    public function poll(Request $request, string $publicId)
    {
        $task = CaptchaSolveTask::query()->where('public_id', $publicId)->first();

        if (! $task) {
            throw new NotFoundHttpException('Captcha task not found.');
        }

        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role');

        if ($role !== 'admin' && $task->user_uid !== $uid) {
            throw new AccessDeniedHttpException('You do not have access to this captcha task.');
        }

        try {
            $task = $this->keywordRankService->pollCaptchaTask($task);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->keywordRankService->serializeCaptchaTask($task),
        ]);
    }

    private function authorizedRun(Request $request, string $publicId): KeywordRankRun
    {
        $run = KeywordRankRun::query()->where('public_id', $publicId)->first();

        if (! $run) {
            throw new NotFoundHttpException('Keyword rank run not found.');
        }

        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role');

        if ($role !== 'admin' && $run->user_uid !== $uid) {
            throw new AccessDeniedHttpException('You do not have access to this run.');
        }

        return $run;
    }
}
