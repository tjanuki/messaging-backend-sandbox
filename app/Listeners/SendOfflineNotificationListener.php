<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Jobs\SendPushNotificationJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOfflineNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'notifications';
    public $delay = 2; // seconds

    public function handle(MessageSent $event): void
    {
        try {
            Log::info('Processing offline notification for message', [
                'message_id' => $event->message->id,
                'conversation_id' => $event->message->conversation_id
            ]);

            // Dispatch the push notification job
            SendPushNotificationJob::dispatch($event->message)
                ->delay(now()->addSeconds($this->delay));

            Log::info('Offline notification job dispatched', [
                'message_id' => $event->message->id
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch offline notification', [
                'message_id' => $event->message->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function failed(MessageSent $event, \Throwable $exception): void
    {
        Log::error('Offline notification listener failed', [
            'message_id' => $event->message->id,
            'error' => $exception->getMessage()
        ]);
    }
}