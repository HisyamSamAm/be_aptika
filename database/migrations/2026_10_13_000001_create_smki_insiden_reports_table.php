<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-016: Pelaporan Insiden Keamanan Informasi.
     * Satu baris = Bagian I (pelapor) + Bagian II (pemilik sistem).
     */
    public function up(): void
    {
        if (Schema::hasTable('smki_insiden_reports')) {
            return;
        }

        Schema::create('smki_insiden_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('bidang_id')->nullable()->index();

            // Bagian I: kategori & jenis
            $table->string('kategori_insiden');
            $table->string('kategori_lainnya')->nullable();
            $table->string('jenis_layanan'); // Proaktif | Reaktif

            // Bagian I: identitas pelapor
            $table->date('tanggal_laporan');
            $table->string('nama_pelapor');
            $table->string('jabatan_pelapor')->nullable();
            $table->string('instansi_pelapor');
            $table->string('nip_pelapor')->nullable();
            $table->string('no_hp_pelapor')->nullable();
            $table->string('email_pelapor');
            $table->dateTime('waktu_kejadian');

            // Bagian I: sistem terdampak
            $table->string('nama_sistem');
            $table->string('jenis_sistem')->nullable(); // Server | Website | PC | Network
            $table->string('url')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('os')->nullable();
            $table->string('web_server')->nullable();
            $table->string('bukti_pelapor')->nullable();
            $table->string('bukti_pelapor_nama')->nullable();

            // Bagian II: identitas pemilik sistem
            $table->string('nama_instansi_pemilik')->nullable();
            $table->text('alamat_instansi')->nullable();
            $table->string('telp_fax_instansi')->nullable();
            $table->string('email_instansi')->nullable();

            // Bagian II: PIC
            $table->string('nama_pic')->nullable();
            $table->string('jabatan_pic')->nullable();
            $table->string('nip_pic')->nullable();
            $table->string('no_hp_pic')->nullable();
            $table->string('email_pic')->nullable();

            // Bagian II: rincian & penanganan
            $table->string('lokasi_insiden')->nullable();
            $table->text('deskripsi_kejadian')->nullable();
            $table->string('isp')->nullable();
            $table->boolean('is_downtime')->default(false);
            $table->string('durasi_downtime')->nullable();
            $table->text('tindakan_awal')->nullable();
            $table->string('bukti_pemilik')->nullable();
            $table->string('bukti_pemilik_nama')->nullable();

            $table->string('status_laporan')->default('Menunggu Pemilik Sistem');

            $table->softDeletes();
            $table->timestamps();

            $table->index('kategori_insiden');
            $table->index('status_laporan');
            $table->index('nama_sistem');

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_insiden_reports');
    }
};
