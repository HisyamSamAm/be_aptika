<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiInsidenReport;
use App\Services\SmkiInsidenReportDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SmkiInsidenReportController extends Controller
{
    protected SmkiInsidenReportDocxService $docxService;

    public function __construct(SmkiInsidenReportDocxService $docxService)
    {
        $this->docxService = $docxService;
    }

    private function isAdmin(): bool
    {
        return (bool) auth()->user()?->isAdminAptika();
    }

    /**
     * Ambil satu laporan dengan otorisasi pemilik-or-admin.
     * Non-pemilik mendapat 404 agar keberadaan data tidak bocor.
     */
    private function findForUser($id): SmkiInsidenReport
    {
        $item = SmkiInsidenReport::with(['bidang:id,name,code'])->findOrFail($id);

        if (! $this->isAdmin() && $item->user_id !== auth()->id()) {
            abort(404);
        }

        return $item;
    }

    /**
     * Daftar laporan + search + filter + KPI.
     * Non-admin hanya melihat laporannya sendiri; admin melihat semua.
     */
    public function index(Request $request)
    {
        $query = SmkiInsidenReport::with(['bidang:id,name,code'])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if (! $this->isAdmin()) {
            $query->where('smki_insiden_reports.user_id', auth()->id());
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('nama_pelapor', 'like', "%{$search}%")
                    ->orWhere('nama_sistem', 'like', "%{$search}%")
                    ->orWhere('kategori_insiden', 'like', "%{$search}%")
                    ->orWhere('instansi_pelapor', 'like', "%{$search}%");
            });
        }

        if ($request->filled('kategori')) {
            $query->where('kategori_insiden', $request->input('kategori'));
        }

        if ($request->filled('status')) {
            $query->where('status_laporan', $request->input('status'));
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage === -1) {
            $items = $query->get();
            $paginated = null;
        } else {
            $paginated = $query->paginate($perPage);
            $items = $paginated->items();
        }

        $base = SmkiInsidenReport::query();
        if (! $this->isAdmin()) {
            $base->where('smki_insiden_reports.user_id', auth()->id());
        }

        return response()->json([
            'success' => true,
            'data' => $items,
            'stats' => [
                'total' => (clone $base)->count(),
                'menunggu' => (clone $base)->where('status_laporan', 'Menunggu Pemilik Sistem')->count(),
                'dalam_penanganan' => (clone $base)->where('status_laporan', 'Dalam Penanganan')->count(),
                'selesai' => (clone $base)->where('status_laporan', 'Selesai')->count(),
            ],
            'meta' => $paginated ? [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ] : ['total' => count($items)],
        ]);
    }

    /**
     * Master statis untuk dropdown + default header dokumen F16-SMKI.
     * Tanpa query tabel master agar tidak 500 saat tabel belum ada.
     */
    public function lookup()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'kategori' => SmkiInsidenReport::KATEGORI,
                'jenis_sistem' => SmkiInsidenReport::JENIS_SISTEM,
                'jenis_layanan' => SmkiInsidenReport::JENIS_LAYANAN,
                'status' => SmkiInsidenReport::STATUS,
                'no_dokumen' => 'F16-SMKI',
                'no_revisi' => '1.0',
                'tanggal_berlaku' => '14 Februari 2022',
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $validated['user_id'] = auth()->id();
        $validated['bidang_id'] = auth()->user()?->bidang_id;
        // Status awal selalu default; abaikan kiriman non-admin.
        unset($validated['status_laporan']);

        if ($request->hasFile('bukti_pelapor')) {
            $file = $request->file('bukti_pelapor');
            $validated['bukti_pelapor'] = $file->store('insiden_bukti', 'public');
            $validated['bukti_pelapor_nama'] = $file->getClientOriginalName();
        }

        if ($request->hasFile('bukti_pemilik')) {
            $file = $request->file('bukti_pemilik');
            $validated['bukti_pemilik'] = $file->store('insiden_bukti', 'public');
            $validated['bukti_pemilik_nama'] = $file->getClientOriginalName();
        }

        $item = SmkiInsidenReport::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Laporan insiden berhasil disimpan.',
            'data' => $item->load('bidang:id,name,code'),
        ], 201);
    }

    public function show($id)
    {
        $item = $this->findForUser($id);

        return response()->json(['success' => true, 'data' => $item]);
    }

    public function update(Request $request, $id)
    {
        $item = $this->findForUser($id);
        $validated = $this->validatePayload($request, true);

        // Status hanya diproses untuk admin; kiriman non-admin diabaikan.
        if (! $this->isAdmin()) {
            unset($validated['status_laporan']);
        }

        foreach (['bukti_pelapor', 'bukti_pemilik'] as $field) {
            $hapusKey = 'hapus_' . $field;
            if ($request->boolean($hapusKey)) {
                if ($item->{$field} && Storage::disk('public')->exists($item->{$field})) {
                    Storage::disk('public')->delete($item->{$field});
                }
                $validated[$field] = null;
                $validated[$field . '_nama'] = null;
            }

            if ($request->hasFile($field)) {
                if ($item->{$field} && Storage::disk('public')->exists($item->{$field})) {
                    Storage::disk('public')->delete($item->{$field});
                }
                $file = $request->file($field);
                $validated[$field] = $file->store('insiden_bukti', 'public');
                $validated[$field . '_nama'] = $file->getClientOriginalName();
            }

            unset($validated[$hapusKey]);
        }

        $item->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Laporan insiden berhasil diperbarui.',
            'data' => $item->load('bidang:id,name,code'),
        ]);
    }

    /**
     * Hapus laporan (soft delete). Khusus admin sesuai FR-016.
     * File bukti ikut dihapus agar tidak yatim di storage.
     */
    public function destroy($id)
    {
        if (! $this->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat menghapus laporan insiden.',
            ], 403);
        }

        $item = SmkiInsidenReport::findOrFail($id);

        foreach (['bukti_pelapor', 'bukti_pemilik'] as $field) {
            if ($item->{$field} && Storage::disk('public')->exists($item->{$field})) {
                Storage::disk('public')->delete($item->{$field});
            }
        }
        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Laporan insiden berhasil dihapus.',
        ]);
    }

    /**
     * Unduh file bukti via API (CORS-friendly, attachment + nama asli).
     * GET /api/smki/pelaporan-insiden/{id}/unduh?tipe=pelapor|pemilik
     */
    public function downloadBukti(Request $request, $id)
    {
        $validated = $request->validate([
            'tipe' => 'nullable|in:pelapor,pemilik',
        ]);

        $tipe = $validated['tipe'] ?? 'pelapor';
        $item = $this->findForUser($id);

        $pathKey = $tipe === 'pemilik' ? 'bukti_pemilik' : 'bukti_pelapor';
        $namaKey = $pathKey . '_nama';

        $path = $item->{$pathKey};
        if (empty($path) || ! Storage::disk('public')->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'File bukti tidak ditemukan.',
            ], 404);
        }

        $nama = $item->{$namaKey} ?: basename($path);

        return Storage::disk('public')->download($path, $nama);
    }

    /**
     * Ekspor satu laporan insiden ke dokumen FR-016 (.docx).
     * Id wajib: template adalah satu formulir per insiden.
     */
    public function exportDocx(Request $request, $id = null)
    {
        $targetId = $id ?: $request->input('id');
        if (!$targetId) {
            return response()->json([
                'success' => false,
                'message' => 'Pilih laporan insiden yang akan diekspor.',
            ], 404);
        }

        $item = $this->findForUser($targetId);

        $options = [
            'no_dokumen' => $request->input('no_dokumen', 'F16-SMKI'),
            'no_revisi' => $request->input('no_revisi', '1.0'),
            'tanggal_berlaku' => $request->input('tanggal_berlaku', '14 Februari 2022'),
        ];

        try {
            $filePath = $this->docxService->generateDocx($item, $options);
            $filename = 'FR016_Laporan_Insiden_' . $item->id . '_' . date('Ymd') . '.docx';

            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghasilkan dokumen FR-016: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        if ($isUpdate) {
            // Selaras validasi tab frontend: identitas + deskripsi wajib saat melengkapi.
            return $request->validate([
                'kategori_insiden' => ['sometimes', Rule::in(SmkiInsidenReport::KATEGORI)],
                'kategori_lainnya' => 'nullable|string|max:255',
                'jenis_layanan' => ['sometimes', Rule::in(SmkiInsidenReport::JENIS_LAYANAN)],
                'tanggal_laporan' => 'sometimes|date',
                'nama_pelapor' => 'sometimes|string|max:255',
                'jabatan_pelapor' => 'nullable|string|max:255',
                'instansi_pelapor' => 'sometimes|string|max:255',
                'nip_pelapor' => 'nullable|string|max:100',
                'no_hp_pelapor' => 'nullable|string|max:50',
                'email_pelapor' => 'sometimes|email|max:255',
                'waktu_kejadian' => 'sometimes|date',
                'nama_sistem' => 'sometimes|string|max:255',
                'jenis_sistem' => ['nullable', Rule::in(SmkiInsidenReport::JENIS_SISTEM)],
                'url' => 'nullable|string|max:255',
                'ip_address' => 'nullable|string|max:100',
                'os' => 'nullable|string|max:255',
                'web_server' => 'nullable|string|max:255',
                'bukti_pelapor' => 'nullable|file|mimes:png,jpg,jpeg,pdf,docx|max:5120',
                'nama_instansi_pemilik' => 'required|string|max:255',
                'alamat_instansi' => 'nullable|string',
                'telp_fax_instansi' => 'nullable|string|max:100',
                'email_instansi' => 'nullable|email|max:255',
                'nama_pic' => 'required|string|max:255',
                'jabatan_pic' => 'nullable|string|max:255',
                'nip_pic' => 'nullable|string|max:100',
                'no_hp_pic' => 'nullable|string|max:50',
                'email_pic' => 'nullable|email|max:255',
                'lokasi_insiden' => 'nullable|string|max:255',
                'deskripsi_kejadian' => 'required|string',
                'isp' => 'nullable|string|max:255',
                'is_downtime' => 'nullable|boolean',
                'durasi_downtime' => 'nullable|string|max:255',
                'tindakan_awal' => 'nullable|string',
                'bukti_pemilik' => 'nullable|file|mimes:png,jpg,jpeg,pdf,docx|max:5120',
                'status_laporan' => ['nullable', Rule::in(SmkiInsidenReport::STATUS)],
                'hapus_bukti_pelapor' => 'nullable|boolean',
                'hapus_bukti_pemilik' => 'nullable|boolean',
            ], [
                'nama_instansi_pemilik.required' => 'Nama instansi wajib diisi.',
                'nama_pic.required' => 'Nama PIC wajib diisi.',
                'deskripsi_kejadian.required' => 'Deskripsi kejadian wajib diisi.',
            ]);
        }

        return $request->validate([
            'kategori_insiden' => ['required', Rule::in(SmkiInsidenReport::KATEGORI)],
            'kategori_lainnya' => 'nullable|string|max:255',
            'jenis_layanan' => ['required', Rule::in(SmkiInsidenReport::JENIS_LAYANAN)],
            'tanggal_laporan' => 'required|date',
            'nama_pelapor' => 'required|string|max:255',
            'jabatan_pelapor' => 'nullable|string|max:255',
            'instansi_pelapor' => 'required|string|max:255',
            'nip_pelapor' => 'nullable|string|max:100',
            'no_hp_pelapor' => 'nullable|string|max:50',
            'email_pelapor' => 'required|email|max:255',
            'waktu_kejadian' => 'required|date',
            'nama_sistem' => 'required|string|max:255',
            'jenis_sistem' => ['nullable', Rule::in(SmkiInsidenReport::JENIS_SISTEM)],
            'url' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:100',
            'os' => 'nullable|string|max:255',
            'web_server' => 'nullable|string|max:255',
            'bukti_pelapor' => 'nullable|file|mimes:png,jpg,jpeg,pdf,docx|max:5120',
            'nama_instansi_pemilik' => 'nullable|string|max:255',
            'alamat_instansi' => 'nullable|string',
            'telp_fax_instansi' => 'nullable|string|max:100',
            'email_instansi' => 'nullable|email|max:255',
            'nama_pic' => 'nullable|string|max:255',
            'jabatan_pic' => 'nullable|string|max:255',
            'nip_pic' => 'nullable|string|max:100',
            'no_hp_pic' => 'nullable|string|max:50',
            'email_pic' => 'nullable|email|max:255',
            'lokasi_insiden' => 'nullable|string|max:255',
            'deskripsi_kejadian' => 'nullable|string',
            'isp' => 'nullable|string|max:255',
            'is_downtime' => 'nullable|boolean',
            'durasi_downtime' => 'nullable|string|max:255',
            'tindakan_awal' => 'nullable|string',
            'bukti_pemilik' => 'nullable|file|mimes:png,jpg,jpeg,pdf,docx|max:5120',
            'hapus_bukti_pelapor' => 'nullable|boolean',
            'hapus_bukti_pemilik' => 'nullable|boolean',
        ], [
            'kategori_insiden.required' => 'Kategori insiden wajib dipilih.',
            'nama_pelapor.required' => 'Nama pelapor wajib diisi.',
            'nama_sistem.required' => 'Nama sistem wajib diisi.',
            'bukti_pelapor.max' => 'Ukuran bukti maksimal 5MB.',
            'bukti_pemilik.max' => 'Ukuran bukti maksimal 5MB.',
        ]);
    }
}
