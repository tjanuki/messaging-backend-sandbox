<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Message $message;
    protected int $tries = 3;
    protected int $maxExceptions = 3;
    protected int $backoff = 60; // seconds

    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->onQueue('notifications');
    }

    public function handle(NotificationService $notificationService): void
    {
        try {
            Log::info('Processing push notification job', [
                'message_id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'sender_id' => $this->message->user_id
            ]);

            $notificationService->sendMessageNotification($this->message);

            Log::info('Push notification job completed successfully', [
                'message_id' => $this->message->id
            ]);
        } catch (\Exception $e) {
            Log::error('Push notification job failed', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Push notification job failed permanently', [
            'message_id' => $this->message->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }
}