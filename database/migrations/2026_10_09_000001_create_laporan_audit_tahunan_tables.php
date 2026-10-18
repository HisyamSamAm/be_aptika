<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabel Utama: Laporan Audit Tahunan
        if (!Schema::hasTable('laporan_audit_tahunan')) {
            Schema::create('laporan_audit_tahunan', function (Blueprint $table) {
                $table->id();
                $table->year('tahun_smki');
                $table->string('no_dokumen', 100);
                $table->string('no_revisi', 50);
                $table->date('tanggal_berlaku');
                $table->text('kriteria_audit');
                $table->text('tujuan');
                $table->string('disusun_oleh', 255)->nullable();
                $table->string('jabatan_penyusun', 255)->nullable();
                $table->string('disahkan_oleh', 255)->nullable();
                $table->string('jabatan_pengesah', 255)->nullable();
                $table->string('status', 50)->default('Draft'); // Draft, Selesai
                $table->timestamps();
            });
        }

        // 2. Tabel Detail: Tim Auditor
        if (!Schema::hasTable('laporan_audit_tahunan_tim')) {
            Schema::create('laporan_audit_tahunan_tim', function (Blueprint $table) {
                $table->id();
                $table->foreignId('laporan_audit_tahunan_id')->constrained('laporan_audit_tahunan', 'id', 'fk_tim_laporan')->cascadeOnDelete();
                $table->string('nama_auditor', 255);
                $table->string('unit_kerja', 255);
                $table->string('peran', 100); // Lead Auditor, Auditor
                $table->timestamps();
            });
        }

        // 3. Tabel Detail: Objek Audit
        if (!Schema::hasTable('laporan_audit_tahunan_objek')) {
            Schema::create('laporan_audit_tahunan_objek', function (Blueprint $table) {
                $table->id();
                $table->foreignId('laporan_audit_tahunan_id')->constrained('laporan_audit_tahunan', 'id', 'fk_objek_laporan')->cascadeOnDelete();
                $table->string('kode_objek', 50);
                $table->string('objek_audit', 255);
                $table->text('ruang_lingkup');
                $table->string('unit_kerja', 255);
                $table->timestamps();
            });
        }

        // 4. Tabel Detail: Klausul (Berelasi dengan Objek Audit)
        if (!Schema::hasTable('laporan_audit_tahunan_klausul')) {
            Schema::create('laporan_audit_tahunan_klausul', function (Blueprint $table) {
                $table->id();
                $table->foreignId('laporan_audit_tahunan_objek_id')->constrained('laporan_audit_tahunan_objek', 'id', 'fk_klausul_objek')->cascadeOnDelete();
                $table->string('klausul_annex', 255);
                $table->text('uraian_klausul');
                $table->timestamps();
            });
        }

        // 5. Tabel Detail: Jadwal Audit (Berelasi dengan Objek Audit)
        if (!Schema::hasTable('laporan_audit_tahunan_jadwal')) {
            Schema::create('laporan_audit_tahunan_jadwal', function (Blueprint $table) {
                $table->id();
                $table->foreignId('laporan_audit_tahunan_objek_id')->constrained('laporan_audit_tahunan_objek', 'id', 'fk_jadwal_objek')->cascadeOnDelete();
                $table->tinyInteger('bulan'); // 1 = Januari, 12 = Desember
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laporan_audit_tahunan_jadwal');
        Schema::dropIfExists('laporan_audit_tahunan_klausul');
        Schema::dropIfExists('laporan_audit_tahunan_objek');
        Schema::dropIfExists('laporan_audit_tahunan_tim');
        Schema::dropIfExists('laporan_audit_tahunan');
    }
};
