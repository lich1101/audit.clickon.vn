<?php

namespace App\Jobs;

use App\Models\AuditRun;
use App\Services\AuditRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAuditRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * 0 = không giới hạn thời gian (Laravel queue).
     */
    public int $timeout = 0;

    public function __construct(
        public readonly int $runId,
    ) {
        $configured = (int) config('services.audit.batch_job_timeout_seconds', 0);

        if ($configured > 0) {
            $this->timeout = $configured;
        }
    }

    public function handle(AuditRunService $auditRunService): void
    {
        $run = AuditRun::query()->with('items')->findOrFail($this->runId);

        if ($auditRunService->isRunCancelled($run)) {
            return;
        }

        $auditRunService->markRunProcessing($run);
        $auditRunService->processBatchUrlOnly($run->fresh('items'));
    }

    public function failed(\Throwable $exception): void
    {
        $run = AuditRun::query()->find($this->runId);

        if ($run) {
            $auditRunService = app(AuditRunService::class);

            if (! $auditRunService->isRunCancelled($run)) {
                $auditRunService->markBatchItemsFailed($run, $exception->getMessage());
                $auditRunService->markRunFailed($run, $exception->getMessage());
            }
        }
    }
}
