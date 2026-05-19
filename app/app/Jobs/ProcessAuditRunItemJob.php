<?php

namespace App\Jobs;

use App\Models\AuditRunItem;
use App\Services\AuditRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAuditRunItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    /**
     * Back off AI calls so provider token-per-minute limits can reset.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 180];
    }

    public function __construct(
        public readonly int $itemId,
    ) {
    }

    public function handle(AuditRunService $auditRunService): void
    {
        $item = AuditRunItem::query()->with('run')->findOrFail($this->itemId);

        if ($auditRunService->isRunCancelled($item->run)) {
            return;
        }

        $auditRunService->processItem($item);
    }

    public function failed(\Throwable $exception): void
    {
        $item = AuditRunItem::query()->with('run')->find($this->itemId);

        if ($item) {
            app(AuditRunService::class)->markItemFailed($item, $exception->getMessage(), stopEntireRun: false);
        }
    }
}
