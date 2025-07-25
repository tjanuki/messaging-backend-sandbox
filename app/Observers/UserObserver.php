<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        Log::info('New user created', [
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name
        ]);
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        // Log FCM token updates
        if ($user->wasChanged('fcm_token')) {
            $oldToken = $user->getOriginal('fcm_token');
            $newToken = $user->fcm_token;
            
            Log::info('User FCM token updated', [
                'user_id' => $user->id,
                'old_token' => $oldToken ? substr($oldToken, 0, 20) . '...' : null,
                'new_token' => $newToken ? substr($newToken, 0, 20) . '...' : null
            ]);
        }

        // Log online status changes
        if ($user->wasChanged('is_online')) {
            Log::info('User online status changed', [
                'user_id' => $user->id,
                'is_online' => $user->is_online,
                'last_seen' => $user->last_seen
            ]);
        }
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        Log::info('User deleted', [
            'user_id' => $user->id,
            'email' => $user->email
        ]);
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        Log::info('User restored', [
            'user_id' => $user->id,
            'email' => $user->email
        ]);
    }

    /**
     * Handle the User "force deleted" event.
     */
    public function forceDeleted(User $user): void
    {
        Log::info('User force deleted', [
            'user_id' => $user->id,
            'email' => $user->email
        ]);
    }
}