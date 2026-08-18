<?php

namespace App\Jobs;

use App\Services\IndexPublishService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessIndexPublishJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 180;

    public function __construct(
        public readonly string $userUid,
        public readonly int $batchSize = 50,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->userUid;
    }

    public function handle(IndexPublishService $indexPublishService): void
    {
        try {
            $indexPublishService->runPublishBatch($this->userUid, $this->batchSize);
        } catch (\Throwable $exception) {
            Log::warning('Index publish job failed', [
                'userUid' => $this->userUid,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
