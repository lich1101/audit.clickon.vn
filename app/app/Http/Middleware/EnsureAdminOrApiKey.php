<?php

namespace App\Http\Middleware;

use App\Services\FirebaseIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminOrApiKey
{
    public function __construct(
        private readonly FirebaseIdentityService $identityService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = (string) $request->header('X-Api-Key');
        $expectedApiKey = (string) env('LARAVEL_INTERNAL_API_KEY');

        if ($expectedApiKey !== '' && hash_equals($expectedApiKey, $apiKey)) {
            $request->attributes->set('actor_source', $request->header('X-Proxy-Source', 'api'));
            $request->attributes->set('firebase_role', 'admin');

            return $next($request);
        }

        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'message' => 'Missing Firebase bearer token or API key.',
            ], 401);
        }

        $identity = $this->identityService->authenticate($token, null);

        if (($identity['firebase_role'] ?? 'user') !== 'admin') {
            return response()->json([
                'message' => 'Admin role required.',
            ], 403);
        }

        $request->attributes->add($identity);
        $request->attributes->set('actor_source', 'admin');

        return $next($request);
    }
}
