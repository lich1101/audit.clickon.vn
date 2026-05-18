<?php

namespace App\Services;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FirestoreService
{
    private const DATASTORE_SCOPE = 'https://www.googleapis.com/auth/datastore';

    /**
     * @param  array<int, string>  $fieldPaths
     * @return array<string, mixed>
     */
    private function buildUpdateMask(array $fieldPaths): array
    {
        return [
            'fieldPaths' => array_values(array_unique($fieldPaths)),
        ];
    }

    /**
     * Firestore REST expects update masks as repeated query params:
     * updateMask.fieldPaths=a&updateMask.fieldPaths=b
     *
     * @param  array<int, string>  $fieldPaths
     */
    private function appendUpdateMask(string $url, array $fieldPaths): string
    {
        $query = collect(array_values(array_unique($fieldPaths)))
            ->map(fn (string $fieldPath): string => 'updateMask.fieldPaths='.rawurlencode($fieldPath))
            ->implode('&');

        return $query === '' ? $url : "{$url}?{$query}";
    }

    private function projectId(): string
    {
        $projectId = env('FIREBASE_PROJECT_ID');

        if (! $projectId) {
            throw new RuntimeException('FIREBASE_PROJECT_ID is not configured.');
        }

        return $projectId;
    }

    private function credentialsPath(): ?string
    {
        return env('FIREBASE_CREDENTIALS') ?: env('GOOGLE_APPLICATION_CREDENTIALS');
    }

    private function baseUrl(): string
    {
        return sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents',
            $this->projectId()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function authHeaders(): array
    {
        $credentialsPath = $this->credentialsPath();

        if ($credentialsPath) {
            putenv("GOOGLE_APPLICATION_CREDENTIALS={$credentialsPath}");
        }

        $credentials = ApplicationDefaultCredentials::getCredentials(self::DATASTORE_SCOPE);
        $token = $credentials->fetchAuthToken(HttpHandlerFactory::build(new Client()));
        $accessToken = $token['access_token'] ?? null;

        if (! $accessToken) {
            throw new RuntimeException('Unable to fetch Google access token for Firestore.');
        }

        return [
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRawDocument(string $documentPath): ?array
    {
        $response = Http::withHeaders($this->authHeaders())
            ->acceptJson()
            ->get("{$this->baseUrl()}/{$documentPath}");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function decodeFields(array $fields): array
    {
        $decoded = [];

        foreach ($fields as $key => $value) {
            $decoded[$key] = $this->decodeValue($value);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function decodeValue(array $value): mixed
    {
        return match (true) {
            array_key_exists('stringValue', $value) => $value['stringValue'],
            array_key_exists('integerValue', $value) => (int) $value['integerValue'],
            array_key_exists('doubleValue', $value) => (float) $value['doubleValue'],
            array_key_exists('booleanValue', $value) => (bool) $value['booleanValue'],
            array_key_exists('timestampValue', $value) => $value['timestampValue'],
            array_key_exists('mapValue', $value) => $this->decodeFields($value['mapValue']['fields'] ?? []),
            array_key_exists('arrayValue', $value) => array_map(
                fn (array $item): mixed => $this->decodeValue($item),
                $value['arrayValue']['values'] ?? []
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function encodeFields(array $data): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $fields[$key] = $this->encodeValue($value);
        }

        return $fields;
    }

    private function encodeValue(mixed $value): array
    {
        if (is_string($value)) {
            return ['stringValue' => $value];
        }

        if (is_int($value)) {
            return ['integerValue' => (string) $value];
        }

        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }

        if ($value instanceof \DateTimeInterface) {
            return ['timestampValue' => Carbon::instance($value)->toIso8601String()];
        }

        if (is_array($value) && array_is_list($value)) {
            return [
                'arrayValue' => [
                    'values' => array_map(fn (mixed $item): array => $this->encodeValue($item), $value),
                ],
            ];
        }

        if (is_array($value)) {
            return [
                'mapValue' => [
                    'fields' => $this->encodeFields($value),
                ],
            ];
        }

        if ($value !== null) {
            return ['timestampValue' => (string) $value];
        }

        return ['nullValue' => null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapDocument(?array $document): ?array
    {
        if (! $document) {
            return null;
        }

        return [
            ...$this->decodeFields($document['fields'] ?? []),
            '_name' => $document['name'] ?? null,
            '_createTime' => $document['createTime'] ?? null,
            '_updateTime' => $document['updateTime'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(string $uid): ?array
    {
        return $this->mapDocument($this->getRawDocument("users/{$uid}"));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPlan(string $planId): ?array
    {
        return $this->mapDocument($this->getRawDocument("plans/{$planId}"));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getWebsite(string $websiteId): ?array
    {
        return $this->mapDocument($this->getRawDocument("websites/{$websiteId}"));
    }

    public function getBalance(string $uid): int
    {
        $user = $this->getUser($uid);

        if (! $user) {
            throw new RuntimeException('User profile does not exist in Firestore.');
        }

        return (int) ($user['credits'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function mutateCredits(
        string $userId,
        string $type,
        int $amount,
        string $reason,
        string $source,
    ): array {
        $attempt = 0;

        while ($attempt < 5) {
            $attempt++;
            $rawUser = $this->getRawDocument("users/{$userId}");

            if (! $rawUser) {
                throw new RuntimeException('User profile does not exist in Firestore.');
            }

            $user = $this->mapDocument($rawUser);
            $before = (int) ($user['credits'] ?? 0);
            $after = $type === 'add' ? $before + $amount : $before - $amount;

            if ($type === 'subtract' && $after < 0) {
                throw new RuntimeException('Insufficient credits.');
            }

            $now = now();
            $logId = (string) Str::ulid();
            $commitBody = [
                'writes' => [
                    [
                        'update' => [
                            'name' => $rawUser['name'],
                            'fields' => $this->encodeFields([
                                'credits' => $after,
                                'updatedAt' => $now,
                            ]),
                        ],
                        'updateMask' => [
                            'fieldPaths' => ['credits', 'updatedAt'],
                        ],
                        'currentDocument' => [
                            'updateTime' => $rawUser['updateTime'],
                        ],
                    ],
                    [
                        'update' => [
                            'name' => "{$this->baseUrl()}/creditLogs/{$logId}",
                            'fields' => $this->encodeFields([
                                'userId' => $userId,
                                'type' => $type,
                                'amount' => $amount,
                                'balanceBefore' => $before,
                                'balanceAfter' => $after,
                                'reason' => $reason,
                                'source' => $source,
                                'createdAt' => $now,
                            ]),
                        ],
                        'currentDocument' => [
                            'exists' => false,
                        ],
                    ],
                ],
            ];

            $response = Http::withHeaders($this->authHeaders())
                ->acceptJson()
                ->post("{$this->baseUrl()}:commit", $commitBody);

            if ($response->successful()) {
                return [
                    'credits' => $after,
                    'log' => [
                        'id' => $logId,
                        'userId' => $userId,
                        'type' => $type,
                        'amount' => $amount,
                        'balanceBefore' => $before,
                        'balanceAfter' => $after,
                        'reason' => $reason,
                        'source' => $source,
                        'createdAt' => $now->toIso8601String(),
                    ],
                ];
            }

            if (! in_array($response->status(), [409, 412], true)) {
                $response->throw();
            }
        }

        throw new RuntimeException('Unable to commit credit mutation after multiple retries.');
    }

    public function seedAdmin(string $uid, string $email, ?string $displayName = null): void
    {
        $now = now();
        $existing = $this->getUser($uid);
        $currentCredits = (int) ($existing['credits'] ?? 0);
        $createdAt = $existing['createdAt'] ?? $now;
        $payload = [
            'uid' => $uid,
            'email' => $email,
            'role' => 'admin',
            'credits' => $currentCredits,
            'createdAt' => $createdAt,
            'updatedAt' => $now,
        ];

        if ($displayName !== null && trim($displayName) !== '') {
            $payload['displayName'] = trim($displayName);
        }

        $fieldPaths = ['uid', 'email', 'role', 'credits', 'createdAt', 'updatedAt'];

        if (array_key_exists('displayName', $payload)) {
            $fieldPaths[] = 'displayName';
        }

        Http::withHeaders($this->authHeaders())
            ->acceptJson()
            ->patch($this->appendUpdateMask("{$this->baseUrl()}/users/{$uid}", $fieldPaths), [
                'fields' => $this->encodeFields($payload),
            ])
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertAuditRun(array $payload): void
    {
        $documentId = (string) ($payload['publicId'] ?? '');

        if ($documentId === '') {
            throw new RuntimeException('Audit run publicId is required.');
        }

        $this->patchDocument('auditRuns', $documentId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertAuditRunItem(array $payload): void
    {
        $documentId = (string) ($payload['publicId'] ?? '');

        if ($documentId === '') {
            throw new RuntimeException('Audit run item publicId is required.');
        }

        $this->patchDocument('auditRunItems', $documentId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function patchDocument(string $collection, string $documentId, array $payload): void
    {
        Http::withHeaders($this->authHeaders())
            ->acceptJson()
            ->patch($this->appendUpdateMask("{$this->baseUrl()}/{$collection}/{$documentId}", array_keys($payload)), [
                'fields' => $this->encodeFields($payload),
            ])
            ->throw();
    }
}
