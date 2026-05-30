<?php

namespace Tests\Feature;

use App\Jobs\ProcessAuditRunJob;
use App\Models\AppUser;
use App\Models\AuditRun;
use App\Models\Website;
use App\Services\AuditRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AuditRunPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_user_cannot_start_second_active_run_on_another_website(): void
    {
        Queue::fake();

        $user = $this->makeUser('user-a');
        $websiteA = $this->makeWebsite('website-a', $user->firebase_uid);
        $websiteB = $this->makeWebsite('website-b', $user->firebase_uid);

        $this->makeExistingRun($websiteA, $user->firebase_uid, 'processing');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tài khoản này đang có một audit run khác đang chạy.');

        app(AuditRunService::class)->createRun($user->firebase_uid, $user->email, $this->payloadFor($websiteB));
    }

    public function test_other_users_active_run_does_not_block_current_user(): void
    {
        Queue::fake();

        $userA = $this->makeUser('user-a');
        $userB = $this->makeUser('user-b');
        $websiteA = $this->makeWebsite('website-a', $userA->firebase_uid);
        $websiteB = $this->makeWebsite('website-b', $userB->firebase_uid);

        $this->makeExistingRun($websiteB, $userB->firebase_uid, 'processing');

        $run = app(AuditRunService::class)->createRun($userA->firebase_uid, $userA->email, $this->payloadFor($websiteA));

        $this->assertSame($websiteA->id, $run->website_id);
        $this->assertSame($userA->firebase_uid, $run->user_uid);
        $this->assertSame('queued', $run->status);

        Queue::assertPushed(ProcessAuditRunJob::class, function (ProcessAuditRunJob $job) use ($run): bool {
            return $job->runId === $run->id;
        });
    }

    public function test_same_website_cannot_run_twice_in_same_day_without_admin_grant(): void
    {
        Queue::fake();

        $user = $this->makeUser('user-a');
        $website = $this->makeWebsite('website-a', $user->firebase_uid);

        $this->makeExistingRun($website, $user->firebase_uid, 'completed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Website này đã được audit một lần trong hôm nay.');

        app(AuditRunService::class)->createRun($user->firebase_uid, $user->email, $this->payloadFor($website));
    }

    public function test_same_website_can_run_again_in_same_day_after_admin_grant(): void
    {
        Queue::fake();

        $user = $this->makeUser('user-a');
        $website = $this->makeWebsite('website-a', $user->firebase_uid, [
            'same_day_reaudit_granted_until' => now()->endOfDay(),
            'same_day_reaudit_granted_by' => 'admin-1',
        ]);

        $this->makeExistingRun($website, $user->firebase_uid, 'completed');

        $run = app(AuditRunService::class)->createRun($user->firebase_uid, $user->email, $this->payloadFor($website));

        $this->assertSame($website->id, $run->website_id);
        $this->assertSame($user->firebase_uid, $run->user_uid);
        $this->assertSame('queued', $run->status);
    }

    private function makeUser(string $uid, array $overrides = []): AppUser
    {
        return AppUser::query()->create([
            'firebase_uid' => $uid,
            'email' => "{$uid}@example.com",
            'display_name' => strtoupper($uid),
            'role' => 'user',
            'credits' => 100,
            'balance_usd' => 10,
            ...$overrides,
        ]);
    }

    private function makeWebsite(string $id, string $userUid, array $overrides = []): Website
    {
        return Website::query()->create([
            'id' => $id,
            'user_uid' => $userUid,
            'name' => "Website {$id}",
            'url' => "https://{$id}.example.com",
            ...$overrides,
        ]);
    }

    private function makeExistingRun(Website $website, string $userUid, string $status): AuditRun
    {
        return AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => $website->id,
            'website_name' => $website->name,
            'website_url' => $website->url,
            'user_uid' => $userUid,
            'user_email' => "{$userUid}@example.com",
            'status' => $status,
            'workflow' => AuditRun::WORKFLOW_STANDARD,
            'start_from_step' => 1,
            'target_urls' => ['https://example.com/post-1'],
            'categories' => [],
            'category_contexts' => [],
            'checklist_text' => 'Checklist test',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4.1',
            'step2_ai_provider' => 'openai',
            'step2_ai_model' => 'gpt-4.1',
            'step3_ai_provider' => 'openai',
            'step3_ai_model' => 'gpt-4.1',
            'step2_formatter_provider' => 'gemini',
            'step2_formatter_model' => 'gemini-2.5-flash',
            'step3_formatter_provider' => 'gemini',
            'step3_formatter_model' => 'gemini-2.5-flash',
            'total_urls' => 1,
            'processed_urls' => $status === 'completed' ? 1 : 0,
            'completed_urls' => $status === 'completed' ? 1 : 0,
            'failed_urls' => 0,
            'started_at' => now()->subMinutes(20),
            'completed_at' => $status === 'completed' ? now()->subMinutes(10) : null,
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(10),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Website $website): array
    {
        return [
            'websiteId' => $website->id,
            'websiteName' => $website->name,
            'websiteUrl' => $website->url,
            'targetUrls' => ['https://example.com/post-1'],
            'categories' => [],
            'checklistText' => 'Checklist test',
        ];
    }
}
