<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $fillable = [
        'donation_id',
        'ekspedisi',
        'no_resi',
        'estimasi_ongkir',
        'status',
        'last_tracked_at',
    ];

    public function Donation()
    {
        return $this->belongsTo(Donation::class);
    }
}
