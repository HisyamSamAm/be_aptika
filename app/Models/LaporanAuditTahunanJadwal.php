<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LaporanAuditTahunanJadwal extends Model
{
    use HasFactory;

    protected $table = 'laporan_audit_tahunan_jadwal';
    protected $guarded = ['id'];

    public function objekAudit()
    {
        return $this->belongsTo(LaporanAuditTahunanObjek::class, 'laporan_audit_tahunan_objek_id');
    }
}
