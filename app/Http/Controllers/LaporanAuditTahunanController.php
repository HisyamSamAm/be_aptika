<?php

namespace App\Http\Controllers;

use App\Models\LaporanAuditTahunan;
use App\Models\LaporanAuditTahunanTim;
use App\Models\LaporanAuditTahunanObjek;
use App\Models\LaporanAuditTahunanKlausul;
use App\Models\LaporanAuditTahunanJadwal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LaporanAuditTahunanController extends Controller
{
    /**
     * Tampilkan daftar laporan audit tahunan (Untuk tabel di Dashboard)
     */
    public function index()
    {
        $laporan = LaporanAuditTahunan::with([
            'timAuditor',
            'objekAudit.klausul',
            'objekAudit.jadwal'
        ])
        ->orderBy('tahun_smki', 'desc')
        ->orderBy('id', 'desc')
        ->get();

        return response()->json(['data' => $laporan]);
    }

    /**
     * Tampilkan detail satu laporan audit berserta seluruh relasinya
     */
    public function show($id)
    {
        $laporan = LaporanAuditTahunan::with([
            'timAuditor',
            'objekAudit.klausul',
            'objekAudit.jadwal'
        ])->findOrFail($id);

        return response()->json(['data' => $laporan]);
    }

    /**
     * Master data lookup untuk dropdown Tim Auditor, Unit Kerja/Bidang, dan Klausul
     */
    public function lookup()
    {
        $auditors = \App\Models\Auditor::select('id_auditor', 'nama_auditor', 'nip_auditor')
            ->orderBy('nama_auditor', 'asc')
            ->get();

        $bidangList = \App\Models\BidangAuditee::select('id_bidang_auditee', 'bidang_auditee')
            ->orderBy('bidang_auditee', 'asc')
            ->get();

        $lokasiList = \App\Models\LokasiAuditee::select('id_lokasi_auditee', 'lokasi_auditee')
            ->orderBy('lokasi_auditee', 'asc')
            ->get();

        $standardClauses = [
            'Klausul 4 (Konteks Organisasi)',
            'Klausul 5 (Kepemimpinan)',
            'Klausul 6 (Perencanaan)',
            'Klausul 7 (Dukungan)',
            'Klausul 8 (Operasional)',
            'Klausul 9 (Evaluasi Kinerja)',
            'Klausul 10 (Peningkatan)',
            'Annex A.5 (Pengendalian Organisasi)',
            'Annex A.6 (Pengendalian Orang)',
            'Annex A.7 (Pengendalian Fisik)',
            'Annex A.8 (Pengendalian Teknologi)',
        ];

        return response()->json([
            'success' => true,
            'data'    => [
                'auditors'         => $auditors,
                'bidang_auditee'   => $bidangList,
                'lokasi_auditee'   => $lokasiList,
                'standard_clauses' => $standardClauses,
            ],
        ]);
    }

    /**
     * Memastikan aksi pengelolaan formulir tahunan audit hanya dapat dilakukan oleh role Admin.
     */
    private function checkAdminAccess(Request $request): void
    {
        $user = $request->user();
        if (!$user || ($user->role !== 'admin' && !$user->isAdminAptika())) {
            abort(response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Pengelolaan formulir program tahunan audit internal hanya diperuntukkan bagi Administrator.'
            ], 403));
        }
    }

    /**
     * Menyimpan data Langkah 1 (Informasi Audit) dan 6 (Penyusun & Pengesah)
     */
    public function storeInformasi(Request $request)
    {
        $this->checkAdminAccess($request);

        $validated = $request->validate([
            'tahun_smki'       => 'required|integer|between:2000,2100',
            'no_dokumen'       => 'required|string|max:100',
            'no_revisi'        => 'required|string|max:50',
            'tanggal_berlaku'  => 'required|date',
            'kriteria_audit'   => 'required|string',
            'tujuan'           => 'required|string',
            'disusun_oleh'     => 'nullable|string',
            'jabatan_penyusun' => 'nullable|string',
            'disahkan_oleh'    => 'nullable|string',
            'jabatan_pengesah' => 'nullable|string',
            'status'           => 'nullable|string',
        ]);

        // Create or update based on request (jika ada ID, berarti update)
        if ($request->filled('id')) {
            $laporan = LaporanAuditTahunan::findOrFail($request->id);
            $laporan->update($validated);
        } else {
            $laporan = LaporanAuditTahunan::create($validated);
        }

        return response()->json([
            'message' => 'Informasi audit berhasil disimpan',
            'data'    => $laporan
        ]);
    }

    /**
     * Menyimpan data Langkah 2 (Tim Auditor)
     */
    public function storeTimAuditor(Request $request, $id)
    {
        $this->checkAdminAccess($request);

        $laporan = LaporanAuditTahunan::findOrFail($id);
        
        $request->validate([
            'tim_auditor'              => 'required|array',
            'tim_auditor.*.nama_auditor' => 'required|string',
            'tim_auditor.*.unit_kerja'   => 'required|string',
            'tim_auditor.*.peran'        => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            // Hapus data lama (overwrite dengan data baru dari form step 2)
            $laporan->timAuditor()->delete();

            foreach ($request->tim_auditor as $auditor) {
                $laporan->timAuditor()->create($auditor);
            }

            DB::commit();
            return response()->json(['message' => 'Tim auditor berhasil disimpan']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Menyimpan data Langkah 3, 4, 5 (Objek, Klausul, Jadwal) sekaligus
     * Karena saling terkait secara hirarki (Klausul & Jadwal menempel pada Objek Audit)
     */
    public function storeObjekAudit(Request $request, $id)
    {
        $this->checkAdminAccess($request);

        $laporan = LaporanAuditTahunan::findOrFail($id);

        $request->validate([
            'objek_audit'                     => 'required|array',
            'objek_audit.*.kode_objek'        => 'required|string',
            'objek_audit.*.objek_audit'       => 'required|string',
            'objek_audit.*.ruang_lingkup'     => 'required|string',
            'objek_audit.*.unit_kerja'        => 'required|string',
            'objek_audit.*.klausul'           => 'nullable|array',
            'objek_audit.*.klausul.*.klausul_annex' => 'required_with:objek_audit.*.klausul|string',
            'objek_audit.*.klausul.*.uraian_klausul'=> 'required_with:objek_audit.*.klausul|string',
            'objek_audit.*.jadwal'            => 'nullable|array',
            'objek_audit.*.jadwal.*.bulan'    => 'required_with:objek_audit.*.jadwal|integer|between:1,12',
        ]);

        DB::beginTransaction();
        try {
            // Hapus data objek lama beserta turunannya (klausul & jadwal) karena on delete cascade
            $laporan->objekAudit()->delete();

            foreach ($request->objek_audit as $objekData) {
                // Simpan Objek
                $objek = $laporan->objekAudit()->create([
                    'kode_objek'    => $objekData['kode_objek'],
                    'objek_audit'   => $objekData['objek_audit'],
                    'ruang_lingkup' => $objekData['ruang_lingkup'],
                    'unit_kerja'    => $objekData['unit_kerja'],
                ]);

                // Simpan Klausul
                if (!empty($objekData['klausul'])) {
                    foreach ($objekData['klausul'] as $klausulData) {
                        $objek->klausul()->create($klausulData);
                    }
                }

                // Simpan Jadwal (Bulan)
                if (!empty($objekData['jadwal'])) {
                    foreach ($objekData['jadwal'] as $jadwalData) {
                        $objek->jadwal()->create($jadwalData);
                    }
                }
            }

            DB::commit();
            return response()->json(['message' => 'Objek Audit, Klausul, dan Jadwal berhasil disimpan']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Terjadi kesalahan', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Menyimpan sekaligus seluruh formulir (Create / Update sekaligus)
     */
    public function storeAll(Request $request)
    {
        $this->checkAdminAccess($request);

        $request->validate([
            'tahun_smki'       => 'required|integer|between:2000,2100',
            'no_dokumen'       => 'required|string|max:100',
            'no_revisi'        => 'required|string|max:50',
            'tanggal_berlaku'  => 'required|date',
            'kriteria_audit'   => 'required|string',
            'tujuan'           => 'required|string',
            'disusun_oleh'     => 'nullable|string',
            'jabatan_penyusun' => 'nullable|string',
            'disahkan_oleh'    => 'nullable|string',
            'jabatan_pengesah' => 'nullable|string',
            'status'           => 'nullable|string',
            'tim_auditor'      => 'nullable|array',
            'objek_audit'      => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            $dataInformasi = [
                'tahun_smki'       => $request->tahun_smki,
                'no_dokumen'       => $request->no_dokumen,
                'no_revisi'        => $request->no_revisi,
                'tanggal_berlaku'  => $request->tanggal_berlaku,
                'kriteria_audit'   => $request->kriteria_audit,
                'tujuan'           => $request->tujuan,
                'disusun_oleh'     => $request->disusun_oleh,
                'jabatan_penyusun' => $request->jabatan_penyusun,
                'disahkan_oleh'    => $request->disahkan_oleh,
                'jabatan_pengesah' => $request->jabatan_pengesah,
                'status'           => $request->status ?? 'Draft',
            ];

            if ($request->filled('id')) {
                $laporan = LaporanAuditTahunan::findOrFail($request->id);
                $laporan->update($dataInformasi);
            } else {
                $laporan = LaporanAuditTahunan::create($dataInformasi);
            }

            // Simpan Tim Auditor
            if ($request->has('tim_auditor')) {
                $laporan->timAuditor()->delete();
                foreach ($request->tim_auditor as $auditor) {
                    if (!empty($auditor['nama_auditor'])) {
                        $laporan->timAuditor()->create([
                            'nama_auditor' => $auditor['nama_auditor'],
                            'unit_kerja'   => $auditor['unit_kerja'] ?? '-',
                            'peran'        => $auditor['peran'] ?? 'Auditor',
                        ]);
                    }
                }
            }

            // Simpan Objek Audit, Klausul, dan Jadwal
            if ($request->has('objek_audit')) {
                $laporan->objekAudit()->delete();
                foreach ($request->objek_audit as $objekData) {
                    if (!empty($objekData['objek_audit'])) {
                        $objek = $laporan->objekAudit()->create([
                            'kode_objek'    => $objekData['kode_objek'] ?? 'OA-01',
                            'objek_audit'   => $objekData['objek_audit'],
                            'ruang_lingkup' => $objekData['ruang_lingkup'] ?? '-',
                            'unit_kerja'    => $objekData['unit_kerja'] ?? '-',
                        ]);

                        if (!empty($objekData['klausul'])) {
                            foreach ($objekData['klausul'] as $klausulData) {
                                if (is_string($klausulData)) {
                                    $objek->klausul()->create([
                                        'klausul_annex' => $klausulData,
                                        'uraian_klausul' => $klausulData,
                                    ]);
                                } elseif (is_array($klausulData) && !empty($klausulData['klausul_annex'])) {
                                    $objek->klausul()->create([
                                        'klausul_annex'  => $klausulData['klausul_annex'],
                                        'uraian_klausul' => $klausulData['uraian_klausul'] ?? $klausulData['klausul_annex'] ?? '-',
                                    ]);
                                }
                            }
                        }

                        if (!empty($objekData['jadwal'])) {
                            foreach ($objekData['jadwal'] as $jadwalData) {
                                if (is_numeric($jadwalData)) {
                                    $objek->jadwal()->create(['bulan' => (int) $jadwalData]);
                                } elseif (is_array($jadwalData) && isset($jadwalData['bulan'])) {
                                    $objek->jadwal()->create(['bulan' => (int) $jadwalData['bulan']]);
                                }
                            }
                        }
                    }
                }
            }

            DB::commit();

            $laporanFresh = LaporanAuditTahunan::with([
                'timAuditor',
                'objekAudit.klausul',
                'objekAudit.jadwal'
            ])->find($laporan->id);

            return response()->json([
                'message' => 'Formulir Program Tahunan Audit Internal berhasil disimpan',
                'data'    => $laporanFresh
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal menyimpan formulir audit tahunan',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * REST Alias untuk store
     */
    public function store(Request $request)
    {
        return $this->storeAll($request);
    }

    /**
     * REST Alias untuk update
     */
    public function update(Request $request, $id)
    {
        $request->merge(['id' => $id]);
        return $this->storeAll($request);
    }

    /**
     * Menghapus laporan audit tahunan berserta data terkaitnya
     */
    public function destroy(Request $request, $id)
    {
        $this->checkAdminAccess($request);

        $laporan = LaporanAuditTahunan::findOrFail($id);
        $laporan->delete();

        return response()->json([
            'message' => 'Formulir audit tahunan berhasil dihapus'
        ]);
    }

    /**
     * Export formulir ke format DOCX berdasarkan template FR-008
     */
    public function exportDocx(Request $request, $id = null, ?\App\Services\SmkiLaporanAuditTahunanDocxService $docxService = null)
    {
        $docxService = $docxService ?: app(\App\Services\SmkiLaporanAuditTahunanDocxService::class);
        $targetId = $id ?: $request->input('id');

        if (!$targetId) {
            $laporan = LaporanAuditTahunan::with([
                'timAuditor',
                'objekAudit.klausul',
                'objekAudit.jadwal'
            ])->orderBy('id', 'desc')->firstOrFail();
        } else {
            $laporan = LaporanAuditTahunan::with([
                'timAuditor',
                'objekAudit.klausul',
                'objekAudit.jadwal'
            ])->findOrFail($targetId);
        }

        $tempFile = $docxService->generateDocx($laporan);
        $filename = 'FR-008_Laporan_Audit_Tahunan_' . str_replace(['/', ' '], '_', $laporan->tahun_smki) . '.docx';

        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }
}


