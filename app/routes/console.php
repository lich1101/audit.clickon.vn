<?php

use App\Services\AuditConfigurationCheckService;
use App\Services\AuditRunService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('clickon:seed-admin {uid} {email} {--name=}', function (\App\Services\AdminAccountService $adminAccountService, string $uid, string $email) {
    $adminAccountService->seedExistingAdminProfile($uid, $email, $this->option('name'));

    $this->info("Admin user seeded for UID {$uid} in MySQL.");
})->purpose('Seed the first admin profile into MySQL');

Artisan::command('clickon:create-admin {email} {password} {--name=} {--uid=} {--unverified}', function (\App\Services\AdminAccountService $adminAccountService, string $email, string $password) {
    $result = $adminAccountService->createOrUpdateAdmin(
        email: $email,
        password: $password,
        displayName: $this->option('name'),
        uid: $this->option('uid'),
        emailVerified: ! (bool) $this->option('unverified'),
    );

    $status = $result['created'] ? 'created' : 'updated';

    $this->info("Admin account {$status}: {$result['email']} ({$result['uid']})");
})->purpose('Create or update a Firebase Authentication admin account and seed its MySQL admin profile');

Artisan::command('audit:check-config {--json}', function (AuditConfigurationCheckService $auditConfigurationCheckService) {
    $result = $auditConfigurationCheckService->check();

    if ((bool) $this->option('json')) {
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result['ready'] ? SymfonyCommand::SUCCESS : SymfonyCommand::FAILURE;
    }

    $summary = $result['summary'];
    $this->line(sprintf(
        'Audit config ready: %s | mode=%s | ok=%d warning=%d error=%d',
        $result['ready'] ? 'yes' : 'no',
        $result['step3FlowMode'],
        (int) $summary['ok'],
        (int) $summary['warning'],
        (int) $summary['error'],
    ));

    foreach ($result['groups'] as $group) {
        $this->newLine();
        $this->line(sprintf('[%s] %s', strtoupper((string) $group['status']), (string) $group['title']));

        foreach ($group['items'] as $item) {
            $this->line(sprintf(
                '  - [%s] %s: %s',
                strtoupper((string) $item['status']),
                (string) $item['label'],
                (string) $item['message'],
            ));
        }
    }

    return $result['ready'] ? SymfonyCommand::SUCCESS : SymfonyCommand::FAILURE;
})->purpose('Check audit runtime configuration, prompts, API keys, and batch settings');

$resolveAuditRun = function (string $publicId): ?\App\Models\AuditRun {
    $needle = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', trim($publicId)));

    if ($needle === '') {
        return null;
    }

    $baseQuery = \App\Models\AuditRun::query()
        ->with(['items' => fn ($query) => $query->orderBy('position')]);

    if (Str::length($needle) >= 26) {
        return (clone $baseQuery)
            ->where('public_id', $needle)
            ->first();
    }

    $matches = (clone $baseQuery)
        ->where('public_id', 'like', '%'.$needle)
        ->limit(2)
        ->get();

    if ($matches->count() > 1) {
        throw new RuntimeException("Multiple audit runs match suffix [{$needle}]. Use the full public id.");
    }

    return $matches->first();
};

Artisan::command('audit:recover-run {publicId} {--json}', function (AuditRunService $auditRunService, string $publicId) use ($resolveAuditRun) {
    /** @var \App\Models\AuditRun|null $run */
    try {
        $run = $resolveAuditRun($publicId);
    } catch (\RuntimeException $exception) {
        $payload = [
            'ok' => false,
            'message' => $exception->getMessage(),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return SymfonyCommand::FAILURE;
        }

        $this->error($payload['message']);

        return SymfonyCommand::FAILURE;
    }

    if (! $run) {
        $payload = [
            'ok' => false,
            'message' => "Audit run [{$publicId}] not found. Use the full public id or the short suffix shown on the UI.",
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return SymfonyCommand::FAILURE;
        }

        $this->error($payload['message']);

        return SymfonyCommand::FAILURE;
    }

    $before = [
        'status' => $run->status,
        'processed' => (int) $run->processed_urls,
        'completed' => (int) $run->completed_urls,
        'failed' => (int) $run->failed_urls,
    ];

    $auditRunService->watchdogActiveRun($run);

    $run = $run->fresh(['items' => fn ($query) => $query->orderBy('position')]);

    $after = [
        'status' => $run?->status,
        'processed' => (int) ($run?->processed_urls ?? 0),
        'completed' => (int) ($run?->completed_urls ?? 0),
        'failed' => (int) ($run?->failed_urls ?? 0),
    ];

    $payload = [
        'ok' => true,
        'publicId' => (string) $run->public_id,
        'before' => $before,
        'after' => $after,
    ];

    if ((bool) $this->option('json')) {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return SymfonyCommand::SUCCESS;
    }

    $this->line(sprintf(
        'Recovered run %s | before: %s %d/%d/%d | after: %s %d/%d/%d',
        (string) $run->public_id,
        (string) $before['status'],
        (int) $before['processed'],
        (int) $before['completed'],
        (int) $before['failed'],
        (string) $after['status'],
        (int) $after['processed'],
        (int) $after['completed'],
        (int) $after['failed'],
    ));

    return SymfonyCommand::SUCCESS;
})->purpose('Recover one active audit run from stale Gemini Deep Research step-3 polling without touching other runs');

Artisan::command('audit:retry-step3-formatter {publicId} {--json}', function (AuditRunService $auditRunService, string $publicId) use ($resolveAuditRun) {
    /** @var \App\Models\AuditRun|null $run */
    try {
        $run = $resolveAuditRun($publicId);
    } catch (\RuntimeException $exception) {
        $payload = ['ok' => false, 'message' => $exception->getMessage()];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return SymfonyCommand::FAILURE;
        }

        $this->error($payload['message']);

        return SymfonyCommand::FAILURE;
    }

    if (! $run) {
        $payload = [
            'ok' => false,
            'message' => "Audit run [{$publicId}] not found. Use the full public id or the short suffix shown on the UI.",
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return SymfonyCommand::FAILURE;
        }

        $this->error($payload['message']);

        return SymfonyCommand::FAILURE;
    }

    try {
        $result = $auditRunService->retryStep3FormatterFromSavedRaw($run);
    } catch (\Throwable $exception) {
        $payload = ['ok' => false, 'message' => $exception->getMessage()];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return SymfonyCommand::FAILURE;
        }

        $this->error($payload['message']);

        return SymfonyCommand::FAILURE;
    }

    $payload = ['ok' => true, ...$result];

    if ((bool) $this->option('json')) {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return SymfonyCommand::SUCCESS;
    }

    $runSummary = $result['run'];
    $this->line(sprintf(
        'Retry step 3.5 for %s | status=%s processed=%d completed=%d failed=%d',
        (string) $runSummary['publicId'],
        (string) $runSummary['status'],
        (int) $runSummary['processed'],
        (int) $runSummary['completed'],
        (int) $runSummary['failed'],
    ));

    foreach ($result['batches'] as $batch) {
        if ($batch['ok'] ?? false) {
            $this->line(sprintf('  OK %s (%d items)', (string) $batch['stepKey'], (int) ($batch['items'] ?? 0)));
        } else {
            $this->error(sprintf('  FAIL %s: %s', (string) $batch['stepKey'], (string) ($batch['error'] ?? 'unknown error')));
        }
    }

    return SymfonyCommand::SUCCESS;
})->purpose('Re-run step 3.5 JSON formatter from saved step-3 raw output without calling Deep Research again');

Artisan::command('audit:refetch-step1 {publicId} {--all} {--json}', function (AuditRunService $auditRunService, string $publicId) use ($resolveAuditRun) {
    /** @var \App\Models\AuditRun|null $run */
    try {
        $run = $resolveAuditRun($publicId);
    } catch (\RuntimeException $exception) {
        $payload = ['ok' => false, 'message' => $exception->getMessage()];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return SymfonyCommand::FAILURE;
        }

        $this->error($payload['message']);

        return SymfonyCommand::FAILURE;
    }

    if (! $run) {
        $payload = [
            'ok' => false,
            'message' => "Audit run [{$publicId}] not found. Use the full public id or the short suffix shown on the UI.",
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return SymfonyCommand::FAILURE;
        }

        $this->error($payload['message']);

        return SymfonyCommand::FAILURE;
    }

    $thinOnly = ! (bool) $this->option('all');

    try {
        $result = $auditRunService->refetchStep1Content($run, $thinOnly);
    } catch (\Throwable $exception) {
        $payload = ['ok' => false, 'message' => $exception->getMessage()];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return SymfonyCommand::FAILURE;
        }

        $this->error($payload['message']);

        return SymfonyCommand::FAILURE;
    }

    $payload = [
        'ok' => true,
        'publicId' => $run->public_id,
        'thinOnly' => $thinOnly,
        ...$result,
    ];

    if ((bool) $this->option('json')) {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return SymfonyCommand::SUCCESS;
    }

    $this->line(sprintf(
        'Refetch step 1 for %s | processed=%d updated=%d skipped=%d thinOnly=%s',
        (string) $run->public_id,
        (int) $result['processed'],
        (int) $result['updated'],
        (int) $result['skipped'],
        $thinOnly ? 'yes' : 'no',
    ));

    foreach ($result['items'] as $item) {
        if (! ($item['changed'] ?? false)) {
            continue;
        }

        $this->line(sprintf(
            '  ~ %s | %s/%d -> %s/%d',
            (string) $item['targetUrl'],
            (string) $item['beforeSource'],
            (int) $item['beforeExcerptLength'],
            (string) $item['afterSource'],
            (int) $item['afterExcerptLength'],
        ));
    }

    return SymfonyCommand::SUCCESS;
})->purpose('Re-fetch step-1 page content for URLs with html source or thin excerpt');

Artisan::command('audit:recover-stale-runs {--limit=} {--json}', function (AuditRunService $auditRunService) {
    $limitOption = $this->option('limit');
    $limit = is_numeric($limitOption) ? (int) $limitOption : null;
    $summary = $auditRunService->recoverStaleRuns($limit);

    if ((bool) $this->option('json')) {
        $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return SymfonyCommand::SUCCESS;
    }

    $this->line(sprintf(
        'Recovered stale runs | scanned=%d changed=%d recovered=%d failed_marked=%d unchanged=%d',
        (int) $summary['scanned'],
        (int) $summary['changed'],
        (int) $summary['recovered'],
        (int) $summary['failedMarked'],
        (int) $summary['unchanged'],
    ));

    foreach ($summary['runs'] as $run) {
        if (! ($run['changed'] ?? false)) {
            continue;
        }

        $this->line(sprintf(
            '  - %s | %s %d/%d/%d -> %s %d/%d/%d',
            (string) $run['publicId'],
            (string) $run['statusBefore'],
            (int) $run['processedBefore'],
            (int) $run['completedBefore'],
            (int) $run['failedBefore'],
            (string) $run['statusAfter'],
            (int) $run['processedAfter'],
            (int) $run['completedAfter'],
            (int) $run['failedAfter'],
        ));
    }

    return SymfonyCommand::SUCCESS;
})->purpose('Khôi phục run kẹt: bước 1 (fetch) và lưu DB bước 3 từ kết quả đã có — không gọi lại AI bước 2–3.5');

if ((bool) config('services.audit.stale_run_recovery_enabled', true)) {
    Schedule::command('audit:recover-stale-runs --quiet')
        ->everyMinute()
        ->withoutOverlapping();
}
