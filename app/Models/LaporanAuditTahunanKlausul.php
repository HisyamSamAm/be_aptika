<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LaporanAuditTahunanKlausul extends Model
{
    use HasFactory;

    protected $table = 'laporan_audit_tahunan_klausul';
    protected $guarded = ['id'];

    public function objekAudit()
    {
        return $this->belongsTo(LaporanAuditTahunanObjek::class, 'laporan_audit_tahunan_objek_id');
    }
}
