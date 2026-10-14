<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Exception;
use ZipArchive;

class SmkiInsidenReportDocxService
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const CHECKED = '√'; // U+221A, glyph yang dipakai template FR-016

    private const UNCHECKED = '×';

    /** Hitungan kemunculan label per dokumen (di-reset tiap generate). */
    private array $takeCounts = [];

    public static function getTemplatePath(): string
    {
        $possiblePaths = [
            resource_path('templates/FR-016 Formulir Laporan Insiden.docx'),
            base_path('resources/templates/FR-016 Formulir Laporan Insiden.docx'),
            storage_path('app/templates/FR-016 Formulir Laporan Insiden.docx'),
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-016 Formulir Laporan Insiden.docx tidak ditemukan di server.');
    }

    /**
     * Generate DOCX FR-016 terisi untuk satu laporan insiden.
     *
     * @param object|array $item  Model SmkiInsidenReport (atau array sepadan)
     * @param array $options ['no_dokumen', 'no_revisi', 'tanggal_berlaku']
     * @return string Path file temporary docx
     */
    public function generateDocx($item, array $options = []): string
    {
        $templatePath = self::getTemplatePath();

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $outputPath = $tempDir . '/FR-016_generated_' . uniqid() . '.docx';
        if (!copy($templatePath, $outputPath)) {
            throw new Exception('Gagal menyalin file template FR-016.');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            throw new Exception('Gagal membuka file DOCX.');
        }

        $xml = $zip->getFromName('word/document.xml');
        if (!$xml) {
            $zip->close();
            throw new Exception('Format dokumen tidak memiliki word/document.xml.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        @$dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W);

        $this->takeCounts = [];

        $tables = $xpath->query('//w:tbl');
        if ($tables->length < 2) {
            $zip->close();
            throw new Exception('Struktur tabel dalam template FR-016 tidak valid.');
        }

        // Kop dokumen (tabel 0, nilai di sel indeks 4 tiap baris).
        $headerRows = $xpath->query('w:tr', $tables->item(0));
        if (!empty($options['no_dokumen']) && $headerRows->length > 0) {
            $this->setCellText($xpath, $dom, $headerRows->item(0), 4, $options['no_dokumen']);
        }
        if (!empty($options['no_revisi']) && $headerRows->length > 1) {
            $this->setCellText($xpath, $dom, $headerRows->item(1), 4, $options['no_revisi']);
        }
        if (!empty($options['tanggal_berlaku']) && $headerRows->length > 2) {
            $this->setCellText($xpath, $dom, $headerRows->item(2), 4, $options['tanggal_berlaku']);
        }

        $this->fillTable($xpath, $dom, $tables->item(0), $item, 1);
        $this->fillTable($xpath, $dom, $tables->item(1), $item, 2);

        $media = $this->collectMedia($item);
        $this->fillLampiran($xpath, $dom, $media);

        $zip->addFromString('word/document.xml', $dom->saveXML());

        // Sisipkan file gambar ke word/media + relasinya.
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
        $typesXml = $zip->getFromName('[Content_Types].xml');
        foreach ($media as $m) {
            if ($m['kind'] === 'image') {
                $zip->addFromString('word/media/' . $m['name'], file_get_contents($m['path']));
                $relsXml = $this->addImageRel($relsXml, $m);
                $typesXml = $this->ensureImageContentType(
                    $typesXml,
                    strtolower((string) pathinfo($m['name'], PATHINFO_EXTENSION))
                );
            }
        }
        $zip->addFromString('word/_rels/document.xml.rels', $relsXml);
        if ($typesXml) {
            $zip->addFromString('[Content_Types].xml', $typesXml);
        }
        $zip->close();

        return $outputPath;
    }

    /**
     * Kumpulkan lampiran: gambar (embed) vs dokumen lain (tulis nama saja).
     *
     * @return array{tipe:string,kind:string,name:string,path?:string,label:string}[]
     */
    private function collectMedia($item): array
    {
        $out = [];
        foreach (['pelapor' => 'bukti_pelapor', 'pemilik' => 'bukti_pemilik'] as $tipe => $key) {
            $path = $this->text($item, $key);
            $nama = $this->text($item, $key . '_nama') ?: ($path !== '' ? basename($path) : '');
            if ($path === '') {
                continue;
            }
            $full = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
            $ext = strtolower((string) pathinfo($nama !== '' ? $nama : $path, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg'], true) && is_file($full)) {
                $out[] = [
                    'tipe' => $tipe,
                    'kind' => 'image',
                    'name' => 'fr016_' . $tipe . '_' . uniqid() . '.' . $ext,
                    'path' => $full,
                    'label' => $nama,
                ];
            } elseif ($nama !== '') {
                $out[] = ['tipe' => $tipe, 'kind' => 'file', 'name' => $nama, 'label' => $nama];
            }
        }

        return $out;
    }

    /**
     * Isi seksi LAMPIRAN PENANGANAN INCIDENT di akhir body:
     * gambar di-embed + caption, file lain ditulis namanya.
     */
    private function fillLampiran(DOMXPath $xpath, DOMDocument $dom, array &$media): void
    {
        $body = $xpath->query('/w:document/w:body')->item(0);
        if (!$body) {
            return;
        }
        $paras = [];
        foreach ($xpath->query('w:p', $body) as $p) {
            $paras[] = $p;
        }
        $byTipe = ['pelapor' => [], 'pemilik' => []];
        foreach ($media as $m) {
            $byTipe[$m['tipe']][] = $m;
        }
        $section = null; // 'pelapor' | 'pemilik' | null
        $relId = 1000;
        $picId = 2000;
        $count = count($paras);
        for ($i = 0; $i < $count; $i++) {
            $txt = trim($paras[$i]->textContent);
            $low = mb_strtolower($txt);
            if ($low === 'lampiran pelapor') {
                $section = 'pelapor';
                continue;
            }
            if (mb_strpos($low, 'lampiran administrator') !== false) {
                $section = 'pemilik';
                continue;
            }
            if ($section === null || mb_stripos($txt, 'narasi dan evidence') === false) {
                continue;
            }
            $items = $byTipe[$section];
            $parent = $paras[$i]->parentNode;
            if (count($items) === 0) {
                $this->setParaText($xpath, $dom, $paras[$i], '< Belum ada lampiran. >');
                $section = null;
                continue;
            }
            $first = true;
            foreach ($items as $m) {
                if ($m['kind'] === 'image') {
                    $relId++;
                    $picId++;
                    $m['relId'] = 'rIdFr016_' . $relId;
                    $m['picId'] = $picId;
                    $imgPara = $this->buildImagePara($dom, $m);
                    $parent->insertBefore($imgPara, $paras[$i]);
                    $capPara = $this->buildCaptionPara($dom, $m['label']);
                    $parent->insertBefore($capPara, $paras[$i]);
                    // Simpan relId kembali untuk penulisan rels.
                    foreach ($media as &$mm) {
                        if ($mm['name'] === $m['name']) {
                            $mm['relId'] = $m['relId'];
                        }
                    }
                    unset($mm);
                    $first = false;
                } else {
                    $line = ($first ? '< ' : '') . $m['label'];
                    $first = false;
                    $this->setParaText($xpath, $dom, $paras[$i], $line);
                }
            }
            if (!$first) {
                // Hapus placeholder narasi karena sudah diganti gambar/nama file.
                $parent->removeChild($paras[$i]);
            }
            $section = null;
        }
    }

    private function setParaText(DOMXPath $xpath, DOMDocument $dom, $p, string $text): void
    {
        foreach ($xpath->query('w:r/w:t', $p) as $t) {
            $t->nodeValue = '';
        }
        $runs = $xpath->query('w:r', $p);
        if ($runs->length === 0) {
            return;
        }
        $first = $runs->item(0);
        $t = $xpath->query('w:t', $first)->item(0);
        if (!$t) {
            $t = $dom->createElementNS(self::W, 'w:t');
            $first->appendChild($t);
        }
        $t->nodeValue = $text;
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    }

    /**
     * Paragraf gambar inline (maks lebar 14 cm, jaga rasio).
     * Dibangun sebagai string XML agar prefix namespace (wp/a/pic/r)
     * sama persis dengan konvensi Word.
     */
    private function buildImagePara(DOMDocument $dom, array $m)
    {
        $w = self::W;
        $size = @getimagesize($m['path']);
        $maxW = 14.0; // cm
        if ($size && $size[0] > 0) {
            $ratio = $size[1] / $size[0];
            $widthCm = min($maxW, $size[0] / 96 * 2.54);
            $heightCm = $widthCm * $ratio;
        } else {
            $widthCm = $maxW;
            $heightCm = 7.0;
        }
        $cx = (int) round($widthCm / 2.54 * 914400);
        $cy = (int) round($heightCm / 2.54 * 914400);
        $docId = (int) ($m['picId'] ?? 1);
        $picId = (int) ($m['picId'] ?? 1);
        $name = htmlspecialchars(mb_substr($m['label'], 0, 60), ENT_QUOTES | ENT_XML1, 'UTF-8');

        $p = $dom->createElementNS($w, 'w:p');
        $r = $dom->createElementNS($w, 'w:r');
        $p->appendChild($r);
        $drawing = '<w:drawing xmlns:w="' . $w . '"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<wp:inline distT="0" distB="0" distL="114300" distR="114300">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:docPr id="' . $docId . '" name="' . $name . '"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . $picId . '" name="' . $name . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $m['relId'] . '"/><a:srcRect b="0" l="0" r="0" t="0"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
        $frag = $dom->createDocumentFragment();
        if (@$frag->appendXML($drawing)) {
            $r->appendChild($frag);
        }

        return $p;
    }

    private function buildCaptionPara(DOMDocument $dom, string $label)
    {
        $w = self::W;
        $p = $dom->createElementNS($w, 'w:p');
        $pPr = $dom->createElementNS($w, 'w:pPr');
        $jc = $dom->createElementNS($w, 'w:jc');
        $jc->setAttribute('w:val', 'center');
        $pPr->appendChild($jc);
        $p->appendChild($pPr);
        $r = $dom->createElementNS($w, 'w:r');
        $p->appendChild($r);
        $rPr = $dom->createElementNS($w, 'w:rPr');
        $sz = $dom->createElementNS($w, 'w:sz');
        $sz->setAttribute('w:val', '18');
        $rPr->appendChild($sz);
        $color = $dom->createElementNS($w, 'w:color');
        $color->setAttribute('w:val', '808080');
        $rPr->appendChild($color);
        $r->appendChild($rPr);
        $t = $dom->createElementNS($w, 'w:t');
        $t->nodeValue = $label;
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $r->appendChild($t);

        return $p;
    }

    private function addImageRel(string $relsXml, array $m): string
    {
        $needle = '</Relationships>';
        $rel = '<Relationship Id="' . $m['relId'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $m['name'] . '"/>';
        if (mb_strpos($relsXml, $needle) !== false) {
            return str_replace($needle, $rel . $needle, $relsXml);
        }

        return $relsXml;
    }

    private function ensureImageContentType(?string $typesXml, string $ext): ?string
    {
        if (!$typesXml) {
            return $typesXml;
        }
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'][$ext] ?? null;
        if (!$mime || mb_strpos($typesXml, 'Extension="' . $ext . '"') !== false) {
            return $typesXml;
        }

        return str_replace(
            '</Types>',
            '<Default Extension="' . $ext . '" ContentType="' . $mime . '"/></Types>',
            $typesXml
        );
    }

    private function field($item, string $key)
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }

        return $item->{$key} ?? null;
    }

    private function text($item, string $key): string
    {
        $v = $this->field($item, $key);
        if ($v === null || $v === '') {
            return '';
        }

        return (string) $v;
    }

    private function fmtDate($value, string $format = 'd F Y'): string
    {
        if (empty($value)) {
            return '';
        }
        try {
            return date($format, strtotime((string) $value));
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * Isi satu tabel berdasarkan urutan label paragraf.
     * $tableNo 1 = Bagian I (pelapor), 2 = Bagian II (pemilik sistem).
     * Label dan gugus titik berada di paragraf terpisah, sehingga pengisian
     * mencari ke paragraf KLAKSON berikutnya yang masih kosong.
     */
    private function fillTable(DOMXPath $xpath, DOMDocument $dom, $table, $item, int $tableNo): void
    {
        $nodes = $xpath->query('.//w:p', $table);
        $paras = [];
        foreach ($nodes as $p) {
            $paras[] = $p;
        }
        $used = [];
        $consumed = [];

        $take = function (string $key, int $occurrence = 1) use (&$used) {
            $used[$key] = ($used[$key] ?? 0) + 1;

            return $used[$key] === $occurrence;
        };

        $count = count($paras);
        for ($i = 0; $i < $count; $i++) {
            $p = $paras[$i];
            $full = trim($p->textContent);
            if ($full === '') {
                continue;
            }
            $low = mb_strtolower($full);

            // Kategori insiden: tiap nama kategori di paragrafnya sendiri.
            if ($this->isCategoryParagraph($low)) {
                $this->markCategories($xpath, $dom, $p, $this->text($item, 'kategori_insiden'));
                continue;
            }
            if (mb_strpos($low, 'lainnya') !== false && $this->hasDots($p, $xpath)) {
                $this->fillDots($xpath, $p, $this->text($item, 'kategori_lainnya'));
                continue;
            }
            // Jenis layanan [Proaktif] [Reaktif].
            if (mb_strpos($low, 'proaktif') !== false) {
                $this->markBracketOptions($xpath, $p, [
                    'Proaktif' => $this->text($item, 'jenis_layanan') === 'Proaktif',
                    'Reaktif' => $this->text($item, 'jenis_layanan') === 'Reaktif',
                ]);
                continue;
            }
            // Downtime [ya] [tidak] + paragraf durasi terpisah.
            if (mb_strpos($low, '[ya]') !== false) {
                $down = (bool) $this->field($item, 'is_downtime');
                $this->markBracketOptions($xpath, $p, ['ya' => $down, 'tidak' => !$down]);
                continue;
            }
            if (mb_strpos($low, 'durasi waktu') !== false) {
                $this->fillSelfOrNext($xpath, $paras, $i, $consumed, $this->text($item, 'durasi_downtime'));
                continue;
            }

            if ($tableNo === 1) {
                $this->fillBagianI($xpath, $paras, $i, $consumed, $low, $item, $take);
            } else {
                $this->fillBagianII($xpath, $paras, $i, $consumed, $low, $item, $take);
            }
        }
    }

    private function isCategoryParagraph(string $low): bool
    {
        foreach (['web defacement', 'hacking', 'malware detection', 'phishing', 'spoofing', 'brute force', 'denial of service', 'sniffing'] as $name) {
            if (mb_strpos($low, $name) !== false && mb_strpos($low, 'jenis insiden') === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Isi gugus titik di paragraf sendiri, atau paragraf bertitik pertama
     * sesudahnya yang belum dikonsumsi.
     */
    private function fillSelfOrNext(DOMXPath $xpath, array $paras, int $i, array &$consumed, string $value): void
    {
        if ($value === '') {
            return;
        }
        if ($this->hasDots($paras[$i], $xpath) && !isset($consumed[$i])) {
            $this->fillDots($xpath, $paras[$i], $value);
            $consumed[$i] = true;

            return;
        }
        $count = count($paras);
        for ($j = $i + 1; $j < $count && $j <= $i + 4; $j++) {
            if (isset($consumed[$j])) {
                continue;
            }
            if ($this->hasDots($paras[$j], $xpath)) {
                $this->fillDots($xpath, $paras[$j], $value);
                $consumed[$j] = true;

                return;
            }
            // Berhenti jika menabrak label berteks berikutnya.
            $t = trim($paras[$j]->textContent);
            if ($t !== '' && !$this->hasDots($paras[$j], $xpath) && mb_strlen($t) > 3) {
                // Biarkan lanjut maksimal 4 paragraf (label perantara pendek).
            }
        }
    }

    /**
     * Tulis "*Terlampir: ..." pada run Terlampir di paragraf sendiri
     * atau paragraf berikutnya.
     */
    private function fillTerlampirNext(DOMXPath $xpath, array $paras, int $i, string $value): void
    {
        if ($value === '') {
            return;
        }
        $count = count($paras);
        for ($j = $i; $j < $count && $j <= $i + 3; $j++) {
            foreach ($xpath->query('w:r/w:t', $paras[$j]) as $t) {
                if (mb_stripos($t->nodeValue, 'terlampir') !== false) {
                    $t->nodeValue = '*Terlampir: ' . $value;
                    $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');

                    return;
                }
            }
        }
    }

    private function fillBagianI(DOMXPath $xpath, array $paras, int $i, array &$consumed, string $low, $item, callable $take): void
    {
        $p = $paras[$i];
        $fill = function (string $value) use ($xpath, $paras, $i, &$consumed) {
            $this->fillSelfOrNext($xpath, $paras, $i, $consumed, $value);
        };
        if (mb_strpos($low, 'tanggal laporan') !== false && $take('tgl_lapor')) {
            $fill($this->fmtDate($this->field($item, 'tanggal_laporan')));
            return;
        }
        if (mb_strpos($low, 'mulai tanggal dan waktu') !== false && $take('waktu')) {
            $fill($this->fmtDate($this->field($item, 'waktu_kejadian'), 'd F Y, H:i'));
            return;
        }
        if (mb_strpos($low, 'nama sistem') !== false && $take('nama_sistem')) {
            $fill($this->text($item, 'nama_sistem'));
            return;
        }
        if (mb_strpos($low, 'jenis sistem') !== false && $take('jenis_sistem')) {
            $this->markSlashOptions($xpath, $p, ['Server', 'Website', 'PC', 'Network'], $this->text($item, 'jenis_sistem'));
            return;
        }
        if (mb_strpos($low, 'url') !== false && $take('url')) {
            $this->fillUrlDots($xpath, $p, $this->text($item, 'url'));
            return;
        }
        if (mb_strpos($low, 'ip address') !== false && $take('ip')) {
            $fill($this->text($item, 'ip_address'));
            return;
        }
        if (preg_match('/\bos\b/', $low) && $take('os')) {
            $fill($this->text($item, 'os'));
            return;
        }
        if (mb_strpos($low, 'web server') !== false && $take('web')) {
            $fill($this->text($item, 'web_server'));
            return;
        }
        if (mb_strpos($low, 'bukti dari pelapor') !== false && $take('bukti')) {
            $this->fillTerlampirNext($xpath, $paras, $i, $this->text($item, 'bukti_pelapor_nama'));
            return;
        }
        // Blok identitas pelapor ( kemunculan pertama tiap label di tabel 1 ).
        if (mb_strpos($low, 'asal instansi') !== false && $take('instansi')) {
            $fill($this->text($item, 'instansi_pelapor'));
            return;
        }
        if (preg_match('/\bnip\b/', $low) && mb_strpos($low, 'pic') === false && $take('nip')) {
            $fill($this->text($item, 'nip_pelapor'));
            return;
        }
        if (mb_strpos($low, 'no hp') !== false && $take('nohp')) {
            $fill($this->text($item, 'no_hp_pelapor'));
            return;
        }
        if (mb_strpos($low, 'email') !== false && $take('email')) {
            $fill($this->text($item, 'email_pelapor'));
            return;
        }
        if (mb_strpos($low, 'jabatan') !== false && $take('jabatan')) {
            $fill($this->text($item, 'jabatan_pelapor'));
            return;
        }
        if (preg_match('/\bnama\b/', $low)
            && mb_strpos($low, 'sistem') === false
            && mb_strpos($low, 'instansi') === false
            && $take('nama')) {
            $fill($this->text($item, 'nama_pelapor'));
            return;
        }
    }

    private function fillBagianII(DOMXPath $xpath, array $paras, int $i, array &$consumed, string $low, $item, callable $take): void
    {
        $p = $paras[$i];
        $fill = function (string $value) use ($xpath, $paras, $i, &$consumed) {
            $this->fillSelfOrNext($xpath, $paras, $i, $consumed, $value);
        };
        if (mb_strpos($low, 'nama instansi') !== false && $take('instansi')) {
            $fill($this->text($item, 'nama_instansi_pemilik'));
            return;
        }
        if (mb_strpos($low, 'nama sistem') !== false && $take('nama_sistem')) {
            $fill($this->text($item, 'nama_sistem'));
            return;
        }
        if (mb_strpos($low, 'jenis sistem') !== false && $take('jenis_sistem')) {
            $this->markSlashOptions($xpath, $p, ['Server', 'Website', 'PC', 'Network'], $this->text($item, 'jenis_sistem'));
            return;
        }
        if (mb_strpos($low, 'url') !== false && $take('url')) {
            $this->fillUrlDots($xpath, $p, $this->text($item, 'url'));
            return;
        }
        if (mb_strpos($low, 'ip address') !== false && $take('ip')) {
            $fill($this->text($item, 'ip_address'));
            return;
        }
        if (preg_match('/\bos\b/', $low) && $take('os')) {
            $fill($this->text($item, 'os'));
            return;
        }
        if (mb_strpos($low, 'web server') !== false && $take('web')) {
            $fill($this->text($item, 'web_server'));
            return;
        }
        if (mb_strpos($low, 'mulai tanggal dan waktu') !== false && $take('waktu')) {
            $fill($this->fmtDate($this->field($item, 'waktu_kejadian'), 'd F Y, H:i'));
            return;
        }
        if (mb_strpos($low, 'lokasi insiden') !== false && $take('lokasi')) {
            $fill($this->text($item, 'lokasi_insiden'));
            return;
        }
        if (mb_strpos($low, 'deskripsi') !== false && $take('deskripsi')) {
            $desc = $this->text($item, 'deskripsi_kejadian');
            $file = $this->text($item, 'bukti_pemilik_nama') ?: $this->text($item, 'bukti_pelapor_nama');
            $this->fillTerlampirNext($xpath, $paras, $i, $desc !== '' ? $desc . ($file !== '' ? " (Terlampir: {$file})" : '') : $file);
            return;
        }
        if (mb_strpos($low, 'internet services provider') !== false && $take('isp')) {
            $fill($this->text($item, 'isp'));
            return;
        }
        if (mb_strpos($low, 'tindakan teknis') !== false && $take('tindakan')) {
            $fill($this->text($item, 'tindakan_awal'));
            return;
        }
        if (mb_strpos($low, 'alamat') !== false && $take('alamat')) {
            $fill($this->text($item, 'alamat_instansi'));
            return;
        }
        if (mb_strpos($low, 'telp') !== false && $take('telp')) {
            $fill($this->text($item, 'telp_fax_instansi'));
            return;
        }
        // Email instansi (kemunculan 1) vs email PIC (kemunculan 2).
        if (mb_strpos($low, 'email') !== false) {
            $n = $this->takeCount('email');
            if ($n === 1) {
                $fill($this->text($item, 'email_instansi'));
            } elseif ($n === 2) {
                $fill($this->text($item, 'email_pic'));
            }

            return;
        }
        if (mb_strpos($low, 'jabatan') !== false && $take('jabatan')) {
            $fill($this->text($item, 'jabatan_pic'));
            return;
        }
        if (preg_match('/\bnip\b/', $low) && $take('nip')) {
            $fill($this->text($item, 'nip_pic'));
            return;
        }
        if (mb_strpos($low, 'no hp') !== false && $take('nohp')) {
            $fill($this->text($item, 'no_hp_pic'));
            return;
        }
        if (preg_match('/\bnama\b/', $low)
            && mb_strpos($low, 'sistem') === false
            && mb_strpos($low, 'instansi') === false
            && $take('nama')) {
            $fill($this->text($item, 'nama_pic'));
            return;
        }
    }

    private function takeCount(string $key): int
    {
        // Hitungan disimpan di properti agar reset tiap dokumen.
        $this->takeCounts[$key] = ($this->takeCounts[$key] ?? 0) + 1;

        return $this->takeCounts[$key];
    }

    private function hasDots($p, DOMXPath $xpath): bool
    {
        foreach ($xpath->query('w:r/w:t', $p) as $t) {
            if (preg_match('/[….]{3,}/u', $t->nodeValue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ganti gugus titik pertama dalam paragraf dengan nilai.
     * Nilai kosong = biarkan titik (tampil sebagai isian kosong).
     */
    private function fillDots(DOMXPath $xpath, $p, string $value): void
    {
        if ($value === '') {
            return;
        }
        $done = false;
        foreach ($xpath->query('w:r/w:t', $p) as $t) {
            $text = $t->nodeValue;
            if (!$done && preg_match('/[….]{3,}/u', $text)) {
                $t->nodeValue = (string) preg_replace_callback(
                    '/^([\s\S]*?)([….][….…\s.]*)$/u',
                    static fn ($m) => $m[1] . $value,
                    $text,
                    1
                );
                $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $done = true;
            } elseif ($done && preg_match('/^\s*[.…\s]+$/u', $text)) {
                $t->nodeValue = '';
            }
        }
    }

    /**
     * Baris URL berisi "http:// .... / https:// ....".
     * Kosongkan awalan http:// dan tulis nilai ke gugus pertama.
     */
    private function fillUrlDots(DOMXPath $xpath, $p, string $value): void
    {
        if ($value === '') {
            return;
        }
        $clearedHttp = false;
        $done = false;
        foreach ($xpath->query('w:r/w:t', $p) as $t) {
            $text = $t->nodeValue;
            if (!$clearedHttp && trim($text) === 'http://') {
                $t->nodeValue = '';
                $clearedHttp = true;
                continue;
            }
            if (!$done && preg_match('/[….]{3,}/u', $text)) {
                $t->nodeValue = (string) preg_replace_callback(
                    '/[….][….…\s.]*/u',
                    static fn () => $value,
                    $text,
                    1
                );
                $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $done = true;
            }
        }
    }

    /**
     * Tandai kategori: terpilih = (√) tebal, lainnya = (×).
     * Kategori tanpa glyph (phishing dkk) ditambahi penanda.
     */
    private function markCategories(DOMXPath $xpath, DOMDocument $dom, $p, string $selected): void
    {
        $names = [
            'web defacement', 'hacking', 'malware detection', 'phishing',
            'spoofing', 'brute force', 'denial of service', 'sniffing',
        ];
        foreach ($xpath->query('.//w:r', $p) as $r) {
            $t = $xpath->query('w:t', $r)->item(0);
            if (!$t) {
                continue;
            }
            $text = $t->nodeValue;
            foreach ($names as $name) {
                if (mb_stripos($text, $name) === false) {
                    continue;
                }
                $isSelected = mb_strtolower(trim($selected)) === $name;
                $mark = $isSelected ? self::CHECKED : self::UNCHECKED;
                if (mb_strpos($text, self::CHECKED) !== false) {
                    $t->nodeValue = str_replace(self::CHECKED, $mark, $text);
                } elseif (mb_strpos($text, '(') !== false) {
                    $t->nodeValue = (string) preg_replace('/\(.*?\)/u', "({$mark})", $text, 1);
                } else {
                    $t->nodeValue = trim($text) . " ({$mark})";
                }
                if ($isSelected) {
                    $this->boldRun($xpath, $dom, $r);
                }
                break;
            }
        }
    }

    /**
     * Tandai opsi dalam kurung siku: "[Proaktif] [Reaktif]", "[ya] [tidak]".
     * Terpilih = "[√ Proaktif]", lainnya = "[  Proaktif]".
     */
    private function markBracketOptions(DOMXPath $xpath, $p, array $map): void
    {
        foreach ($xpath->query('w:r/w:t', $p) as $t) {
            $text = $t->nodeValue;
            $changed = false;
            foreach ($map as $label => $active) {
                $needle = '[' . $label . ']';
                if (mb_stripos($text, $needle) !== false) {
                    $text = str_ireplace(
                        $needle,
                        $active ? '[√ ' . $label . ']' : '[  ' . $label . ']',
                        $text
                    );
                    $changed = true;
                }
            }
            if ($changed) {
                // Gabung opsi terpisah run (mis. "[" + "Proaktif" + "]") ke run pertama.
                $t->nodeValue = $text;
                $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            }
        }
        // Rangkai opsi yang terpecah ke beberapa run: "[Proaktif] [Reaktif]".
        $this->mergeBracketRuns($xpath, $p, $map);
    }

    /**
     * Tangani label opsi yang terpecah ke beberapa run
     * (contoh template: run "[" + run "Proaktif" + run "] [Reaktif]]").
     */
    private function mergeBracketRuns(DOMXPath $xpath, $p, array $map): void
    {
        $runs = [];
        foreach ($xpath->query('w:r', $p) as $r) {
            $t = $xpath->query('w:t', $r)->item(0);
            if ($t) {
                $runs[] = [$r, $t];
            }
        }
        $joined = '';
        foreach ($runs as [, $t]) {
            $joined .= $t->nodeValue;
        }
        $changed = false;
        foreach ($map as $label => $active) {
            $needle = '[' . $label . ']';
            if (mb_stripos($joined, $needle) !== false) {
                $joined = str_ireplace(
                    $needle,
                    $active ? '[√ ' . $label . ']' : '[  ' . $label . ']',
                    $joined
                );
                $changed = true;
            }
        }
        if ($changed && count($runs) > 0) {
            $runs[0][1]->nodeValue = $joined;
            for ($k = 1; $k < count($runs); $k++) {
                $runs[$k][1]->nodeValue = '';
            }
        }
    }

    /**
     * Tandai opsi garis miring "Server / Website / PC / Network".
     */
    private function markSlashOptions(DOMXPath $xpath, $p, array $options, string $selected): void
    {
        foreach ($xpath->query('w:r', $p) as $r) {
            $t = $xpath->query('w:t', $r)->item(0);
            if (!$t) {
                continue;
            }
            $text = $t->nodeValue;
            foreach ($options as $opt) {
                if (mb_stripos($text, $opt) === false) {
                    continue 2;
                }
            }
            $parts = preg_split('/(\s*\/\s*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            $out = '';
            foreach ($parts as $part) {
                $hit = false;
                foreach ($options as $opt) {
                    if (trim($part) !== '' && mb_strtolower(trim($part)) === mb_strtolower($opt)) {
                        $hit = true;
                        $out .= (mb_strtolower($opt) === mb_strtolower(trim($selected)))
                            ? '√' . $opt
                            : $opt;
                        break;
                    }
                }
                if (!$hit) {
                    $out .= $part;
                }
            }
            $t->nodeValue = $out;
            break;
        }
    }

    private function boldRun(DOMXPath $xpath, DOMDocument $dom, $run): void
    {
        $rPr = $xpath->query('w:rPr', $run)->item(0);
        if (!$rPr) {
            $rPr = $dom->createElementNS(self::W, 'w:rPr');
            $run->insertBefore($rPr, $run->firstChild);
        }
        if ($xpath->query('w:b', $rPr)->length === 0) {
            $rPr->appendChild($dom->createElementNS(self::W, 'w:b'));
        }
    }

    private function setCellText(DOMXPath $xpath, DOMDocument $dom, $rowNode, int $cellIndex, string $text): void
    {
        $cells = $xpath->query('w:tc', $rowNode);
        if ($cells->length > $cellIndex) {
            $this->writeCellText($dom, $xpath, $cells->item($cellIndex), $text);
        }
    }

    private function writeCellText(DOMDocument $dom, DOMXPath $xpath, $cellNode, string $text): void
    {
        $paragraphs = $xpath->query('w:p', $cellNode);
        if ($paragraphs->length === 0) {
            return;
        }
        $p = $paragraphs->item(0);

        $rPr = null;
        $existingRuns = $xpath->query('w:r', $p);
        if ($existingRuns->length > 0) {
            $existingRPr = $xpath->query('w:rPr', $existingRuns->item(0))->item(0);
            if ($existingRPr) {
                $rPr = $existingRPr->cloneNode(true);
            }
        }
        if (!$rPr) {
            $paraRPr = $xpath->query('w:pPr/w:rPr', $p)->item(0);
            if ($paraRPr) {
                $rPr = $paraRPr->cloneNode(true);
            }
        }
        foreach ($existingRuns as $run) {
            $p->removeChild($run);
        }

        $newRun = $dom->createElementNS(self::W, 'w:r');
        if ($rPr) {
            $newRun->appendChild($rPr);
        }

        $newText = $dom->createElementNS(self::W, 'w:t');
        $newText->nodeValue = $text; // DOM meng-escape otomatis (& < >)
        if (trim($text) !== $text) {
            $newText->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        }
        $newRun->appendChild($newText);
        $p->appendChild($newRun);
    }
}
