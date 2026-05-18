<?php

namespace App\Http\Middleware;

use App\Services\FirebaseIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFirebaseToken
{
    public function __construct(
        private readonly FirebaseIdentityService $identityService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'message' => 'Missing Firebase bearer token.',
            ], 401);
        }

        $request->attributes->add($this->identityService->authenticate($token));

        return $next($request);
    }
}
