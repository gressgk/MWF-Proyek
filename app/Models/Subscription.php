<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'subscription_id',
        'user_id',
        'campaign_id',
        'nominal',
        'frequency',
        'interval',
        'interval_unit',
        'payment_type',
        'token',
        'status',
        'is_anonim',
        'idempotency_key',
        'next_execution_at',
        'metadata',
        'management_state',
        'operation_guard_at',
        'operation_guard_owner',
    ];

    /**
     * Sembunyikan token pembayaran dari serialisasi array / JSON.
     */
    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'nominal'           => 'decimal:2',
        'interval'          => 'integer',
        'is_anonim'         => 'boolean',
        'token'             => 'encrypted',
        'next_execution_at' => 'datetime',
        'operation_guard_at' => 'datetime',
        'metadata'          => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function donations()
    {
        return $this->hasMany(Donation::class, 'subscription_id', 'subscription_id');
    }
}
