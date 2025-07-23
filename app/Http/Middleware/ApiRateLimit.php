<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ApiRateLimit
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $key = 'api'): Response
    {
        $identifier = $this->getIdentifier($request);
        
        $rateLimitKey = $key . ':' . $identifier;
        
        // Different limits for different endpoints
        $limits = $this->getLimits($key);
        
        if (RateLimiter::tooManyAttempts($rateLimitKey, $limits['maxAttempts'])) {
            return response()->json([
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => RateLimiter::availableIn($rateLimitKey)
            ], 429);
        }
        
        RateLimiter::hit($rateLimitKey, $limits['decayMinutes'] * 60);
        
        return $next($request);
    }
    
    /**
     * Get the rate limiter identifier for the request.
     */
    protected function getIdentifier(Request $request): string
    {
        if ($request->user()) {
            return 'user:' . $request->user()->id;
        }
        
        return 'ip:' . $request->ip();
    }
    
    /**
     * Get rate limiting configuration for the given key.
     */
    protected function getLimits(string $key): array
    {
        return match ($key) {
            'auth' => ['maxAttempts' => 5, 'decayMinutes' => 15], // Login attempts
            'messages' => ['maxAttempts' => 60, 'decayMinutes' => 1], // Messages per minute
            'api' => ['maxAttempts' => 100, 'decayMinutes' => 1], // General API calls
            default => ['maxAttempts' => 60, 'decayMinutes' => 1],
        };
    }
}
