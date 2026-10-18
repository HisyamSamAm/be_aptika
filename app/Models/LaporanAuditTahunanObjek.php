<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LaporanAuditTahunanObjek extends Model
{
    use HasFactory;

    protected $table = 'laporan_audit_tahunan_objek';
    protected $guarded = ['id'];

    public function laporanAuditTahunan()
    {
        return $this->belongsTo(LaporanAuditTahunan::class, 'laporan_audit_tahunan_id');
    }

    public function klausul()
    {
        return $this->hasMany(LaporanAuditTahunanKlausul::class, 'laporan_audit_tahunan_objek_id');
    }

    public function jadwal()
    {
        return $this->hasMany(LaporanAuditTahunanJadwal::class, 'laporan_audit_tahunan_objek_id');
    }
}
