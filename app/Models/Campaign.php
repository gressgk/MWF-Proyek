<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'category_id',
        'kategori_lainnya',
        'judul',
        'slug',
        'deskripsi',
        'tipe_bantuan',
        'target_nominal',
        'terkumpul_nominal',
        'target_barang',
        'satuan_barang',
        'terkumpul_barang',
        'urgensi',
        'tanggal_mulai',
        'tanggal_selesai',
        'foto_sampul',
        'qr_code_path',
        'status',
        'alasan_ditolak',
    ];

    // relasi
    public function Penerima()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function Category()
    {
        return $this->belongsTo(Category::class);
    }

    public function Donations()
    {
        return $this->hasMany(Donation::class);
    }

    public function Conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function Reports()
    {
        return $this->hasMany(CampaignReport::class);
    }

    // accessor progress
    public function getProgresPersenAttribute()
    {
        if ($this->tipe_bantuan == 'barang') {
            if (!$this->target_barang) {
                return 0;
            }
            return min(100, intval(($this->terkumpul_barang / $this->target_barang) * 100));
        }

        if (!$this->target_nominal) {
            return 0;
        }
        return min(100, intval(($this->terkumpul_nominal / $this->target_nominal) * 100));
    }
}
