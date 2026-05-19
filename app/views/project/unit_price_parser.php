<?php
if (!function_exists('cpms_project_parse_unit_price_xlsx')) {
function cpms_project_parse_unit_price_xlsx($pdo, $tmpFile) {
    $result = array('ok'=>false,'rows'=>array(),'message'=>'');
    if (!$pdo) { $result['message']='DB 연결 실패'; return $result; }
    if ($tmpFile === '' || !file_exists($tmpFile)) { $result['message']='업로드 파일 없음'; return $result; }

    $maps = array();
    try {
        $st = $pdo->query("SELECT system_field, excel_headers, is_required FROM cpms_unit_price_header_map");
        foreach ($st->fetchAll() as $r) {
            $aliases = array();
            foreach (explode('|', (string)$r['excel_headers']) as $a) {
                $a = trim((string)$a);
                if ($a !== '') $aliases[] = $a;
            }
            $maps[(string)$r['system_field']] = array('headers'=>$aliases,'required'=>(int)$r['is_required']);
        }
    } catch (Exception $e) { $maps = array(); }
    if (count($maps) === 0) { $result['message']='헤더 매핑 설정 없음'; return $result; }

    $res = \App\Core\SimpleXlsxReader::readFirstSheet($tmpFile, 1000);
    if (!empty($res['error'])) { $result['message']='엑셀 읽기 실패: '.$res['error']; return $result; }
    $rows = isset($res['rows']) ? $res['rows'] : array();
    if (count($rows) < 2) { $result['message']='데이터가 없습니다.'; return $result; }

    $normalize = function($v){ $v=trim((string)$v); $v=preg_replace('/\s+/', '', $v); return mb_strtolower($v,'UTF-8'); };
    $nclean = function($v){ $v=str_replace(array(',', ' '), '', (string)$v); $v=preg_replace('/[^0-9\.\-]/', '', $v); return $v; };

    $header1 = isset($rows[0]) ? $rows[0] : array();
    $header2 = isset($rows[1]) ? $rows[1] : array();
    $maxCols = max(count($header1), count($header2));
    $single = array(); $double = array();
    for ($i=0;$i<$maxCols;$i++) {
        $h1 = isset($header1[$i]) ? trim((string)$header1[$i]) : '';
        $h2 = isset($header2[$i]) ? trim((string)$header2[$i]) : '';
        $single[$i] = $h1;
        if ($h2 !== '' && preg_match('/(단가|금액|노무|자재|안전|경비|합계)/u', $h2)) $double[$i] = $h2;
        else $double[$i] = ($h1 !== '') ? $h1 : $h2;
    }

    $matchMap = function($headers) use ($maps, $normalize) {
        $fieldToCol = array();
        for ($c=0;$c<count($headers);$c++) {
            $h = isset($headers[$c]) ? (string)$headers[$c] : '';
            if ($h==='') continue;
            $hn = $normalize($h);
            foreach ($maps as $sf=>$cfg) {
                if (isset($fieldToCol[$sf])) continue;
                foreach ($cfg['headers'] as $a) {
                    if ($hn === $normalize($a)) { $fieldToCol[$sf] = $c; break 2; }
                }
            }
        }
        $missing = array();
        foreach ($maps as $sf=>$cfg) { if ((int)$cfg['required']===1 && !isset($fieldToCol[$sf])) $missing[]=$sf; }
        return array($fieldToCol, $missing);
    };

    list($m1,$miss1) = $matchMap($single);
    list($m2,$miss2) = $matchMap($double);
    $useDouble = (count($miss2) <= count($miss1));
    $fieldToCol = $useDouble ? $m2 : $m1;
    $dataStart = $useDouble ? 2 : 1;
    $missingReq = $useDouble ? $miss2 : $miss1;
    if (count($missingReq)>0) { $result['message']='필수 헤더 없음: '.implode(', ', $missingReq); return $result; }

    $parsed = array();
    for ($r=$dataStart;$r<count($rows);$r++) {
        $row = $rows[$r];
        $item = isset($fieldToCol['item_name']) ? trim((string)@$row[$fieldToCol['item_name']]) : '';
        if ($item==='') continue;
        $spec = isset($fieldToCol['spec']) ? trim((string)@$row[$fieldToCol['spec']]) : '';
        $unit = isset($fieldToCol['unit']) ? trim((string)@$row[$fieldToCol['unit']]) : '';
        $remark = isset($fieldToCol['remark']) ? trim((string)@$row[$fieldToCol['remark']]) : '';
        $qty = null; $up=null; $lup=null; $mup=null; $sup=null;
        if (isset($fieldToCol['qty'])) { $x=$nclean(@$row[$fieldToCol['qty']]); if ($x!=='' && is_numeric($x)) $qty=(float)$x; }
        if (isset($fieldToCol['unit_price'])) { $x=$nclean(@$row[$fieldToCol['unit_price']]); if ($x!=='' && is_numeric($x)) $up=(float)$x; }
        if (isset($fieldToCol['labor_unit_price'])) { $x=$nclean(@$row[$fieldToCol['labor_unit_price']]); if ($x!=='' && is_numeric($x)) $lup=(float)$x; }
        if (isset($fieldToCol['material_unit_price'])) { $x=$nclean(@$row[$fieldToCol['material_unit_price']]); if ($x!=='' && is_numeric($x)) $mup=(float)$x; }
        if (isset($fieldToCol['safety_unit_price'])) { $x=$nclean(@$row[$fieldToCol['safety_unit_price']]); if ($x!=='' && is_numeric($x)) $sup=(float)$x; }
        $isSafety = (mb_strpos($item, '안전', 0, 'UTF-8') !== false || mb_strpos($spec, '안전', 0, 'UTF-8') !== false) ? 1 : 0;
        $parsed[] = array('item_name'=>$item,'spec'=>$spec,'unit'=>$unit,'qty'=>$qty,'unit_price'=>$up,'labor_unit_price'=>$lup,'material_unit_price'=>$mup,'safety_unit_price'=>$sup,'is_safety'=>$isSafety,'remark'=>$remark);
    }
    if (count($parsed)===0) { $result['message']='가져올 데이터가 없습니다.'; return $result; }
    $result['ok']=true; $result['rows']=$parsed;
    return $result;
}
}