<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LaporanAuditTahunanTim extends Model
{
    use HasFactory;

    protected $table = 'laporan_audit_tahunan_tim';
    protected $guarded = ['id'];

    public function laporanAuditTahunan()
    {
        return $this->belongsTo(LaporanAuditTahunan::class, 'laporan_audit_tahunan_id');
    }
}
