<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Donation;

class DonationPolicy
{
    public function viewAny(User $user)
    {
        return $user->isAdmin() || $user->isDonatur() || $user->isPenerima();
    }

    public function view(User $user, Donation $donation)
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isDonatur()) {
            return $user->id == $donation->user_id;
        }

        if ($user->isPenerima()) {
            return $user->id == $donation->Campaign?->user_id;
        }

        return false;
    }

    public function create(User $user)
    {
        return $user->isDonatur();
    }
    
    public function konfirmasiDiterima(User $user, Donation $donation)
    {
        return $user->isPenerima() && $user->id == $donation->Campaign?->user_id && $donation->jenis == 'barang';
    }

    public function kelolaRutin(User $user, Donation $donation)
    {
        return $user->isDonatur() && $user->id == $donation->user_id && $donation->is_recurring;
    }
}