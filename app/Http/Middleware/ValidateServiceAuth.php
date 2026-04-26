<?php

namespace App\Http\Middleware;

use App\Support\ServiceAuth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidateServiceAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
        //todo: enable this later
        $token = $request->header('X-Service-Auth');
        if (! $token) {
            return response()->json([
                'error' => 'missing_auth_token',
                'message' => 'X-Service-Auth header is required',
            ], 401);
        }

        $secret = (string) config('coa.service_auth_secret', '');
        if ($secret === '') {
            return response()->json([
                'error' => 'server_misconfiguration',
                'message' => 'Set coa.service_auth_secret (e.g. SERVICE_AUTH_SECRET in .env loaded via config/coa.php).',
            ], 500);
        }

        if (! ServiceAuth::validateToken($token, $secret)) {
            Log::warning('service_auth_token_invalid', [
                'path' => $request->path(),
                'service_id' => $request->header('X-Service-Id'),
            ]);

            return response()->json([
                'error' => 'invalid_auth_token',
                'message' => 'Invalid or expired authentication token',
            ], 401);
        }

        $serviceId = $request->header('X-Service-Id', 'unknown');
        $request->attributes->set('service_id', $serviceId);
        $request->attributes->set('service_auth_date', ServiceAuth::getDateString());

        Log::withContext(['service_id' => $serviceId]);

        return $next($request);
    }
}
