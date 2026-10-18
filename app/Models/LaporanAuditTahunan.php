<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LaporanAuditTahunan extends Model
{
    use HasFactory;

    protected $table = 'laporan_audit_tahunan';
    protected $guarded = ['id'];

    public function timAuditor()
    {
        return $this->hasMany(LaporanAuditTahunanTim::class, 'laporan_audit_tahunan_id');
    }

    public function objekAudit()
    {
        return $this->hasMany(LaporanAuditTahunanObjek::class, 'laporan_audit_tahunan_id');
    }
}
