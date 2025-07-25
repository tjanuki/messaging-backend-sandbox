<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class TestNotificationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notification:test {user_id} {--title=Test} {--body=This is a test notification}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test push notification to a specific user';

    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = $this->argument('user_id');
        $title = $this->option('title');
        $body = $this->option('body');

        $user = User::find($userId);

        if (!$user) {
            $this->error("User with ID {$userId} not found");
            return 1;
        }

        if (!$user->fcm_token) {
            $this->error("User {$user->name} does not have an FCM token");
            return 1;
        }

        $this->info("Sending notification to {$user->name}...");

        $notification = [
            'title' => $title,
            'body' => $body,
            'data' => [
                'type' => 'test',
                'user_id' => (string) $user->id,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
            ]
        ];

        $success = $this->notificationService->sendToDevice($user->fcm_token, $notification);

        if ($success) {
            $this->info('✅ Notification sent successfully!');
            return 0;
        } else {
            $this->error('❌ Failed to send notification');
            return 1;
        }
    }
}