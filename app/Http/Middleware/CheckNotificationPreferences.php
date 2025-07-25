<?php

namespace App\Http\Middleware;

use App\Services\NotificationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckNotificationPreferences
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $notificationType = 'message_notifications'): Response
    {
        $user = $request->user();

        if ($user && !$this->notificationService->shouldSendNotification($user->id, $notificationType)) {
            return response()->json([
                'message' => 'Notifications disabled for this type',
                'notification_type' => $notificationType
            ], 403);
        }

        return $next($request);
    }
}