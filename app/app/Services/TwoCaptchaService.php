<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwoCaptchaService
{
    /**
     * @return array{taskId:string}
     */
    public function createRecaptchaV2Task(array $payload): array
    {
        $apiKey = trim((string) config('services.two_captcha.api_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('TWO_CAPTCHA_API_KEY chưa được cấu hình.');
        }

        $websiteUrl = trim((string) ($payload['websiteUrl'] ?? ''));
        $websiteKey = trim((string) ($payload['websiteKey'] ?? ''));

        if ($websiteUrl === '' || $websiteKey === '') {
            throw new RuntimeException('Thiếu websiteURL hoặc websiteKey để tạo task 2captcha.');
        }

        $task = [
            'type' => 'RecaptchaV2TaskProxyless',
            'websiteURL' => $websiteUrl,
            'websiteKey' => $websiteKey,
            'isInvisible' => (bool) ($payload['isInvisible'] ?? false),
        ];

        $dataS = trim((string) ($payload['recaptchaDataSValue'] ?? ''));
        if ($dataS !== '') {
            $task['recaptchaDataSValue'] = $dataS;
        }

        $userAgent = trim((string) ($payload['userAgent'] ?? ''));
        if ($userAgent !== '') {
            $task['userAgent'] = $userAgent;
        }

        $cookies = trim((string) ($payload['cookies'] ?? ''));
        if ($cookies !== '') {
            $task['cookies'] = $cookies;
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl().'/createTask', [
                'clientKey' => $apiKey,
                'task' => $task,
            ]);

        if (! $response->ok()) {
            throw new RuntimeException('2captcha createTask HTTP '.$response->status());
        }

        $json = $response->json();

        if (! is_array($json) || (int) ($json['errorId'] ?? 1) !== 0) {
            throw new RuntimeException((string) ($json['errorDescription'] ?? $json['errorCode'] ?? '2captcha createTask failed.'));
        }

        $taskId = (string) ($json['taskId'] ?? '');

        if ($taskId === '') {
            throw new RuntimeException('2captcha createTask không trả taskId.');
        }

        return ['taskId' => $taskId];
    }

    /**
     * @return array{status:string,solutionToken?:string,costUsd?:float,errorMessage?:string}
     */
    public function getTaskResult(string $taskId): array
    {
        $apiKey = trim((string) config('services.two_captcha.api_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('TWO_CAPTCHA_API_KEY chưa được cấu hình.');
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl().'/getTaskResult', [
                'clientKey' => $apiKey,
                'taskId' => is_numeric($taskId) ? (int) $taskId : $taskId,
            ]);

        if (! $response->ok()) {
            throw new RuntimeException('2captcha getTaskResult HTTP '.$response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('2captcha getTaskResult trả dữ liệu không hợp lệ.');
        }

        if ((int) ($json['errorId'] ?? 0) !== 0) {
            return [
                'status' => 'failed',
                'errorMessage' => (string) ($json['errorDescription'] ?? $json['errorCode'] ?? '2captcha task failed.'),
            ];
        }

        if (($json['status'] ?? null) === 'processing') {
            return ['status' => 'processing'];
        }

        $solution = is_array($json['solution'] ?? null) ? $json['solution'] : [];
        $token = (string) ($solution['gRecaptchaResponse'] ?? $solution['token'] ?? '');

        if (($json['status'] ?? null) !== 'ready' || $token === '') {
            return [
                'status' => 'failed',
                'errorMessage' => '2captcha ready nhưng không có token.',
            ];
        }

        return [
            'status' => 'ready',
            'solutionToken' => $token,
            'costUsd' => is_numeric($json['cost'] ?? null) ? (float) $json['cost'] : null,
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.two_captcha.base_url', 'https://api.2captcha.com'), '/');
    }
}
