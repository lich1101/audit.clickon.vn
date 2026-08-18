<?php

namespace App\Services;

use App\Models\AppUser;
use RuntimeException;

class LocalAuthToken
{
    public static function enabled(): bool
    {
        return (bool) config('app.local_auth');
    }

    public static function issue(AppUser $user, int $ttlSeconds = 60 * 60 * 24 * 5): string
    {
        $payload = [
            'uid' => $user->firebase_uid,
            'email' => $user->email,
            'role' => $user->role === 'admin' ? 'admin' : 'user',
            'name' => $user->display_name,
            'exp' => time() + $ttlSeconds,
        ];

        $body = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        return 'local.'.$body.'.'.hash_hmac('sha256', $body, self::secret());
    }

    /**
     * @return array{uid:string,email:string,role:string,name:?string}
     */
    public static function verify(string $token): array
    {
        if (! str_starts_with($token, 'local.')) {
            throw new RuntimeException('Not a local auth token.');
        }

        $parts = explode('.', $token, 3);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid local auth token.');
        }

        [, $body, $signature] = $parts;
        $expected = hash_hmac('sha256', $body, self::secret());

        if (! hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid local auth signature.');
        }

        $padded = strtr($body, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $payload = json_decode((string) base64_decode($padded, true), true);

        if (! is_array($payload) || empty($payload['uid']) || empty($payload['exp'])) {
            throw new RuntimeException('Invalid local auth payload.');
        }

        if ((int) $payload['exp'] < time()) {
            throw new RuntimeException('Local auth token expired.');
        }

        return [
            'uid' => (string) $payload['uid'],
            'email' => (string) ($payload['email'] ?? ''),
            'role' => ($payload['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
            'name' => isset($payload['name']) ? (string) $payload['name'] : null,
        ];
    }

    private static function secret(): string
    {
        $secret = (string) env('LARAVEL_INTERNAL_API_KEY', '');

        if ($secret === '') {
            $secret = (string) env('APP_KEY', 'local-dev-secret');
        }

        return $secret;
    }
}
