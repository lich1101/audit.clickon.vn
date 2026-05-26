<?php

namespace App\Jobs;

use App\Models\AuditRunItem;
use App\Services\AuditRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAuditDeepResearchItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * 0 = không giới hạn thời gian (Laravel queue).
     */
    public int $timeout = 0;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 180];
    }

    public function __construct(
        public readonly int $itemId,
    ) {
        $configured = (int) config('services.audit.batch_job_timeout_seconds', 0);

        if ($configured > 0) {
            $this->timeout = $configured;
        }
    }

    public function handle(AuditRunService $auditRunService): void
    {
        $item = AuditRunItem::query()->with('run')->findOrFail($this->itemId);

        if ($auditRunService->isRunCancelled($item->run)) {
            return;
        }

        $auditRunService->processDeepResearchItem($item);
    }

    public function failed(\Throwable $exception): void
    {
        $item = AuditRunItem::query()->with('run')->find($this->itemId);

        if ($item) {
            app(AuditRunService::class)->markItemFailed($item, $exception->getMessage(), stopEntireRun: false);
        }
    }
}
