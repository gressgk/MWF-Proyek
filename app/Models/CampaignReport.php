<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignReport extends Model
{
    protected $fillable = [
        'campaign_id',
        'judul',
        'isi',
        'foto_bukti',
        'dokumen_bukti',
        'nominal_terpakai',
    ];

    public function Campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function Photos()
    {
        return $this->hasMany(
            CampaignReportPhoto::class,
            'campaign_report_id'
        );
    }
}
