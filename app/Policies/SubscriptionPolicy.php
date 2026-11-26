<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isDonatur();
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $user->isDonatur() && (int) $subscription->user_id === (int) $user->id;
    }

    public function manage(User $user, Subscription $subscription): bool
    {
        return $user->isDonatur() && (int) $subscription->user_id === (int) $user->id;
    }
}
