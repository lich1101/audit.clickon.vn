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

    public int $timeout = 1800;

    public function __construct(
        public readonly int $runId,
    ) {
    }

    public function handle(AuditRunService $auditRunService): void
    {
        $run = AuditRun::query()->with('items')->findOrFail($this->runId);

        if ($auditRunService->isRunCancelled($run)) {
            return;
        }

        $auditRunService->markRunProcessing($run);
        $auditRunService->prepareCategoryContexts($run);

        if ($auditRunService->isRunCancelled($run->fresh())) {
            return;
        }

        foreach ($run->fresh('items')->items as $item) {
            if ($auditRunService->isRunCancelled($run->fresh())) {
                break;
            }

            ProcessAuditRunItemJob::dispatch($item->id);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $run = AuditRun::query()->find($this->runId);

        if ($run) {
            app(AuditRunService::class)->markRunFailed($run, $exception->getMessage());
        }
    }
}
