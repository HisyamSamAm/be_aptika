<?php

namespace App\Services;

use App\Models\LaporanAuditTahunan;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use DOMElement;
use DOMNode;
use Exception;
use ZipArchive;

class SmkiLaporanAuditTahunanDocxService
{
    /**
     * Dapatkan path template FR-008
     */
    public static function getTemplatePath(): string
    {
        $possiblePaths = [
            storage_path('app/templates/template_fr_008.docx'),
            base_path('../FR-008-SMKI Formulir Program Tahunan Audit Internal (1).docx'),
            resource_path('templates/FR-008-SMKI Formulir Program Tahunan Audit Internal (1).docx'),
        ];

        foreach ($possiblePaths as $p) {
            if (file_exists($p)) {
                return $p;
            }
        }

        throw new Exception('Template dokumen FR-008 tidak ditemukan.');
    }

    /**
     * Generate DOCX rapi sesuai standar formulir SMKI & identik dengan template asli
     */
    public function generateDocx(LaporanAuditTahunan $laporan): string
    {
        $templatePath = self::getTemplatePath();

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $safeNum = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $laporan->tahun_smki ?: '2026');
        $outputPath = $tempDir . '/FR-008_' . $safeNum . '_' . uniqid() . '.docx';

        if (!copy($templatePath, $outputPath)) {
            throw new Exception('Gagal menyalin template FR-008.');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            throw new Exception('Gagal membuka file DOCX.');
        }

        $xml = $zip->getFromName('word/document.xml');
        if (!$xml) {
            $zip->close();
            throw new Exception('Format dokumen tidak valid: word/document.xml tidak ditemukan.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        @$dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // CATATAN PENTING: Margin asli template TIDAK diubah agar layout & lebar tabel 100% presisi.

        // Siapkan data Lead Auditor dan Auditor
        $leadAuditor = $laporan->timAuditor->first(function ($t) {
            return strcasecmp(trim($t->peran), 'Lead Auditor') === 0;
        });
        $leadAuditorStr = $leadAuditor 
            ? $leadAuditor->nama_auditor . ($leadAuditor->unit_kerja ? " (" . $leadAuditor->unit_kerja . ")" : "")
            : "-";

        $anggotaAuditors = $laporan->timAuditor->filter(function ($t) {
            return strcasecmp(trim($t->peran), 'Lead Auditor') !== 0;
        });
        $anggotaList = [];
        foreach ($anggotaAuditors as $aud) {
            $anggotaList[] = $aud->nama_auditor . ($aud->unit_kerja ? " (" . $aud->unit_kerja . ")" : "");
        }
        $anggotaStr = !empty($anggotaList) ? implode(", ", $anggotaList) : "-";

        // Format tanggal berlaku
        Carbon::setLocale('id');
        $tglBerlaku = $laporan->tanggal_berlaku 
            ? Carbon::parse($laporan->tanggal_berlaku)->translatedFormat('d F Y')
            : '-';

        $tables = $xpath->query('//w:tbl');

        // -------------------------------------------------------------
        // TABLE 0: Header Kop Dokumen (No. Dokumen, No. Revisi, Tgl Berlaku)
        // -------------------------------------------------------------
        if ($tables->length >= 1) {
            $table0 = $tables->item(0);
            $rows0 = $xpath->query('w:tr', $table0);

            if ($rows0->length > 0) {
                $this->replaceCellTextPreserveFormatting($xpath, $dom, $rows0->item(0), 4, $laporan->no_dokumen ?: 'F08-SMKI');
            }
            if ($rows0->length > 1) {
                $this->replaceCellTextPreserveFormatting($xpath, $dom, $rows0->item(1), 4, $laporan->no_revisi ?: '1.0');
            }
            if ($rows0->length > 2) {
                $this->replaceCellTextPreserveFormatting($xpath, $dom, $rows0->item(2), 4, $tglBerlaku);
            }
        }

        // -------------------------------------------------------------
        // TABLE 1: Tabel Isi Utama Program Tahunan Audit Internal
        // -------------------------------------------------------------
        if ($tables->length >= 2) {
            $table1 = $tables->item(1);
            $rows1 = $xpath->query('./w:tr', $table1);
            $totalRows = $rows1->length;

            // Row 0, Cell 1: Tahun
            if ($totalRows > 0) {
                $this->replaceCellTextPreserveFormatting($xpath, $dom, $rows1->item(0), 1, "Tahun: " . ($laporan->tahun_smki ?: '2026'));
            }

            // Row 1, Cell 0: Kriteria Audit
            if ($totalRows > 1) {
                $this->replaceCellTextPreserveFormatting($xpath, $dom, $rows1->item(1), 0, "Kriteria Audit: " . ($laporan->kriteria_audit ?: 'ISO/IEC 27001:2013'));
            }

            // Row 2, Cell 0: Tujuan
            if ($totalRows > 2) {
                $this->replaceCellTextPreserveFormatting($xpath, $dom, $rows1->item(2), 0, "Tujuan: " . ($laporan->tujuan ?: '-'));
            }

            // Row 4, Cell 0: Tim Auditor (Lead Auditor & Anggota)
            if ($totalRows > 4) {
                $this->fillTimAuditorCell($xpath, $dom, $rows1->item(4), $leadAuditorStr, $anggotaStr);
            }

            // Template Baris Data Objek Audit (Row 6)
            $sampleRow = ($totalRows > 6) ? $rows1->item(6) : null;
            $sampleRowClone = $sampleRow ? $sampleRow->cloneNode(true) : null;

            // Hapus baris dummy lama (Row 6 sampai baris sebelum Tanda Tangan: Row 14)
            // Di template asli: Row 0-5 adalah header, Row 6-14 adalah dummy data (9 rows), Row 15 & 16 adalah TTD
            $endDummyIndex = $totalRows - 3; // index 14
            for ($i = $endDummyIndex; $i >= 6; $i--) {
                if (isset($rows1[$i])) {
                    $table1->removeChild($rows1[$i]);
                }
            }

            // Re-query rows setelah remove dummy rows
            $currentRows = $xpath->query('./w:tr', $table1);
            $currentTotal = $currentRows->length;
            $ttdBeforeNode = $currentRows->item($currentTotal - 2); // Row 'Disahkan Oleh:'

            // Isi Data Pengesah & Penyusun di baris TTD
            if ($currentTotal >= 2) {
                $ttdLabelRow = $currentRows->item($currentTotal - 2);
                $ttdNameRow = $currentRows->item($currentTotal - 1);

                $labelPengesah = "Disahkan Oleh: " . ($laporan->jabatan_pengesah ? "(" . $laporan->jabatan_pengesah . ")" : "");
                $labelPenyusun = "Disusun Oleh: " . ($laporan->jabatan_penyusun ? "(" . $laporan->jabatan_penyusun . ")" : "");

                $namaPengesah = "(" . ($laporan->disahkan_oleh ?: "…………………………………….") . ")";
                $namaPenyusun = "(" . ($laporan->disusun_oleh ?: "…………………………………….") . ")";

                $this->replaceCellTextPreserveFormatting($xpath, $dom, $ttdLabelRow, 0, $labelPengesah);
                $this->replaceCellTextPreserveFormatting($xpath, $dom, $ttdLabelRow, 1, $labelPenyusun);

                $this->replaceCellTextPreserveFormatting($xpath, $dom, $ttdNameRow, 0, $namaPengesah);
                $this->replaceCellTextPreserveFormatting($xpath, $dom, $ttdNameRow, 1, $namaPenyusun);
            }

            // Sisipkan baris-baris Objek Audit yang sebenarnya
            if ($sampleRowClone && $laporan->objekAudit->count() > 0) {
                $nomor = 1;
                foreach ($laporan->objekAudit as $objek) {
                    $newRow = $sampleRowClone->cloneNode(true);
                    $cells = $xpath->query('./w:tc', $newRow);

                    // Cell 0: No
                    $this->fillCleanCell($xpath, $dom, $cells->item(0), (string)$nomor, 'center');

                    // Cell 1: Obyek Audit
                    $objekText = $objek->kode_objek . " - " . $objek->objek_audit;
                    if ($objek->unit_kerja && $objek->unit_kerja !== '-') {
                        $objekText .= "\nUnit: " . $objek->unit_kerja;
                    }
                    if ($objek->ruang_lingkup && $objek->ruang_lingkup !== '-') {
                        $objekText .= "\nLingkup: " . $objek->ruang_lingkup;
                    }
                    $this->fillCleanCell($xpath, $dom, $cells->item(1), $objekText, 'left');

                    // Cell 2: Klausul
                    $klausulList = $objek->klausul->pluck('klausul_annex')->toArray();
                    $klausulText = !empty($klausulList) ? implode("\n", $klausulList) : '-';
                    $this->fillCleanCell($xpath, $dom, $cells->item(2), $klausulText, 'left');

                    // Cell 3 s/d 14: Bulan Jan s/d Des
                    $bulanAktif = $objek->jadwal->pluck('bulan')->toArray();
                    for ($m = 1; $m <= 12; $m++) {
                        $cellMonth = $cells->item(2 + $m);
                        if ($cellMonth) {
                            $simbol = in_array($m, $bulanAktif) ? "V" : "";
                            $this->fillCleanCell($xpath, $dom, $cellMonth, $simbol, 'center', true);
                        }
                    }

                    $table1->insertBefore($newRow, $ttdBeforeNode);
                    $nomor++;
                }
            }
        }

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $outputPath;
    }

    /**
     * Mengisi sel Row 4 (Lead Auditor & Anggota) dengan mempertahankan layout indent & tab template asli
     */
    private function fillTimAuditorCell(DOMXPath $xpath, DOMDocument $dom, DOMElement $row, string $leadStr, string $anggotaStr): void
    {
        $cells = $xpath->query('./w:tc', $row);
        if ($cells->length === 0) return;

        $cell = $cells->item(0);
        $paragraphs = $xpath->query('./w:p', $cell);

        // Di template, p[0] adalah "Lead Auditor:", p[1] adalah "Auditor:"
        if ($paragraphs->length >= 2) {
            $this->replaceParagraphRunText($xpath, $dom, $paragraphs->item(0), "Lead Auditor:\t" . $leadStr);
            $this->replaceParagraphRunText($xpath, $dom, $paragraphs->item(1), "Auditor:\t" . $anggotaStr);
        } else {
            // Fallback jika paragraf berbeda
            $this->fillCleanCell($xpath, $dom, $cell, "Lead Auditor: " . $leadStr . "\nAuditor: " . $anggotaStr, 'left');
        }
    }

    /**
     * Ganti isi teks pada sel dengan mempertahankan formatting run & paragraph yang sudah ada di template
     */
    private function replaceCellTextPreserveFormatting(
        DOMXPath $xpath,
        DOMDocument $dom,
        DOMElement $row,
        int $cellIndex,
        string $newText
    ): void {
        $cells = $xpath->query('./w:tc', $row);
        if ($cellIndex >= $cells->length) return;

        $cell = $cells->item($cellIndex);
        $paras = $xpath->query('./w:p', $cell);
        if ($paras->length > 0) {
            // Ganti di paragraf pertama/terakhir yang memiliki run
            $targetP = null;
            for ($i = $paras->length - 1; $i >= 0; $i--) {
                $runs = $xpath->query('.//w:t', $paras->item($i));
                if ($runs->length > 0) {
                    $targetP = $paras->item($i);
                    break;
                }
            }
            if (!$targetP) {
                $targetP = $paras->item(0);
            }
            $this->replaceParagraphRunText($xpath, $dom, $targetP, $newText);
        }
    }

    /**
     * Ganti isi run teks pada satu paragraf
     */
    private function replaceParagraphRunText(DOMXPath $xpath, DOMDocument $dom, DOMElement $p, string $text): void
    {
        $tNodes = $xpath->query('.//w:t', $p);
        if ($tNodes->length > 0) {
            // Tulis text ke w:t pertama, kosongkan sisanya
            $tNodes->item(0)->textContent = $text;
            if ($tNodes->item(0) instanceof DOMElement) {
                $tNodes->item(0)->setAttribute('xml:space', 'preserve');
            }
            for ($i = 1; $i < $tNodes->length; $i++) {
                $tNodes->item($i)->textContent = '';
            }
        } else {
            // Tambahkan run dengan font Arial 18 (9pt)
            $r = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
            $rPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rPr');
            $rFonts = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rFonts');
            $rFonts->setAttribute('w:ascii', 'Arial');
            $rFonts->setAttribute('w:hAnsi', 'Arial');
            $rFonts->setAttribute('w:cs', 'Arial');
            $rPr->appendChild($rFonts);
            $sz = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:sz');
            $sz->setAttribute('w:val', '18');
            $rPr->appendChild($sz);
            $r->appendChild($rPr);

            $t = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
            $t->setAttribute('xml:space', 'preserve');
            $t->textContent = $text;
            $r->appendChild($t);
            $p->appendChild($r);
        }
    }

    /**
     * Mengisi sel data tabel secara bersih dengan font Arial 9pt / 10pt sesuai standar template asli
     */
    private function fillCleanCell(
        DOMXPath $xpath,
        DOMDocument $dom,
        ?DOMElement $cell,
        string $text,
        string $align = 'left',
        bool $isBold = false
    ): void {
        if (!$cell) return;

        // Kosongkan isi paragraf lama
        $paras = $xpath->query('./w:p', $cell);
        foreach ($paras as $p) {
            $cell->removeChild($p);
        }

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $p = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:p');
            $pPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pPr');

            if ($align !== 'left') {
                $jc = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:jc');
                $jc->setAttribute('w:val', $align);
                $pPr->appendChild($jc);
            }

            // Spacing kompak agar pas di tabel
            $spacing = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:spacing');
            $spacing->setAttribute('w:before', '20');
            $spacing->setAttribute('w:after', '20');
            $spacing->setAttribute('w:line', '240');
            $spacing->setAttribute('w:lineRule', 'auto');
            $pPr->appendChild($spacing);

            $p->appendChild($pPr);

            $r = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
            $rPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rPr');

            $rFonts = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rFonts');
            $rFonts->setAttribute('w:ascii', 'Arial');
            $rFonts->setAttribute('w:hAnsi', 'Arial');
            $rFonts->setAttribute('w:cs', 'Arial');
            $rPr->appendChild($rFonts);

            $sz = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:sz');
            $sz->setAttribute('w:val', '18'); // 9 pt
            $rPr->appendChild($sz);

            if ($isBold) {
                $b = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:b');
                $rPr->appendChild($b);
            }

            $r->appendChild($rPr);

            $t = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
            $t->setAttribute('xml:space', 'preserve');
            $t->textContent = $line;
            $r->appendChild($t);

            $p->appendChild($r);
            $cell->appendChild($p);
        }
    }
}
