<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BroadcastNotification extends Model
{
    protected $fillable = ['admin_id', 'judul', 'isi', 'target_role'];

    public function Admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
