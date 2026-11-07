<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Donation;

class DonationPolicy
{
    public function view(User $user, Donation $donation)
    {
        return $user->id == $donation->user_id;
    }
}