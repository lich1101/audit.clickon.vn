<?php

namespace App\Services;

use App\Models\AiUsageEvent;
use Illuminate\Support\Facades\DB;

class AiUsageBillingReconciliationService
{
    private const USD_EPSILON = 0.000001;

    public function __construct(
        private readonly TokenBillingService $tokenBillingService,
        private readonly CreditService $creditService,
    ) {
    }

    /**
     * @param  array{
     *   status?:string,
     *   provider?:?string,
     *   userUid?:?string,
     *   runPublicId?:?string,
     *   limit?:int
     * }  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters = []): array
    {
        $status = $this->normalizeStatus((string) ($filters['status'] ?? 'undercharged'));
        $rows = $this->baseQuery($filters)->get();

        $summary = [
            'scannedEventCount' => 0,
            'affectedEventCount' => 0,
            'underchargedEventCount' => 0,
            'overchargedEventCount' => 0,
            'alignedEventCount' => 0,
            'affectedRunCount' => 0,
            'chargedUsd' => 0.0,
            'expectedUsd' => 0.0,
            'usdDelta' => 0.0,
            'chargedCredits' => 0,
            'expectedCredits' => 0,
            'creditDelta' => 0,
        ];

        $runs = [];
        $events = [];

        foreach ($rows as $row) {
            $event = $this->buildEventEntry($row);
            $summary['scannedEventCount']++;

            if ($event['status'] === 'undercharged') {
                $summary['underchargedEventCount']++;
            } elseif ($event['status'] === 'overcharged') {
                $summary['overchargedEventCount']++;
            } else {
                $summary['alignedEventCount']++;
            }

            if ($status !== 'all' && $event['status'] !== $status) {
                continue;
            }

            $summary['chargedUsd'] = round((float) $summary['chargedUsd'] + (float) $event['chargedUsd'], 6);
            $summary['expectedUsd'] = round((float) $summary['expectedUsd'] + (float) $event['expectedUsd'], 6);
            $summary['usdDelta'] = round((float) $summary['usdDelta'] + (float) $event['usdDelta'], 6);
            $summary['chargedCredits'] += (int) $event['chargedCredits'];
            $summary['expectedCredits'] += (int) $event['expectedCredits'];
            $summary['creditDelta'] += (int) $event['creditDelta'];

            if ($event['status'] !== 'aligned') {
                $summary['affectedEventCount']++;
            }

            $events[] = $event;
            $runKey = (string) $event['runPublicId'];

            if (! isset($runs[$runKey])) {
                $runs[$runKey] = [
                    'runPublicId' => $runKey,
                    'websiteName' => (string) ($event['websiteName'] ?? ''),
                    'websiteUrl' => (string) ($event['websiteUrl'] ?? ''),
                    'userUid' => (string) ($event['userUid'] ?? ''),
                    'workflow' => (string) ($event['workflow'] ?? ''),
                    'pipelineMode' => (string) ($event['pipelineMode'] ?? ''),
                    'eventCount' => 0,
                    'affectedEventCount' => 0,
                    'chargedUsd' => 0.0,
                    'expectedUsd' => 0.0,
                    'usdDelta' => 0.0,
                    'chargedCredits' => 0,
                    'expectedCredits' => 0,
                    'creditDelta' => 0,
                    'latestEventAt' => (string) ($event['createdAt'] ?? ''),
                    'status' => 'aligned',
                ];
            }

            $runs[$runKey]['eventCount']++;
            $runs[$runKey]['chargedUsd'] = round((float) $runs[$runKey]['chargedUsd'] + (float) $event['chargedUsd'], 6);
            $runs[$runKey]['expectedUsd'] = round((float) $runs[$runKey]['expectedUsd'] + (float) $event['expectedUsd'], 6);
            $runs[$runKey]['usdDelta'] = round((float) $runs[$runKey]['usdDelta'] + (float) $event['usdDelta'], 6);
            $runs[$runKey]['chargedCredits'] += (int) $event['chargedCredits'];
            $runs[$runKey]['expectedCredits'] += (int) $event['expectedCredits'];
            $runs[$runKey]['creditDelta'] += (int) $event['creditDelta'];
            $runs[$runKey]['latestEventAt'] = max((string) $runs[$runKey]['latestEventAt'], (string) ($event['createdAt'] ?? ''));

            if ($event['status'] !== 'aligned') {
                $runs[$runKey]['affectedEventCount']++;
            }
        }

        $runList = array_values(array_map(function (array $run): array {
            $run['status'] = $this->classifyDelta((float) $run['usdDelta'], (int) $run['creditDelta']);

            return $run;
        }, $runs));

        usort($events, fn (array $left, array $right): int => $this->compareByDeltaMagnitude($left, $right));
        usort($runList, fn (array $left, array $right): int => $this->compareByDeltaMagnitude($left, $right));

        $summary['affectedRunCount'] = count(array_filter($runList, fn (array $run): bool => $run['status'] !== 'aligned'));

        return [
            'filters' => [
                'status' => $status,
                'provider' => $filters['provider'] ?? null,
                'userUid' => $filters['userUid'] ?? null,
                'runPublicId' => $filters['runPublicId'] ?? null,
                'limit' => (int) ($filters['limit'] ?? 500),
            ],
            'summary' => $summary,
            'runs' => $runList,
            'events' => $events,
        ];
    }

    /**
     * @param  array{
     *   provider?:?string,
     *   userUid?:?string,
     *   runPublicId?:?string,
     *   limit?:int,
     *   runPublicIds?:list<string>|null
     * }  $filters
     * @return array<string, mixed>
     */
    public function backfill(array $filters = []): array
    {
        $report = $this->report([
            ...$filters,
            'status' => 'undercharged',
        ]);

        $selectedRunIds = collect($filters['runPublicIds'] ?? [])
            ->filter(fn ($value): bool => trim((string) $value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->values();

        $events = collect($report['events'] ?? [])
            ->filter(fn (array $event): bool => $event['status'] === 'undercharged')
            ->when($selectedRunIds->isNotEmpty(), fn ($collection) => $collection->whereIn('runPublicId', $selectedRunIds->all()))
            ->values();

        $results = [];
        $appliedUsdDelta = 0.0;
        $appliedCreditDelta = 0;
        $appliedCount = 0;

        foreach ($events as $event) {
            $eventId = (int) ($event['eventId'] ?? 0);

            if ($eventId <= 0) {
                continue;
            }

            try {
                $result = $this->backfillEvent($eventId);
            } catch (\Throwable $exception) {
                $result = [
                    'eventId' => $eventId,
                    'runPublicId' => (string) ($event['runPublicId'] ?? ''),
                    'itemPublicId' => (string) ($event['itemPublicId'] ?? ''),
                    'applied' => false,
                    'error' => $exception->getMessage(),
                ];
            }

            if (($result['applied'] ?? false) === true) {
                $appliedCount++;
                $appliedUsdDelta = round($appliedUsdDelta + (float) ($result['usdDelta'] ?? 0), 6);
                $appliedCreditDelta += (int) ($result['creditDelta'] ?? 0);
            }

            $results[] = $result;
        }

        return [
            'summary' => [
                'candidateEventCount' => $events->count(),
                'appliedEventCount' => $appliedCount,
                'appliedUsdDelta' => $appliedUsdDelta,
                'appliedCreditDelta' => $appliedCreditDelta,
                'failedEventCount' => count(array_filter($results, fn (array $row): bool => ($row['applied'] ?? false) !== true)),
            ],
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(array $filters)
    {
        $limit = min(5000, max(1, (int) ($filters['limit'] ?? 500)));
        $provider = trim((string) ($filters['provider'] ?? ''));
        $userUid = trim((string) ($filters['userUid'] ?? ''));
        $runPublicId = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', (string) ($filters['runPublicId'] ?? '')));

        return DB::table('ai_usage_events')
            ->join('audit_run_items', 'audit_run_items.id', '=', 'ai_usage_events.audit_run_item_id')
            ->join('audit_runs', 'audit_runs.id', '=', 'audit_run_items.audit_run_id')
            ->leftJoin('websites', 'websites.id', '=', 'audit_runs.website_id')
            ->when($provider !== '', fn ($query) => $query->where('ai_usage_events.provider', $provider))
            ->when($userUid !== '', fn ($query) => $query->where('audit_runs.user_uid', $userUid))
            ->when($runPublicId !== '', function ($query) use ($runPublicId) {
                if (strlen($runPublicId) >= 26) {
                    $query->where('audit_runs.public_id', $runPublicId);

                    return;
                }

                $query->where('audit_runs.public_id', 'like', '%'.$runPublicId);
            })
            ->orderByDesc('ai_usage_events.id')
            ->limit($limit)
            ->select([
                'ai_usage_events.id as event_id',
                'ai_usage_events.audit_run_item_id',
                'ai_usage_events.step',
                'ai_usage_events.provider',
                'ai_usage_events.model',
                'ai_usage_events.input_tokens',
                'ai_usage_events.output_tokens',
                'ai_usage_events.total_tokens',
                'ai_usage_events.citation_tokens',
                'ai_usage_events.reasoning_tokens',
                'ai_usage_events.search_queries',
                'ai_usage_events.provider_reported_cost_usd',
                'ai_usage_events.credits_charged',
                'ai_usage_events.usd_charged',
                'ai_usage_events.created_at as event_created_at',
                'audit_run_items.public_id as item_public_id',
                'audit_run_items.position',
                'audit_run_items.target_url',
                'audit_runs.public_id as run_public_id',
                'audit_runs.user_uid',
                'audit_runs.website_name',
                'audit_runs.website_url',
                'audit_runs.workflow',
                'audit_runs.pipeline_mode',
                'websites.name as website_entity_name',
            ]);
    }

    /**
     * @param  object|array<string,mixed>  $row
     * @return array<string, mixed>
     */
    private function buildEventEntry(object|array $row): array
    {
        $data = (array) $row;
        $usage = [
            'provider' => (string) ($data['provider'] ?? ''),
            'model' => (string) ($data['model'] ?? ''),
            'input_tokens' => (int) ($data['input_tokens'] ?? 0),
            'output_tokens' => (int) ($data['output_tokens'] ?? 0),
            'citation_tokens' => (int) ($data['citation_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($data['reasoning_tokens'] ?? 0),
            'search_queries' => (int) ($data['search_queries'] ?? 0),
            'provider_reported_cost_usd' => $data['provider_reported_cost_usd'] ?? null,
        ];
        $expected = $this->tokenBillingService->calculateUsdForUsage($usage);
        $expectedUsd = round((float) ($expected['amount'] ?? 0.0), 6);
        $chargedUsd = round((float) ($data['usd_charged'] ?? 0.0), 6);
        $usdDelta = round($expectedUsd - $chargedUsd, 6);
        $expectedCredits = $this->creditService->creditsForUsd($expectedUsd);
        $chargedCredits = (int) ($data['credits_charged'] ?? 0);
        $creditDelta = $expectedCredits - $chargedCredits;
        $status = $this->classifyDelta($usdDelta, $creditDelta);

        return [
            'eventId' => (int) ($data['event_id'] ?? 0),
            'itemId' => (int) ($data['audit_run_item_id'] ?? 0),
            'itemPublicId' => (string) ($data['item_public_id'] ?? ''),
            'position' => (int) ($data['position'] ?? 0),
            'targetUrl' => (string) ($data['target_url'] ?? ''),
            'runPublicId' => (string) ($data['run_public_id'] ?? ''),
            'userUid' => (string) ($data['user_uid'] ?? ''),
            'websiteName' => (string) (($data['website_entity_name'] ?? null) ?: ($data['website_name'] ?? '')),
            'websiteUrl' => (string) ($data['website_url'] ?? ''),
            'workflow' => (string) ($data['workflow'] ?? ''),
            'pipelineMode' => (string) ($data['pipeline_mode'] ?? ''),
            'step' => (string) ($data['step'] ?? ''),
            'provider' => (string) ($data['provider'] ?? ''),
            'model' => (string) ($data['model'] ?? ''),
            'inputTokens' => (int) ($data['input_tokens'] ?? 0),
            'outputTokens' => (int) ($data['output_tokens'] ?? 0),
            'totalTokens' => (int) ($data['total_tokens'] ?? 0),
            'citationTokens' => (int) ($data['citation_tokens'] ?? 0),
            'reasoningTokens' => (int) ($data['reasoning_tokens'] ?? 0),
            'searchQueries' => (int) ($data['search_queries'] ?? 0),
            'providerReportedCostUsd' => is_numeric($data['provider_reported_cost_usd'] ?? null) ? round((float) $data['provider_reported_cost_usd'], 6) : null,
            'chargedUsd' => $chargedUsd,
            'expectedUsd' => $expectedUsd,
            'usdDelta' => $usdDelta,
            'chargedCredits' => $chargedCredits,
            'expectedCredits' => $expectedCredits,
            'creditDelta' => $creditDelta,
            'status' => $status,
            'pricingSource' => (string) ($expected['source'] ?? 'unknown'),
            'isExact' => (bool) ($expected['isExact'] ?? false),
            'createdAt' => isset($data['event_created_at']) ? (string) $data['event_created_at'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backfillEvent(int $eventId): array
    {
        return DB::transaction(function () use ($eventId): array {
            /** @var AiUsageEvent $event */
            $event = AiUsageEvent::query()
                ->with(['item.run'])
                ->lockForUpdate()
                ->findOrFail($eventId);

            $entry = $this->buildEventEntry([
                'event_id' => $event->id,
                'audit_run_item_id' => $event->audit_run_item_id,
                'step' => $event->step,
                'provider' => $event->provider,
                'model' => $event->model,
                'input_tokens' => $event->input_tokens,
                'output_tokens' => $event->output_tokens,
                'total_tokens' => $event->total_tokens,
                'citation_tokens' => $event->citation_tokens,
                'reasoning_tokens' => $event->reasoning_tokens,
                'search_queries' => $event->search_queries,
                'provider_reported_cost_usd' => $event->provider_reported_cost_usd,
                'credits_charged' => $event->credits_charged,
                'usd_charged' => $event->usd_charged,
                'event_created_at' => optional($event->created_at)?->toIso8601String(),
                'item_public_id' => (string) ($event->item?->public_id ?? ''),
                'position' => (int) ($event->item?->position ?? 0),
                'target_url' => (string) ($event->item?->target_url ?? ''),
                'run_public_id' => (string) ($event->item?->run?->public_id ?? ''),
                'user_uid' => (string) ($event->item?->run?->user_uid ?? ''),
                'website_name' => (string) ($event->item?->run?->website_name ?? ''),
                'website_url' => (string) ($event->item?->run?->website_url ?? ''),
                'workflow' => (string) ($event->item?->run?->workflow ?? ''),
                'pipeline_mode' => (string) ($event->item?->run?->pipeline_mode ?? ''),
            ]);

            if ($entry['status'] !== 'undercharged') {
                return [
                    'eventId' => $event->id,
                    'runPublicId' => (string) ($entry['runPublicId'] ?? ''),
                    'itemPublicId' => (string) ($entry['itemPublicId'] ?? ''),
                    'applied' => false,
                    'reason' => 'Event is not undercharged anymore.',
                ];
            }

            $run = $event->item?->run;
            if (! $run) {
                throw new \RuntimeException('Audit run not found for AiUsageEvent.');
            }

            $deltaUsd = round((float) $entry['usdDelta'], 6);
            $deltaCredits = max(0, (int) $entry['creditDelta']);

            $adjustment = $this->creditService->mutateUsdWithExactCredits(
                firebaseUid: (string) $run->user_uid,
                type: 'subtract',
                amountUsd: $deltaUsd,
                creditDelta: $deltaCredits,
                reason: $this->buildBackfillReason($entry),
                source: 'audit_reconcile',
                referenceType: 'ai_usage_event',
                referenceId: (string) $event->id,
            );

            $event->forceFill([
                'usd_charged' => (float) $entry['expectedUsd'],
                'credits_charged' => (int) $entry['expectedCredits'],
            ])->save();

            return [
                'eventId' => $event->id,
                'runPublicId' => (string) ($entry['runPublicId'] ?? ''),
                'itemPublicId' => (string) ($entry['itemPublicId'] ?? ''),
                'applied' => true,
                'usdDelta' => $deltaUsd,
                'creditDelta' => $deltaCredits,
                'newUsdCharged' => (float) $entry['expectedUsd'],
                'newCreditsCharged' => (int) $entry['expectedCredits'],
                'transaction' => $adjustment['log'],
            ];
        });
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'all', 'undercharged', 'overcharged', 'aligned' => strtolower(trim($status)),
            default => 'undercharged',
        };
    }

    private function classifyDelta(float $usdDelta, int $creditDelta): string
    {
        if ($usdDelta > self::USD_EPSILON || $creditDelta > 0) {
            return 'undercharged';
        }

        if ($usdDelta < -self::USD_EPSILON || $creditDelta < 0) {
            return 'overcharged';
        }

        return 'aligned';
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function buildBackfillReason(array $entry): string
    {
        return sprintf(
            'Audit AI backfill [%s] %s · event #%d · run #%s · corrected %0.6f -> %0.6f USD',
            (string) ($entry['step'] ?? 'unknown_step'),
            (string) ($entry['model'] ?? 'unknown-model'),
            (int) ($entry['eventId'] ?? 0),
            (string) ($entry['runPublicId'] ?? 'unknown-run'),
            (float) ($entry['chargedUsd'] ?? 0.0),
            (float) ($entry['expectedUsd'] ?? 0.0),
        );
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareByDeltaMagnitude(array $left, array $right): int
    {
        $byUsd = abs((float) ($right['usdDelta'] ?? 0)) <=> abs((float) ($left['usdDelta'] ?? 0));

        if ($byUsd !== 0) {
            return $byUsd;
        }

        return ((int) ($right['creditDelta'] ?? 0)) <=> ((int) ($left['creditDelta'] ?? 0));
    }
}
