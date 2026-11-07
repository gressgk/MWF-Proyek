<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Campaign;

class CampaignPolicy
{
    public function update(User $user, Campaign $campaign)
    {
        return $user->id == $campaign->user_id;
    }

    public function delete(User $user, Campaign $campaign)
    {
        return $user->id == $campaign->user_id;
    }
}