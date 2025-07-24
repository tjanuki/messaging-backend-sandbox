<?php

namespace App\Http\Middleware;

use App\Services\UserStatusService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserOnlineStatus
{
    protected UserStatusService $userStatusService;

    public function __construct(UserStatusService $userStatusService)
    {
        $this->userStatusService = $userStatusService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only update status for authenticated API requests
        if ($request->user() && $request->is('api/*')) {
            $this->userStatusService->setUserOnline($request->user()->id);
        }

        return $next($request);
    }
}