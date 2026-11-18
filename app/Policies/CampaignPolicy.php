<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Campaign;

class CampaignPolicy
{
    public function viewAny(User $user)
    {
        return $user->isAdmin() || $user->isPenerima();
    }

    public function view(User $user, Campaign $campaign)
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isPenerima()) {
            return $user->id == $campaign->user_id;
        }

        return $user->isDonatur() && $campaign->status == 'aktif';
    }

    public function create(User $user)
    {
        return $user->isPenerima()
            && $user->status_verifikasi == 'approved';
    }

    public function update(User $user, Campaign $campaign)
    {
        return $user->isPenerima()
            && $user->id == $campaign->user_id;
    }

    public function delete(User $user, Campaign $campaign)
    {
        return $user->isPenerima()
            && $user->id == $campaign->user_id;
    }

    public function approve(User $user, Campaign $campaign)
    {
        return $user->isAdmin();
    }

    public function reject(User $user, Campaign $campaign)
    {
        return $user->isAdmin();
    }
}