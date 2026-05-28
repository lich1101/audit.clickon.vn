<?php

use App\Services\AuditConfigurationCheckService;
use App\Services\AuditRunService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
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

Artisan::command('audit:recover-run {publicId} {--json}', function (AuditRunService $auditRunService, string $publicId) {
    /** @var \App\Models\AuditRun|null $run */
    $run = \App\Models\AuditRun::query()
        ->where('public_id', $publicId)
        ->with(['items' => fn ($query) => $query->orderBy('position')])
        ->first();

    if (! $run) {
        $payload = [
            'ok' => false,
            'message' => "Audit run [{$publicId}] not found.",
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
        'publicId' => $publicId,
        'before' => $before,
        'after' => $after,
    ];

    if ((bool) $this->option('json')) {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return SymfonyCommand::SUCCESS;
    }

    $this->line(sprintf(
        'Recovered run %s | before: %s %d/%d/%d | after: %s %d/%d/%d',
        $publicId,
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
})->purpose('Recover stale active audit runs in bulk without waiting for the UI watchdog');

if ((bool) config('services.audit.stale_run_recovery_enabled', true)) {
    Schedule::command('audit:recover-stale-runs --quiet')
        ->everyMinute()
        ->withoutOverlapping();
}
