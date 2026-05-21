<?php

namespace App\Jobs;

use App\Models\AuditRun;
use App\Services\AuditRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAuditRunStep3BatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * 0 = không giới hạn thời gian (Laravel queue).
     */
    public int $timeout = 0;

    /**
     * @param  array<int, int>  $itemIds
     */
    public function __construct(
        public readonly int $runId,
        public readonly array $itemIds,
    ) {
        $configured = (int) config('services.audit.batch_job_timeout_seconds', 0);

        if ($configured > 0) {
            $this->timeout = $configured;
        }
    }

    public function handle(AuditRunService $auditRunService): void
    {
        $run = AuditRun::query()->findOrFail($this->runId);

        if ($auditRunService->isRunCancelled($run)) {
            return;
        }

        $auditRunService->processStep3Batch($run, $this->itemIds);
    }

    public function failed(\Throwable $exception): void
    {
        $run = AuditRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        $auditRunService = app(AuditRunService::class);

        if ($auditRunService->isRunCancelled($run)) {
            return;
        }

        $auditRunService->markBatchItemIdsFailed($run, $this->itemIds, $exception->getMessage());
        $auditRunService->dispatchStep3Batches($run);
    }
}
