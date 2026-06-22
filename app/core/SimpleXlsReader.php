<?php
/**
 * Minimal BIFF8 .xls reader for uploaded card ledgers.
 * PHP 5.6 compatible.
 */

namespace App\Core;

class SimpleXlsReader
{
    const END_OF_CHAIN = 4294967294;
    const FREE_SECTOR = 4294967295;

    public static function readFirstSheet($filePath, $maxRows)
    {
        $result = array('rows' => array(), 'error' => null);
        if (!is_string($filePath) || $filePath === '' || !is_file($filePath)) {
            $result['error'] = '파일을 찾을 수 없습니다.';
            return $result;
        }
        $bytes = @file_get_contents($filePath);
        if ($bytes === false || strlen($bytes) < 512) {
            $result['error'] = '엑셀 파일을 읽을 수 없습니다.';
            return $result;
        }
        if (substr($bytes, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            $result['error'] = '.xls 형식이 아닙니다.';
            return $result;
        }

        $workbook = self::workbookStream($bytes);
        if ($workbook === '') {
            $result['error'] = '엑셀 Workbook 스트림을 찾을 수 없습니다.';
            return $result;
        }
        return self::biffRows($workbook, $maxRows);
    }

    private static function u16($bytes, $offset)
    {
        $v = unpack('v', substr($bytes, $offset, 2));
        return isset($v[1]) ? (int)$v[1] : 0;
    }

    private static function u32($bytes, $offset)
    {
        $v = unpack('V', substr($bytes, $offset, 4));
        return isset($v[1]) ? (float)$v[1] : 0;
    }

    private static function dbl($bytes)
    {
        $v = unpack('d', $bytes);
        return isset($v[1]) ? (float)$v[1] : 0.0;
    }

    private static function workbookStream($bytes)
    {
        $sectorShift = self::u16($bytes, 30);
        $sectorSize = 1 << $sectorShift;
        if ($sectorSize < 512 || $sectorSize > 4096) $sectorSize = 512;
        $numFat = (int)self::u32($bytes, 44);
        $firstDir = (int)self::u32($bytes, 48);

        $fatSectors = array();
        for ($i = 0; $i < 109 && count($fatSectors) < $numFat; $i++) {
            $sid = (int)self::u32($bytes, 76 + ($i * 4));
            if ($sid !== self::FREE_SECTOR && $sid !== self::END_OF_CHAIN && $sid >= 0) $fatSectors[] = $sid;
        }

        $fat = array();
        foreach ($fatSectors as $sid) {
            $off = ($sid + 1) * $sectorSize;
            if ($off < 0 || $off + $sectorSize > strlen($bytes)) continue;
            for ($p = 0; $p < $sectorSize; $p += 4) $fat[] = (int)self::u32($bytes, $off + $p);
        }
        if (count($fat) === 0) return '';

        $dir = self::readChain($bytes, $fat, $firstDir, $sectorSize, 0);
        $entries = array();
        for ($pos = 0; $pos + 128 <= strlen($dir); $pos += 128) {
            $entry = substr($dir, $pos, 128);
            $nameLen = self::u16($entry, 64);
            if ($nameLen < 2 || $nameLen > 64) continue;
            $name = self::decodeUtf16(substr($entry, 0, $nameLen - 2));
            $type = ord($entry[66]);
            $start = (int)self::u32($entry, 116);
            $size = (int)self::u32($entry, 120);
            $entries[] = array('name' => $name, 'type' => $type, 'start' => $start, 'size' => $size);
        }

        foreach ($entries as $entry) {
            if (($entry['name'] === 'Workbook' || $entry['name'] === 'Book') && (int)$entry['type'] === 2) {
                return self::readChain($bytes, $fat, (int)$entry['start'], $sectorSize, (int)$entry['size']);
            }
        }
        return '';
    }

    private static function readChain($bytes, $fat, $start, $sectorSize, $size)
    {
        $out = '';
        $sid = (int)$start;
        $seen = array();
        $limit = count($fat) + 5;
        while ($sid !== self::END_OF_CHAIN && $sid !== self::FREE_SECTOR && $sid >= 0 && $sid < count($fat) && $limit > 0) {
            if (isset($seen[$sid])) break;
            $seen[$sid] = true;
            $off = ($sid + 1) * $sectorSize;
            if ($off < 0 || $off >= strlen($bytes)) break;
            $out .= substr($bytes, $off, $sectorSize);
            $sid = (int)$fat[$sid];
            $limit--;
        }
        return $size > 0 ? substr($out, 0, $size) : $out;
    }

    private static function biffRows($workbook, $maxRows)
    {
        $records = array();
        $pos = 0;
        $len = strlen($workbook);
        while ($pos + 4 <= $len) {
            $type = self::u16($workbook, $pos);
            $size = self::u16($workbook, $pos + 2);
            $pos += 4;
            if ($size < 0 || $pos + $size > $len) break;
            $records[] = array('type' => $type, 'data' => substr($workbook, $pos, $size));
            $pos += $size;
        }

        $strings = self::sstStrings($records);
        $cells = array();
        $maxRow = 0;
        $maxCol = 0;
        foreach ($records as $record) {
            $type = (int)$record['type'];
            $data = $record['data'];
            $dlen = strlen($data);
            if ($type === 0x00FD && $dlen >= 10) {
                $r = self::u16($data, 0);
                $c = self::u16($data, 2);
                $si = (int)self::u32($data, 6);
                self::setCell($cells, $maxRow, $maxCol, $r, $c, isset($strings[$si]) ? $strings[$si] : '');
            } else if ($type === 0x0203 && $dlen >= 14) {
                $r = self::u16($data, 0);
                $c = self::u16($data, 2);
                self::setCell($cells, $maxRow, $maxCol, $r, $c, self::dbl(substr($data, 6, 8)));
            } else if ($type === 0x027E && $dlen >= 10) {
                $r = self::u16($data, 0);
                $c = self::u16($data, 2);
                self::setCell($cells, $maxRow, $maxCol, $r, $c, self::rkValue(substr($data, 6, 4)));
            } else if ($type === 0x00BD && $dlen >= 6) {
                $r = self::u16($data, 0);
                $c1 = self::u16($data, 2);
                $c2 = self::u16($data, $dlen - 2);
                $off = 4;
                for ($c = $c1; $c <= $c2; $c++) {
                    if ($off + 6 > $dlen - 2) break;
                    self::setCell($cells, $maxRow, $maxCol, $r, $c, self::rkValue(substr($data, $off + 2, 4)));
                    $off += 6;
                }
            } else if ($type === 0x0204 && $dlen >= 8) {
                $r = self::u16($data, 0);
                $c = self::u16($data, 2);
                $strlen = self::u16($data, 6);
                self::setCell($cells, $maxRow, $maxCol, $r, $c, self::decodeText(substr($data, 8, $strlen)));
            }
        }

        $rows = array();
        $limitRow = min($maxRow, max(0, (int)$maxRows - 1));
        for ($r = 0; $r <= $limitRow; $r++) {
            $row = array();
            for ($c = 0; $c <= $maxCol; $c++) {
                $row[] = isset($cells[$r][$c]) ? $cells[$r][$c] : '';
            }
            $rows[] = $row;
        }
        return array('rows' => $rows, 'error' => null);
    }

    private static function setCell(&$cells, &$maxRow, &$maxCol, $row, $col, $value)
    {
        $row = (int)$row;
        $col = (int)$col;
        if (!isset($cells[$row])) $cells[$row] = array();
        $cells[$row][$col] = $value;
        if ($row > $maxRow) $maxRow = $row;
        if ($col > $maxCol) $maxCol = $col;
    }

    private static function sstStrings($records)
    {
        $payload = '';
        for ($i = 0; $i < count($records); $i++) {
            if ((int)$records[$i]['type'] !== 0x00FC) continue;
            $payload = $records[$i]['data'];
            $j = $i + 1;
            while ($j < count($records) && (int)$records[$j]['type'] === 0x003C) {
                $payload .= $records[$j]['data'];
                $j++;
            }
            break;
        }
        if ($payload === '' || strlen($payload) < 8) return array();
        $unique = (int)self::u32($payload, 4);
        $off = 8;
        $strings = array();
        for ($i = 0; $i < $unique && $off + 3 <= strlen($payload); $i++) {
            $read = self::readUnicodeString($payload, $off);
            $strings[] = $read['text'];
            $off = $read['offset'];
        }
        return $strings;
    }

    private static function readUnicodeString($bytes, $off)
    {
        $cch = self::u16($bytes, $off);
        $flags = ord($bytes[$off + 2]);
        $off += 3;
        $rich = ($flags & 0x08) ? true : false;
        $ext = ($flags & 0x04) ? true : false;
        $is16 = ($flags & 0x01) ? true : false;
        $rt = 0;
        $cb = 0;
        if ($rich && $off + 2 <= strlen($bytes)) {
            $rt = self::u16($bytes, $off);
            $off += 2;
        }
        if ($ext && $off + 4 <= strlen($bytes)) {
            $cb = (int)self::u32($bytes, $off);
            $off += 4;
        }
        $byteLen = $cch * ($is16 ? 2 : 1);
        $raw = substr($bytes, $off, $byteLen);
        $off += $byteLen;
        $text = $is16 ? self::decodeUtf16($raw) : self::decodeText($raw);
        if ($rich) $off += 4 * $rt;
        if ($ext) $off += $cb;
        return array('text' => $text, 'offset' => $off);
    }

    private static function decodeUtf16($raw)
    {
        if ($raw === '') return '';
        if (function_exists('mb_convert_encoding')) return mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        if (function_exists('iconv')) {
            $out = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
            return $out === false ? '' : $out;
        }
        return $raw;
    }

    private static function decodeText($raw)
    {
        if ($raw === '') return '';
        if (function_exists('mb_convert_encoding')) return mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        if (function_exists('iconv')) {
            $out = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $raw);
            return $out === false ? '' : $out;
        }
        return $raw;
    }

    private static function rkValue($raw)
    {
        if (strlen($raw) < 4) return 0.0;
        $value = (int)self::u32($raw, 0);
        $mult = ($value & 0x00000001) ? true : false;
        $isInt = ($value & 0x00000002) ? true : false;
        $num = $value & 0xFFFFFFFC;
        if ($isInt) {
            if ($num >= 0x80000000) $num -= 0x100000000;
            $v = $num / 4;
        } else {
            $packed = pack('V2', 0, $num);
            $v = self::dbl($packed);
        }
        if ($mult) $v = $v / 100.0;
        return $v;
    }
}
