<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBulkNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $tokens;
    protected array $notification;
    protected int $tries = 3;
    protected int $maxExceptions = 3;
    protected int $backoff = 60; // seconds

    public function __construct(array $tokens, array $notification)
    {
        $this->tokens = $tokens;
        $this->notification = $notification;
        $this->onQueue('notifications');
    }

    public function handle(NotificationService $notificationService): void
    {
        try {
            Log::info('Processing bulk notification job', [
                'token_count' => count($this->tokens),
                'notification_title' => $this->notification['title'] ?? 'Unknown'
            ]);

            $result = $notificationService->sendBulkNotifications($this->tokens, $this->notification);

            Log::info('Bulk notification job completed', [
                'token_count' => count($this->tokens),
                'success_count' => $result['success'],
                'failure_count' => $result['failure']
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk notification job failed', [
                'token_count' => count($this->tokens),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Bulk notification job failed permanently', [
            'token_count' => count($this->tokens),
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }
}