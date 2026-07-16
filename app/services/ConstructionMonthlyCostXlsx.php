<?php
/**
 * 공사 월별 투입비 통합 XLSX 생성기
 * - ZipArchive 기반, PHP 5.6 호환
 */

namespace App\Services;

class ConstructionMonthlyCostXlsx
{
    public static function build($sheets, $filePath)
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('서버에 ZipArchive 확장 모듈이 없습니다.');
        }
        if (!is_array($sheets) || count($sheets) === 0) {
            throw new \RuntimeException('생성할 엑셀 시트가 없습니다.');
        }

        $parts = array(
            '[Content_Types].xml' => self::contentTypesXml(count($sheets)),
            '_rels/.rels' => self::rootRelationshipsXml(),
            'xl/workbook.xml' => self::workbookXml($sheets),
            'xl/_rels/workbook.xml.rels' => self::workbookRelationshipsXml(count($sheets)),
            'xl/styles.xml' => self::stylesXml(),
            'docProps/core.xml' => self::corePropertiesXml(),
            'docProps/app.xml' => self::appPropertiesXml($sheets)
        );
        foreach ($sheets as $index => $sheet) {
            $parts['xl/worksheets/sheet' . ($index + 1) . '.xml'] = self::sheetXml($sheet, $index === 0);
        }
        foreach ($parts as $partName => $partXml) {
            self::validateXml($partName, $partXml);
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('엑셀 임시 파일을 열 수 없습니다.');
        }
        foreach ($parts as $partName => $partXml) {
            if ($zip->addFromString($partName, $partXml) === false) {
                $zip->close();
                throw new \RuntimeException('엑셀 구성요소를 저장할 수 없습니다: ' . $partName);
            }
        }
        $zip->close();

        self::verify($filePath, count($sheets));
    }

    public static function cell($value, $style)
    {
        return array('value' => $value, 'style' => (int)$style);
    }

    private static function sheetXml($sheet, $isFirst)
    {
        $rows = isset($sheet['rows']) && is_array($sheet['rows']) ? $sheet['rows'] : array();
        $widths = isset($sheet['widths']) && is_array($sheet['widths']) ? $sheet['widths'] : array();
        $merges = isset($sheet['merges']) && is_array($sheet['merges']) ? $sheet['merges'] : array();
        $columnCount = isset($sheet['column_count']) ? max(1, (int)$sheet['column_count']) : max(1, count($widths));
        $rowCount = max(1, count($rows));
        $lastColumn = self::columnName($columnCount);

        $columnXml = '';
        for ($column = 1; $column <= $columnCount; $column++) {
            $width = isset($widths[$column - 1]) ? (float)$widths[$column - 1] : 13.0;
            if ($width < 2) $width = 2;
            if ($width > 80) $width = 80;
            $columnXml .= '<col min="' . $column . '" max="' . $column . '" width="' . self::number($width) . '" customWidth="1"/>';
        }

        $rowXml = '';
        foreach ($rows as $rowIndex => $rowDefinition) {
            $rowNumber = $rowIndex + 1;
            $height = 20;
            $cells = $rowDefinition;
            if (is_array($rowDefinition) && isset($rowDefinition['cells'])) {
                $cells = is_array($rowDefinition['cells']) ? $rowDefinition['cells'] : array();
                $height = isset($rowDefinition['height']) ? (float)$rowDefinition['height'] : 20;
            }
            $rowXml .= '<row r="' . $rowNumber . '" ht="' . self::number($height) . '" customHeight="1">';
            for ($column = 1; $column <= $columnCount; $column++) {
                $cell = isset($cells[$column - 1]) ? $cells[$column - 1] : array('value' => '', 'style' => 0);
                $value = is_array($cell) && array_key_exists('value', $cell) ? $cell['value'] : $cell;
                $style = is_array($cell) && isset($cell['style']) ? (int)$cell['style'] : 0;
                $rowXml .= self::cellXml($rowNumber, $column, $value, $style);
            }
            $rowXml .= '</row>';
        }

        $mergeXml = '';
        if (count($merges) > 0) {
            $mergeXml = '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $merge) {
                $mergeXml .= '<mergeCell ref="' . self::xml($merge) . '"/>';
            }
            $mergeXml .= '</mergeCells>';
        }

        $sheetView = '<sheetView showGridLines="0"' . ($isFirst ? ' tabSelected="1"' : '') . ' workbookViewId="0">';
        $freeze = isset($sheet['freeze']) && is_array($sheet['freeze']) ? $sheet['freeze'] : array();
        $freezeX = isset($freeze['x']) ? max(0, (int)$freeze['x']) : 0;
        $freezeY = isset($freeze['y']) ? max(0, (int)$freeze['y']) : 0;
        if ($freezeX > 0 || $freezeY > 0) {
            $topLeft = self::columnName($freezeX + 1) . ($freezeY + 1);
            $pane = ($freezeX > 0 && $freezeY > 0) ? 'bottomRight' : ($freezeX > 0 ? 'topRight' : 'bottomLeft');
            $sheetView .= '<pane'
                . ($freezeX > 0 ? ' xSplit="' . $freezeX . '"' : '')
                . ($freezeY > 0 ? ' ySplit="' . $freezeY . '"' : '')
                . ' topLeftCell="' . $topLeft . '" activePane="' . $pane . '" state="frozen"/>';
            $sheetView .= '<selection pane="' . $pane . '" activeCell="' . $topLeft . '" sqref="' . $topLeft . '"/>';
        }
        $sheetView .= '</sheetView>';

        $orientation = isset($sheet['orientation']) && $sheet['orientation'] === 'portrait' ? 'portrait' : 'landscape';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<dimension ref="A1:' . $lastColumn . $rowCount . '"/>'
            . '<sheetViews>' . $sheetView . '</sheetViews>'
            . '<sheetFormatPr defaultRowHeight="20"/><cols>' . $columnXml . '</cols>'
            . '<sheetData>' . $rowXml . '</sheetData>'
            . $mergeXml
            . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="' . $orientation . '" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
            . '</worksheet>';
    }

    private static function cellXml($row, $column, $value, $style)
    {
        $ref = self::columnName($column) . $row;
        $styleAttribute = ' s="' . max(0, (int)$style) . '"';
        if ($value === null || $value === '') {
            return '<c r="' . $ref . '"' . $styleAttribute . '/>';
        }
        if (is_int($value) || is_float($value)) {
            return '<c r="' . $ref . '"' . $styleAttribute . '><v>' . self::number($value) . '</v></c>';
        }
        return '<c r="' . $ref . '" t="inlineStr"' . $styleAttribute . '><is><t xml:space="preserve">' . self::xml($value) . '</t></is></c>';
    }

    private static function stylesXml()
    {
        $fonts = '<fonts count="10">'
            . '<font><sz val="10"/><color rgb="FF111827"/><name val="맑은 고딕"/></font>'
            . '<font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="맑은 고딕"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF111827"/><name val="맑은 고딕"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF1D4ED8"/><name val="맑은 고딕"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFDC2626"/><name val="맑은 고딕"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF92400E"/><name val="맑은 고딕"/></font>'
            . '<font><sz val="10"/><color rgb="FF6B7280"/><name val="맑은 고딕"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="맑은 고딕"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF1D4ED8"/><name val="맑은 고딕"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF7C3AED"/><name val="맑은 고딕"/></font>'
            . '</fonts>';

        $fills = '<fills count="18">'
            . '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . self::fill('111827')
            . self::fill('F3F4F6')
            . self::fill('D7AA8A')
            . self::fill('F2DFCF')
            . self::fill('FEF3C7')
            . self::fill('FFFBEB')
            . self::fill('FEF9C3')
            . self::fill('FFFFFF')
            . self::fill('FFEDD5')
            . self::fill('F2A983')
            . self::fill('F7C7A8')
            . self::fill('BFBFBF')
            . self::fill('FEFCE8')
            . self::fill('EFF6FF')
            . self::fill('F5F3FF')
            . self::fill('1F2937')
            . '</fills>';

        $borders = '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right><top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border></borders>';

        $definitions = array(
            array(0,0,0,0,'left',false),
            array(1,2,0,0,'center',false),
            array(2,3,1,0,'center',false),
            array(0,9,1,0,'left',false),
            array(2,4,1,0,'center',true),
            array(2,5,1,0,'left',false),
            array(0,9,1,0,'left',true),
            array(0,9,1,0,'center',true),
            array(0,9,1,164,'right',false),
            array(2,6,1,0,'left',true),
            array(2,6,1,164,'right',false),
            array(2,7,1,0,'left',true),
            array(2,7,1,164,'right',false),
            array(2,8,1,0,'left',true),
            array(2,8,1,164,'right',false),
            array(2,10,1,0,'left',true),
            array(2,10,1,164,'right',false),
            array(2,9,1,0,'left',true),
            array(3,9,1,164,'right',false),
            array(4,9,1,164,'right',false),
            array(2,11,1,0,'center',true),
            array(2,12,1,164,'right',false),
            array(2,13,1,0,'center',true),
            array(0,9,1,0,'left',false),
            array(5,14,1,164,'right',false),
            array(6,13,1,0,'center',true),
            array(0,9,1,165,'center',true),
            array(2,8,1,0,'center',true),
            array(2,8,1,165,'center',true),
            array(2,8,1,3,'right',false),
            array(0,15,1,0,'left',true),
            array(0,15,1,0,'center',true),
            array(2,15,1,3,'right',false),
            array(0,16,1,0,'left',true),
            array(0,16,1,0,'center',true),
            array(2,16,1,3,'right',false),
            array(7,17,1,0,'center',true),
            array(7,17,1,3,'right',false),
            array(8,15,1,0,'center',true),
            array(2,15,1,3,'right',false),
            array(9,16,1,0,'center',true),
            array(2,16,1,3,'right',false),
            array(0,9,1,3,'right',false)
        );
        $cellXfs = '';
        foreach ($definitions as $definition) {
            $cellXfs .= self::styleXf($definition[0], $definition[1], $definition[2], $definition[3], $definition[4], $definition[5]);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0;[Red]-#,##0;-"/><numFmt numFmtId="165" formatCode="0.##;[Red]-0.##;-"/></numFmts>'
            . $fonts . $fills . $borders
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($definitions) . '">' . $cellXfs . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function styleXf($font, $fill, $border, $numberFormat, $horizontal, $wrap)
    {
        $alignment = '<alignment horizontal="' . $horizontal . '" vertical="center"' . ($wrap ? ' wrapText="1"' : '') . '/>';
        return '<xf numFmtId="' . (int)$numberFormat . '" fontId="' . (int)$font . '" fillId="' . (int)$fill . '" borderId="' . (int)$border . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1">' . $alignment . '</xf>';
    }

    private static function fill($rgb)
    {
        return '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $rgb . '"/><bgColor indexed="64"/></patternFill></fill>';
    }

    private static function workbookXml($sheets)
    {
        $sheetXml = '';
        foreach ($sheets as $index => $sheet) {
            $name = isset($sheet['name']) ? (string)$sheet['name'] : ('Sheet' . ($index + 1));
            $sheetXml .= '<sheet name="' . self::xml($name) . '" sheetId="' . ($index + 1) . '" state="visible" r:id="rId' . ($index + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<fileVersion appName="xl" lastEdited="7" lowestEdited="7" rupBuild="0"/>'
            . '<workbookPr defaultThemeVersion="166925"/>'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
            . '<sheets>' . $sheetXml . '</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
    }

    private static function workbookRelationshipsXml($sheetCount)
    {
        $relationships = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $relationships .= '<Relationship Id="rId' . $index . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $index . '.xml"/>';
        }
        $relationships .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>';
    }

    private static function contentTypesXml($sheetCount)
    {
        $overrides = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $index . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $overrides
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function rootRelationshipsXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function corePropertiesXml()
    {
        $created = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>CPMS</dc:creator><cp:lastModifiedBy>CPMS</cp:lastModifiedBy><dc:title>공사 월별 투입비 통합내역</dc:title>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function appPropertiesXml($sheets)
    {
        $titles = '';
        foreach ($sheets as $index => $sheet) {
            $name = isset($sheet['name']) ? (string)$sheet['name'] : ('Sheet' . ($index + 1));
            $titles .= '<vt:lpstr>' . self::xml($name) . '</vt:lpstr>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Microsoft Excel</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . count($sheets) . '</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . count($sheets) . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts>'
            . '<Company>CPMS</Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>16.0300</AppVersion>'
            . '</Properties>';
    }

    private static function verify($filePath, $sheetCount)
    {
        $required = array('[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'docProps/core.xml', 'docProps/app.xml');
        for ($index = 1; $index <= $sheetCount; $index++) $required[] = 'xl/worksheets/sheet' . $index . '.xml';

        $zip = new \ZipArchive();
        $opened = $zip->open($filePath, \ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new \RuntimeException('생성된 엑셀 파일이 올바른 ZIP 패키지가 아닙니다.');
        }
        foreach ($required as $part) {
            if ($zip->locateName($part) === false || $zip->getFromName($part) === false) {
                $zip->close();
                throw new \RuntimeException('생성된 엑셀 파일에 필수 구성요소가 없습니다: ' . $part);
            }
        }
        $zip->close();
    }

    private static function validateXml($partName, $xml)
    {
        if (!function_exists('simplexml_load_string')) return;
        $previous = libxml_use_internal_errors(true);
        $valid = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($valid === false) {
            throw new \RuntimeException('엑셀 XML 구성요소가 올바르지 않습니다: ' . $partName);
        }
    }

    private static function columnName($index)
    {
        $index = max(1, (int)$index);
        $name = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $index = (int)(($index - 1) / 26);
        }
        return $name;
    }

    private static function number($value)
    {
        if (!is_numeric($value)) return '0';
        $formatted = sprintf('%.10F', (float)$value);
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    private static function xml($value)
    {
        $value = (string)$value;
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
        if ($clean !== null) $value = $clean;
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
