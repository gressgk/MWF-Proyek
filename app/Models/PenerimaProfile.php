<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PenerimaProfile extends Model
{
    protected $fillable = [
        'user_id',
        'nama_organisasi',
        'jenis_organisasi',
        'alamat',
        'nomor_rekening',
        'dokumen_ktp',
        'dokumen_legalitas',
        'alasan_ditolak',
    ];

    public function Owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
