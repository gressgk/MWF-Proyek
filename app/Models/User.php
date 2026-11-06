<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'avatar',
        'role',
        'status_verifikasi',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // relasi
    public function PenerimaProfile()
    {
        return $this->hasOne(PenerimaProfile::class);
    }

    public function Campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function Donations()
    {
        return $this->hasMany(Donation::class);
    }

    // helper role
    public function isAdmin()
    {
        return $this->role == 'admin';
    }

    public function isPenerima()
    {
        return $this->role == 'penerima';
    }

    public function isDonatur()
    {
        return $this->role == 'donatur';
    }
}
