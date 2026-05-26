<?php

namespace App\Services;

use App\Models\AuditRun;
use App\Models\AuditRunItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AuditRunCallbackService
{
    /**
     * @param  array<string, mixed>|null  $rawResearchData
     * @param  array<string, mixed>|null  $modelUsed
     * @param  array<string, mixed>|null  $cost
     */
    public function notifySuccess(
        AuditRun $run,
        AuditRunItem $item,
        ?array $rawResearchData,
        ?array $modelUsed,
        ?array $cost,
        ?string $warning = null,
    ): void {
        $this->notify($run, [
            'status' => 'completed',
            'api_status' => 'completed',
            'workflow' => $run->workflow ?: AuditRun::WORKFLOW_STANDARD,
            'run_public_id' => $run->public_id,
            'item_public_id' => $item->public_id,
            'target_url' => $item->target_url,
            'audit_score' => $item->audit_score,
            'audit_findings' => $item->audit_findings ? preg_split('/\r\n|\r|\n/', $item->audit_findings) : [],
            'audit_recommendations' => $item->audit_recommendations ? preg_split('/\r\n|\r|\n/', $item->audit_recommendations) : [],
            'content_revision_direction' => $item->content_revision_direction,
            'raw_research_data' => $rawResearchData,
            'model_used' => $modelUsed,
            'cost' => $cost,
            'warning' => $warning,
        ]);
    }

    public function notifyError(AuditRun $run, AuditRunItem $item, string $message): void
    {
        $this->notify($run, [
            'status' => 'hold',
            'api_status' => 'error',
            'workflow' => $run->workflow ?: AuditRun::WORKFLOW_STANDARD,
            'run_public_id' => $run->public_id,
            'item_public_id' => $item->public_id,
            'target_url' => $item->target_url,
            'error_message' => $message,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notify(AuditRun $run, array $payload): void
    {
        $callbackUrl = trim((string) ($run->callback_url ?? ''));

        if ($callbackUrl === '') {
            return;
        }

        $attempts = max(1, (int) config('services.audit.callback_retry_attempts', 3));
        $sleepMs = max(0, (int) config('services.audit.callback_retry_sleep_ms', 2000));
        $timeout = max(5, (int) config('services.audit.callback_timeout_seconds', 30));
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->timeout($timeout)
                    ->withHeaders([
                        'X-Clickon-Audit-Run' => $run->public_id,
                        'X-Clickon-Audit-Workflow' => (string) ($run->workflow ?: AuditRun::WORKFLOW_STANDARD),
                    ])
                    ->post($callbackUrl, $payload);

                $this->throwIfCallbackFailed($response);

                return;
            } catch (ConnectionException|RequestException|RuntimeException $exception) {
                $lastException = $exception;

                if ($attempt < $attempts) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        Log::warning('audit callback failed', [
            'run_public_id' => $run->public_id,
            'workflow' => $run->workflow ?: AuditRun::WORKFLOW_STANDARD,
            'callback_url' => $callbackUrl,
            'error' => $lastException?->getMessage(),
        ]);

        throw new RuntimeException('Callback failed: '.trim((string) $lastException?->getMessage()));
    }

    private function throwIfCallbackFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        try {
            $response->throw();
        } catch (RequestException $exception) {
            $message = trim($exception->response->body());
            $message = $message !== '' ? mb_substr($message, 0, 500) : 'Unknown callback error.';

            throw new RuntimeException(
                sprintf('Callback HTTP %d: %s', $exception->response->status(), $message),
                previous: $exception,
            );
        }
    }
}
