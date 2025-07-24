<?php

namespace App\Console\Commands;

use App\Services\TypingService;
use App\Services\UserStatusService;
use Illuminate\Console\Command;

class CleanupTypingIndicators extends Command
{
    protected $signature = 'messaging:cleanup-typing';
    
    protected $description = 'Clean up expired typing indicators and offline users';

    protected TypingService $typingService;
    protected UserStatusService $userStatusService;

    public function __construct(
        TypingService $typingService,
        UserStatusService $userStatusService
    ) {
        parent::__construct();
        $this->typingService = $typingService;
        $this->userStatusService = $userStatusService;
    }

    public function handle()
    {
        $this->info('Starting cleanup of expired typing indicators and offline users...');

        // Cleanup expired typing indicators
        $expiredTypingCount = $this->typingService->cleanupExpiredTypingIndicators();
        $this->info("Cleaned up {$expiredTypingCount} expired typing indicators");

        // Cleanup offline users (based on cache expiration)
        $offlineUsersCount = $this->userStatusService->cleanupOfflineUsers();
        $this->info("Marked {$offlineUsersCount} users as offline");

        $this->info('Cleanup completed successfully');
        
        return Command::SUCCESS;
    }
}