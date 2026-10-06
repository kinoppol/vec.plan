<?php
declare(strict_types=1);

/**
 * Minimal .xlsx reader/writer without ext-zip (only zlib), enough for the plan import
 * template: first worksheet, strings and numbers.
 */
class Xlsx
{
    // ------------------------------------------------------------------ read

    /** @return array<int, array<int, string>> rows of cell strings (first sheet) */
    public static function read(string $path): array
    {
        $zip = self::unzip(file_get_contents($path));
        $shared = [];
        if (isset($zip['xl/sharedStrings.xml'])) {
            $x = self::xml($zip['xl/sharedStrings.xml']);
            foreach ($x->si as $si) {
                if (isset($si->t)) $shared[] = (string)$si->t;
                else {
                    $s = '';
                    foreach ($si->r as $r) $s .= (string)$r->t;
                    $shared[] = $s;
                }
            }
        }
        $sheetPath = 'xl/worksheets/sheet1.xml';
        if (isset($zip['xl/workbook.xml'], $zip['xl/_rels/workbook.xml.rels'])) {
            $wb = self::xml($zip['xl/workbook.xml']);
            $first = $wb->sheets->sheet[0] ?? null;
            if ($first) {
                $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $rels = self::xml($zip['xl/_rels/workbook.xml.rels']);
                foreach ($rels->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $target = ltrim((string)$rel['Target'], '/');
                        $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }
        if (!isset($zip[$sheetPath])) throw new RuntimeException('ไม่พบแผ่นงานในไฟล์ Excel');
        $sheet = self::xml($zip[$sheetPath]);
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = $ref !== '' ? self::colIndex($ref) : count($cells);
                $t = (string)$c['t'];
                if ($t === 's') $v = $shared[(int)$c->v] ?? '';
                elseif ($t === 'inlineStr') $v = isset($c->is->t) ? (string)$c->is->t : implode('', array_map(fn($r) => (string)$r->t, iterator_to_array($c->is->r ?? [], false)));
                elseif ($t === 'b') $v = ((string)$c->v) === '1' ? 'TRUE' : 'FALSE';
                else $v = (string)$c->v;
                $cells[$col] = $v;
            }
            if (!$cells) continue;
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $v) $line[$i] = $v;
            $rows[] = $line;
        }
        return $rows;
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
        $n = 0;
        foreach (str_split($m[1] ?? 'A') as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }

    private static function xml(string $s): SimpleXMLElement
    {
        $x = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET);
        if ($x === false) throw new RuntimeException('อ่านไฟล์ Excel ไม่ได้ (XML เสีย)');
        return $x;
    }

    /** @return array<string,string> */
    private static function unzip(string $data): array
    {
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false) throw new RuntimeException('ไฟล์ไม่ใช่ .xlsx ที่ถูกต้อง');
        $e = unpack('vdisk/vcdDisk/ventriesDisk/ventries/Vsize/Voffset', substr($data, $eocd + 4, 16));
        $pos = $e['offset'];
        $files = [];
        for ($i = 0; $i < $e['entries']; $i++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02") throw new RuntimeException('โครงสร้างไฟล์ .xlsx เสีย');
            $h = unpack('vmade/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voffset', substr($data, $pos + 4, 42));
            $name = substr($data, $pos + 46, $h['nlen']);
            $pos += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
            $lh = unpack('vnlen/velen', substr($data, $h['offset'] + 26, 4));
            $start = $h['offset'] + 30 + $lh['nlen'] + $lh['elen'];
            $raw = substr($data, $start, $h['csize']);
            if ($h['method'] === 0) $files[$name] = $raw;
            elseif ($h['method'] === 8) {
                $out = @gzinflate($raw);
                if ($out === false) throw new RuntimeException('แตกไฟล์ .xlsx ไม่ได้');
                $files[$name] = $out;
            }
        }
        return $files;
    }

    // ------------------------------------------------------------------ write

    /** Build a one-sheet .xlsx from rows of scalars; the first row is bold (header). */
    public static function build(array $rows, string $sheetName = 'Sheet1'): string
    {
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xmlRows = '';
        foreach (array_values($rows) as $r => $row) {
            $xmlRows .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $v) {
                $ref = self::colName($c) . ($r + 1);
                $style = $r === 0 ? ' s="1"' : '';
                if (is_int($v) || is_float($v)) $xmlRows .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
                else $xmlRows .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
            }
            $xmlRows .= '</row>';
        }
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><name val="Tahoma"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xmlRows . '</sheetData></worksheet>',
        ];
        return self::zip($files);
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    /** Store-only zip archive. */
    private static function zip(array $files): string
    {
        $data = '';
        $central = '';
        $offset = 0;
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $len = strlen($content);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, 0, 0x21, $crc, $len, $len, strlen($name), 0) . $name . $content;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, 0, 0x21, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $data .= $local;
            $offset += strlen($local);
        }
        return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
    }
}
