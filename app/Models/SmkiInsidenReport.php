<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class SmkiInsidenReport extends Model
{
    use BelongsToBidang, HasFactory, SoftDeletes;

    protected $table = 'smki_insiden_reports';

    public const KATEGORI = [
        'web defacement',
        'hacking',
        'malware detection',
        'phishing',
        'spoofing',
        'brute force',
        'denial of service',
        'sniffing',
        'lainnya',
    ];

    public const JENIS_SISTEM = ['Server', 'Website', 'PC', 'Network'];

    public const JENIS_LAYANAN = ['Proaktif', 'Reaktif'];

    public const STATUS = [
        'Menunggu Pemilik Sistem',
        'Dalam Penanganan',
        'Selesai',
    ];

    protected $fillable = [
        'user_id',
        'bidang_id',
        'kategori_insiden',
        'kategori_lainnya',
        'jenis_layanan',
        'tanggal_laporan',
        'nama_pelapor',
        'jabatan_pelapor',
        'instansi_pelapor',
        'nip_pelapor',
        'no_hp_pelapor',
        'email_pelapor',
        'waktu_kejadian',
        'nama_sistem',
        'jenis_sistem',
        'url',
        'ip_address',
        'os',
        'web_server',
        'bukti_pelapor',
        'bukti_pelapor_nama',
        'nama_instansi_pemilik',
        'alamat_instansi',
        'telp_fax_instansi',
        'email_instansi',
        'nama_pic',
        'jabatan_pic',
        'nip_pic',
        'no_hp_pic',
        'email_pic',
        'lokasi_insiden',
        'deskripsi_kejadian',
        'isp',
        'is_downtime',
        'durasi_downtime',
        'tindakan_awal',
        'bukti_pemilik',
        'bukti_pemilik_nama',
        'status_laporan',
    ];

    protected $casts = [
        'tanggal_laporan' => 'date:Y-m-d',
        'waktu_kejadian' => 'datetime',
        'is_downtime' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'bukti_pelapor_url',
        'bukti_pemilik_url',
    ];

    public function getBuktiPelaporUrlAttribute(): ?string
    {
        if (empty($this->bukti_pelapor)) {
            return null;
        }

        return Storage::disk('public')->url($this->bukti_pelapor);
    }

    public function getBuktiPemilikUrlAttribute(): ?string
    {
        if (empty($this->bukti_pemilik)) {
            return null;
        }

        return Storage::disk('public')->url($this->bukti_pemilik);
    }
}
