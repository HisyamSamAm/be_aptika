<?php

namespace Tests\Feature\Smki;

use App\Models\Bidang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsidenReportTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeader(): array
    {
        $bidang = Bidang::factory()->create(['code' => 'APTIKA']);
        $user = User::factory()->create(['role' => 'admin', 'bidang_id' => $bidang->id]);

        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function userHeader(): array
    {
        $bidang = Bidang::factory()->create();
        $user = User::factory()->create(['bidang_id' => $bidang->id]);

        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function payload(): array
    {
        return [
            'kategori_insiden' => 'phishing',
            'jenis_layanan' => 'Reaktif',
            'tanggal_laporan' => '2026-10-08',
            'nama_pelapor' => 'Nadia Laras',
            'instansi_pelapor' => 'Dinas Contoh',
            'email_pelapor' => 'nadia@example.org',
            'waktu_kejadian' => '2026-10-08 09:20:00',
            'nama_sistem' => 'Email Instansi Contoh',
        ];
    }

    public function test_lookup_returns_static_masters(): void
    {
        $res = $this->getJson('/api/smki/pelaporan-insiden/lookup', $this->userHeader());

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.no_dokumen', 'F16-SMKI')
            ->assertJsonCount(9, 'data.kategori');
    }

    public function test_store_requires_mandatory_fields(): void
    {
        $res = $this->postJson('/api/smki/pelaporan-insiden', [], $this->userHeader());

        $res->assertStatus(422);
    }

    public function test_full_crud_flow(): void
    {
        $headers = $this->adminHeader();

        $created = $this->postJson('/api/smki/pelaporan-insiden', $this->payload(), $headers)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->json('data');

        $this->getJson('/api/smki/pelaporan-insiden?search=Email', $headers)->assertOk();
        $this->getJson('/api/smki/pelaporan-insiden/' . $created['id'], $headers)->assertOk();

        $this->postJson('/api/smki/pelaporan-insiden/' . $created['id'], array_merge(
            ['_method' => 'PUT'],
            [
                'nama_instansi_pemilik' => 'Dinas Contoh',
                'nama_pic' => 'Dimas',
                'deskripsi_kejadian' => 'Investigasi log akses.',
                'status_laporan' => 'Dalam Penanganan',
            ]
        ), $headers)->assertOk()->assertJsonPath('data.status_laporan', 'Dalam Penanganan');

        $this->deleteJson('/api/smki/pelaporan-insiden/' . $created['id'], [], $headers)->assertOk();
        $this->assertSoftDeleted('smki_insiden_reports', ['id' => $created['id']]);
    }

    public function test_non_admin_status_is_ignored(): void
    {
        $user = $this->userHeader();

        $created = $this->postJson('/api/smki/pelaporan-insiden', $this->payload(), $user)
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/smki/pelaporan-insiden/' . $created['id'], array_merge(
            ['_method' => 'PUT'],
            [
                'nama_instansi_pemilik' => 'Dinas Contoh',
                'nama_pic' => 'Dimas',
                'deskripsi_kejadian' => 'Update user biasa.',
                'status_laporan' => 'Selesai',
            ]
        ), $user)->assertOk()->assertJsonPath('data.status_laporan', 'Menunggu Pemilik Sistem');
    }

    public function test_non_admin_only_sees_own_reports(): void
    {
        $admin = $this->adminHeader();
        $user = $this->userHeader();

        $this->postJson('/api/smki/pelaporan-insiden', $this->payload(), $admin)->assertCreated();
        $mine = $this->postJson('/api/smki/pelaporan-insiden', $this->payload(), $user)
            ->assertCreated()->json('data');

        $list = $this->getJson('/api/smki/pelaporan-insiden', $user)->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertEquals($mine['id'], $list[0]['id']);

        // Milik orang lain: 404 semua.
        $otherId = $this->postJson('/api/smki/pelaporan-insiden', $this->payload(), $admin)
            ->assertCreated()->json('data.id');
        $this->getJson('/api/smki/pelaporan-insiden/' . $otherId, $user)->assertNotFound();
        $this->postJson('/api/smki/pelaporan-insiden/' . $otherId, array_merge(
            ['_method' => 'PUT'],
            [
                'nama_instansi_pemilik' => 'X',
                'nama_pic' => 'Y',
                'deskripsi_kejadian' => 'Z',
            ]
        ), $user)->assertNotFound();
        $this->getJson('/api/smki/pelaporan-insiden/' . $otherId . '/unduh', $user)->assertNotFound();
        $this->getJson('/api/smki/pelaporan-insiden/' . $otherId . '/export-docx', $user)->assertNotFound();
    }

    public function test_non_admin_cannot_delete(): void
    {
        $admin = $this->adminHeader();
        $user = $this->userHeader();

        $created = $this->postJson('/api/smki/pelaporan-insiden', $this->payload(), $user)
            ->assertCreated()->json('data');

        $this->deleteJson('/api/smki/pelaporan-insiden/' . $created['id'], [], $user)
            ->assertForbidden()
            ->assertJsonPath('success', false);

        // Admin boleh hapus milik siapa saja.
        $this->deleteJson('/api/smki/pelaporan-insiden/' . $created['id'], [], $admin)->assertOk();
        $this->assertSoftDeleted('smki_insiden_reports', ['id' => $created['id']]);
    }

    public function test_admin_delete_removes_files(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $headers = $this->adminHeader();

        $file = \Illuminate\Http\UploadedFile::fake()->image('bukti.png');

        $created = $this->postJson('/api/smki/pelaporan-insiden', array_merge(
            $this->payload(),
            ['bukti_pelapor' => $file]
        ), $headers)->assertCreated()->json('data');

        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($created['bukti_pelapor']);

        $this->deleteJson('/api/smki/pelaporan-insiden/' . $created['id'], [], $headers)->assertOk();

        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($created['bukti_pelapor']);
    }

    public function test_guest_cannot_access(): void
    {
        $this->getJson('/api/smki/pelaporan-insiden')->assertUnauthorized();
    }

    public function test_export_requires_id(): void
    {
        $this->getJson('/api/smki/pelaporan-insiden/export-docx', $this->adminHeader())
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_export_single_returns_docx(): void
    {
        $headers = $this->adminHeader();

        $created = $this->postJson('/api/smki/pelaporan-insiden', array_merge(
            $this->payload(),
            [
                'nama_pic' => 'Dimas',
                'deskripsi_kejadian' => 'Halaman berubah.',
            ]
        ), $headers)->assertCreated()->json('data');

        // Lengkapi Bagian II agar dokumen terisi penuh.
        $this->postJson('/api/smki/pelaporan-insiden/' . $created['id'], array_merge(
            ['_method' => 'PUT'],
            [
                'nama_instansi_pemilik' => 'Dinas Contoh',
                'nama_pic' => 'Dimas',
                'deskripsi_kejadian' => 'Halaman berubah.',
            ]
        ), $headers)->assertOk();

        $res = $this->get('/api/smki/pelaporan-insiden/' . $created['id'] . '/export-docx', $headers);
        $res->assertOk();
        $this->assertStringContainsString(
            'wordprocessingml.document',
            $res->headers->get('content-type') ?? ''
        );
    }

    public function test_export_embeds_uploaded_screenshot(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $headers = $this->adminHeader();

        $file = \Illuminate\Http\UploadedFile::fake()->image('bukti.png', 800, 600);

        $created = $this->postJson('/api/smki/pelaporan-insiden', array_merge(
            $this->payload(),
            ['bukti_pelapor' => $file]
        ), $headers)->assertCreated()->json('data');

        $res = $this->get('/api/smki/pelaporan-insiden/' . $created['id'] . '/export-docx', $headers);
        $res->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'fr016') . '.docx';
        file_put_contents($tmp, $res->getContent());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($tmp);

        $media = array_values(array_filter($names, fn ($n) => str_starts_with($n, 'word/media/fr016_')));
        $this->assertNotEmpty($media, 'Screenshot tidak ter-embed ke word/media');
    }

    public function test_guest_cannot_export(): void
    {
        $this->getJson('/api/smki/pelaporan-insiden/1/export-docx')->assertUnauthorized();
    }

    public function test_download_missing_file_returns_404(): void
    {
        $headers = $this->adminHeader();

        $created = $this->postJson('/api/smki/pelaporan-insiden', $this->payload(), $headers)
            ->assertCreated()
            ->json('data');

        $this->getJson('/api/smki/pelaporan-insiden/' . $created['id'] . '/unduh?tipe=pelapor', $headers)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_download_invalid_tipe_returns_422(): void
    {
        $headers = $this->adminHeader();

        $created = $this->postJson('/api/smki/pelaporan-insiden', $this->payload(), $headers)
            ->assertCreated()
            ->json('data');

        $this->getJson('/api/smki/pelaporan-insiden/' . $created['id'] . '/unduh?tipe=salah', $headers)
            ->assertStatus(422);
    }

    public function test_download_stored_file_returns_attachment(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $headers = $this->adminHeader();

        $file = \Illuminate\Http\UploadedFile::fake()->image('bukti.png');

        $created = $this->postJson('/api/smki/pelaporan-insiden', array_merge(
            $this->payload(),
            ['bukti_pelapor' => $file]
        ), $headers)->assertCreated()->json('data');

        $res = $this->get('/api/smki/pelaporan-insiden/' . $created['id'] . '/unduh?tipe=pelapor', $headers);
        $res->assertOk();
        $this->assertStringContainsString(
            'attachment',
            $res->headers->get('content-disposition') ?? ''
        );
    }

    public function test_guest_cannot_download(): void
    {
        $this->getJson('/api/smki/pelaporan-insiden/1/unduh')->assertUnauthorized();
    }
}
