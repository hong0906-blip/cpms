<?php
/**
 * 파일경로: public/test_approval_cost_apply.php
 * 기능: 비용 수정/누락 전자결재 "최종승인 -> 실제 공사자료 자동반영" 실테스트 도구 V2
 * 대상: 마스터 / 개발부서 전용
 * 지원: 노무비 / 장비비 / 외주비 / 자재비
 * 기준: PHP 5.6 호환
 *
 * 중요
 * - 실제 ApprovalCostCorrectionService::applyApprovedDocument()를 호출합니다.
 * - 결재선만 건너뛰고 최종승인 직후의 자동반영 코드는 운영과 동일합니다.
 * - 테스트 전 원본을 storage/approval_cost_apply_tests 에 스냅샷으로 저장합니다.
 * - 테스트 확인 후 반드시 [원상복구]를 눌러주세요.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/approval/_common.php';
require_once __DIR__ . '/../app/services/ApprovalCostCorrectionService.php';
require_once __DIR__ . '/../app/services/CostChangeService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\ApprovalCostCorrectionService;
use App\Services\CostChangeService;

if (!Auth::check()) {
    header('Location: ./?r=login');
    exit;
}

$allowed = Auth::isMaster();
if (!$allowed && method_exists('App\\Core\\Auth', 'isDevelopmentDepartment')) {
    $allowed = Auth::isDevelopmentDepartment();
}
if (!$allowed) {
    http_response_code(403);
    exit('403 - 마스터 또는 개발부서만 사용할 수 있습니다.');
}

$pdo = Db::pdo();
$user = Auth::user();
if (!$pdo || !is_array($user)) {
    http_response_code(500);
    exit('DB 또는 로그인 정보를 확인할 수 없습니다.');
}

if (!function_exists('tcat_h')) {
    function tcat_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('tcat_json')) {
    function tcat_json($data, $status)
    {
        http_response_code((int)$status);
        header('Content-Type: application/json; charset=UTF-8');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) $json = json_encode($data);
        echo $json;
        exit;
    }
}
if (!function_exists('tcat_table_exists')) {
    function tcat_table_exists($pdo, $table)
    {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:tbl");
            $st->execute(array(':tbl'=>(string)$table));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) { return false; }
    }
}
if (!function_exists('tcat_table_columns')) {
    function tcat_table_columns($pdo, $table)
    {
        $result = array();
        try {
            $safe = str_replace('`', '', (string)$table);
            $st = $pdo->query("SHOW COLUMNS FROM `" . $safe . "`");
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['Field'])) $result[(string)$row['Field']] = true;
            }
        } catch (Exception $e) { return array(); }
        return $result;
    }
}
if (!function_exists('tcat_valid_date')) {
    function tcat_valid_date($value)
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return '';
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return '';
        return $value;
    }
}
if (!function_exists('tcat_money')) {
    function tcat_money($value)
    {
        $raw = preg_replace('/[^0-9.\-]/', '', (string)$value);
        return ($raw !== '' && is_numeric($raw)) ? (float)$raw : 0.0;
    }
}
if (!function_exists('tcat_user_identity')) {
    function tcat_user_identity($user)
    {
        $id = isset($user['id']) ? (int)$user['id'] : 0;
        $name = isset($user['name']) ? trim((string)$user['name']) : '';
        $email = isset($user['email']) ? trim((string)$user['email']) : '';
        if ($name === '' && method_exists('App\\Core\\Auth','userName')) $name = (string)Auth::userName();
        if ($email === '' && method_exists('App\\Core\\Auth','userEmail')) $email = (string)Auth::userEmail();
        return array('id'=>$id,'name'=>$name,'email'=>$email);
    }
}
if (!function_exists('tcat_make_token')) {
    function tcat_make_token()
    {
        return 'T' . date('YmdHis') . '-' . substr(sha1(uniqid('', true) . '|' . mt_rand()), 0, 8);
    }
}
if (!function_exists('tcat_test_dir')) {
    function tcat_test_dir()
    {
        return dirname(__DIR__) . '/storage/approval_cost_apply_tests';
    }
}
if (!function_exists('tcat_snapshot_path')) {
    function tcat_snapshot_path($token)
    {
        $token = preg_replace('/[^A-Za-z0-9\-]/', '', (string)$token);
        return tcat_test_dir() . '/' . $token . '.json';
    }
}
if (!function_exists('tcat_save_snapshot')) {
    function tcat_save_snapshot($snapshot)
    {
        $dir = tcat_test_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('테스트 복구정보 저장폴더를 만들 수 없습니다. storage 폴더 쓰기 권한을 확인해주세요.');
        }
        $token = isset($snapshot['token']) ? (string)$snapshot['token'] : '';
        if ($token === '') throw new Exception('테스트번호를 만들지 못했습니다.');
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) throw new Exception('테스트 복구정보를 만들지 못했습니다.');
        if (@file_put_contents(tcat_snapshot_path($token), $json, LOCK_EX) === false) {
            throw new Exception('테스트 복구정보를 저장하지 못했습니다. storage 폴더 권한을 확인해주세요.');
        }
    }
}
if (!function_exists('tcat_load_snapshot')) {
    function tcat_load_snapshot($token)
    {
        $path = tcat_snapshot_path($token);
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }
}
if (!function_exists('tcat_delete_snapshot')) {
    function tcat_delete_snapshot($token)
    {
        $path = tcat_snapshot_path($token);
        if (is_file($path)) @unlink($path);
    }
}
if (!function_exists('tcat_owned_snapshot')) {
    function tcat_owned_snapshot($snapshot, $identity)
    {
        if (!is_array($snapshot)) return false;
        if (Auth::isMaster()) return true;
        $sid = isset($snapshot['owner_id']) ? (int)$snapshot['owner_id'] : 0;
        $semail = isset($snapshot['owner_email']) ? strtolower(trim((string)$snapshot['owner_email'])) : '';
        $uid = isset($identity['id']) ? (int)$identity['id'] : 0;
        $uemail = isset($identity['email']) ? strtolower(trim((string)$identity['email'])) : '';
        return ($sid > 0 && $uid > 0 && $sid === $uid) || ($semail !== '' && $uemail !== '' && $semail === $uemail);
    }
}
if (!function_exists('tcat_list_snapshots')) {
    function tcat_list_snapshots($identity)
    {
        $rows = array();
        $dir = tcat_test_dir();
        if (!is_dir($dir)) return $rows;
        $files = glob($dir . '/T*.json');
        if (!is_array($files)) return $rows;
        rsort($files, SORT_STRING);
        for ($i=0; $i<count($files) && count($rows)<50; $i++) {
            $raw = @file_get_contents($files[$i]);
            $row = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($row) || !tcat_owned_snapshot($row, $identity)) continue;
            $rows[] = $row;
        }
        return $rows;
    }
}
if (!function_exists('tcat_insert_temp_document')) {
    function tcat_insert_temp_document($pdo, $identity, $projectId, $title, $content)
    {
        $columns = tcat_table_columns($pdo, 'cpms_approval_documents');
        if (count($columns) === 0) throw new Exception('전자결재 문서 테이블을 찾을 수 없습니다.');
        $fields = array('doc_type','title','content','doc_status','current_step_order','created_by_id','created_by_name');
        $marks = array(':doc_type',':title',':content',':doc_status',':current_step_order',':created_by_id',':created_by_name');
        $params = array(
            ':doc_type'=>'proposal', ':title'=>(string)$title,
            ':content'=>ApprovalCostCorrectionService::jsonEncode($content), ':doc_status'=>'APPROVED',
            ':current_step_order'=>0, ':created_by_id'=>isset($identity['id'])?(int)$identity['id']:0,
            ':created_by_name'=>isset($identity['name'])?(string)$identity['name']:''
        );
        if (isset($columns['created_by_email'])) { $fields[]='created_by_email'; $marks[]=':created_by_email'; $params[':created_by_email']=isset($identity['email'])?(string)$identity['email']:''; }
        if (isset($columns['project_id'])) { $fields[]='project_id'; $marks[]=':project_id'; $params[':project_id']=(int)$projectId; }
        if (isset($columns['created_at'])) { $fields[]='created_at'; $marks[]='NOW()'; }
        if (isset($columns['updated_at'])) { $fields[]='updated_at'; $marks[]='NOW()'; }
        $st = $pdo->prepare("INSERT INTO cpms_approval_documents (".implode(',',$fields).") VALUES (".implode(',',$marks).")");
        $st->execute($params);
        $id = (int)$pdo->lastInsertId();
        if ($id <= 0) throw new Exception('테스트용 전자결재 문서를 만들지 못했습니다.');
        return $id;
    }
}
if (!function_exists('tcat_cleanup_temp_document')) {
    function tcat_cleanup_temp_document($pdo, $documentId)
    {
        $documentId=(int)$documentId;
        if ($documentId<=0) return;
        $tables = array(
            'cpms_approval_notifications'=>'document_id', 'cpms_approval_logs'=>'document_id',
            'cpms_approval_lines'=>'document_id', 'cpms_approval_references'=>'document_id', 'cpms_approval_files'=>'document_id'
        );
        foreach ($tables as $table=>$column) {
            if (!tcat_table_exists($pdo,$table)) continue;
            try { $pdo->prepare("DELETE FROM `".$table."` WHERE `".$column."`=:id")->execute(array(':id'=>$documentId)); } catch (Exception $e) {}
        }
        try {
            if (tcat_table_exists($pdo,'cpms_google_chat_notifications')) {
                $pdo->prepare("DELETE FROM cpms_google_chat_notifications WHERE source_id=:id AND (source_type='approval' OR source_type='APPROVAL')")->execute(array(':id'=>$documentId));
            }
        } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM cpms_approval_documents WHERE id=:id AND title LIKE '[자동반영 테스트]%' ")->execute(array(':id'=>$documentId)); } catch (Exception $e) {}
    }
}
if (!function_exists('tcat_project_name')) {
    function tcat_project_name($pdo,$projectId)
    {
        try { $st=$pdo->prepare("SELECT name FROM cpms_projects WHERE id=:id LIMIT 1"); $st->execute(array(':id'=>(int)$projectId)); return trim((string)$st->fetchColumn()); }
        catch(Exception $e){ return ''; }
    }
}
if (!function_exists('tcat_find_target')) {
    function tcat_find_target($pdo,$testType,$projectId,$correctionDate,$targetId)
    {
        $formType=''; $category='';
        if ($testType==='equipment') $formType=ApprovalCostCorrectionService::FORM_EQUIPMENT;
        elseif ($testType==='outsourcing') $formType=ApprovalCostCorrectionService::FORM_OUTSOURCING;
        elseif ($testType==='material') { $formType=ApprovalCostCorrectionService::FORM_INPUT; $category='자재비'; }
        else return null;
        $rows=ApprovalCostCorrectionService::existingTargets($pdo,$formType,$projectId,$category,$correctionDate);
        for($i=0;$i<count($rows);$i++) if(isset($rows[$i]['target_id']) && (string)$rows[$i]['target_id']===(string)$targetId) return $rows[$i];
        return null;
    }
}
if (!function_exists('tcat_resolve_vendor_id')) {
    function tcat_resolve_vendor_id($pdo,$row)
    {
        $vid=isset($row['vendor_id'])?(int)$row['vendor_id']:0;
        if($vid>0 && ApprovalCostCorrectionService::vendor($pdo,$vid)) return $vid;
        $name=isset($row['vendor_name'])?trim((string)$row['vendor_name']):'';
        if($name==='') return 0;
        $rows=ApprovalCostCorrectionService::searchVendors($pdo,$name,50);
        $found=0;
        for($i=0;$i<count($rows);$i++){
            $rn=isset($rows[$i]['name'])?trim((string)$rows[$i]['name']):'';
            if($rn===$name){
                if($found>0 && $found!==(int)$rows[$i]['id']) return 0;
                $found=(int)$rows[$i]['id'];
            }
        }
        return $found;
    }
}
if (!function_exists('tcat_enrich_target')) {
    function tcat_enrich_target($pdo,$testType,$projectId,$row)
    {
        if(!is_array($row)) return $row;
        $targetId=isset($row['target_id'])?(int)$row['target_id']:0;
        try {
            if($testType==='material'){
                $cols=tcat_table_columns($pdo,'cpms_material_items');
                $vendorSel=isset($cols['vendor_id'])?'i.vendor_id':'0 AS vendor_id';
                $st=$pdo->prepare("SELECT u.*,i.id AS master_id,i.vendor_name,i.spec,i.category,".$vendorSel." FROM cpms_material_usage u INNER JOIN cpms_material_items i ON i.id=u.material_id WHERE u.id=:id AND u.project_id=:pid LIMIT 1");
                $st->execute(array(':id'=>$targetId,':pid'=>(int)$projectId)); $db=$st->fetch(PDO::FETCH_ASSOC);
                if(is_array($db)){ $row['master_id']=(int)$db['master_id']; if(isset($db['vendor_id']))$row['vendor_id']=(int)$db['vendor_id']; }
            }elseif($testType==='equipment'){
                $cols=tcat_table_columns($pdo,'cpms_equipment_items');
                $vendorSel=isset($cols['vendor_id'])?'i.vendor_id':'0 AS vendor_id';
                $st=$pdo->prepare("SELECT u.*,i.id AS master_id,i.vendor_name,i.spec,i.category,".$vendorSel." FROM cpms_equipment_usage u INNER JOIN cpms_equipment_items i ON i.id=u.equipment_id WHERE u.id=:id AND u.project_id=:pid LIMIT 1");
                $st->execute(array(':id'=>$targetId,':pid'=>(int)$projectId)); $db=$st->fetch(PDO::FETCH_ASSOC);
                if(is_array($db)){ $row['master_id']=(int)$db['master_id']; if(isset($db['vendor_id']))$row['vendor_id']=(int)$db['vendor_id']; }
            }elseif($testType==='outsourcing'){
                $cols=tcat_table_columns($pdo,'cpms_outsourcing_costs');
                if(isset($cols['vendor_id'])){ $st=$pdo->prepare("SELECT vendor_id FROM cpms_outsourcing_costs WHERE id=:id AND project_id=:pid LIMIT 1"); $st->execute(array(':id'=>$targetId,':pid'=>(int)$projectId)); $row['vendor_id']=(int)$st->fetchColumn(); }
            }
        } catch(Exception $e) {}
        $row['resolved_vendor_id']=tcat_resolve_vendor_id($pdo,$row);
        return $row;
    }
}
if (!function_exists('tcat_snapshot_cost_meta')) {
    function tcat_snapshot_cost_meta($pdo,$targetType,$targetId)
    {
        if(!tcat_table_exists($pdo,'cpms_cost_record_meta')) return null;
        try{ $st=$pdo->prepare("SELECT * FROM cpms_cost_record_meta WHERE target_type=:t AND target_id=:id LIMIT 1"); $st->execute(array(':t'=>$targetType,':id'=>(string)$targetId)); $row=$st->fetch(PDO::FETCH_ASSOC); return is_array($row)?$row:null; }
        catch(Exception $e){ return null; }
    }
}
if (!function_exists('tcat_snapshot_db_row')) {
    function tcat_snapshot_db_row($pdo,$testType,$projectId,$targetId)
    {
        try{
            if($testType==='material'){
                $st=$pdo->prepare("SELECT * FROM cpms_material_usage WHERE id=:id AND project_id=:pid LIMIT 1");
            }elseif($testType==='equipment'){
                $st=$pdo->prepare("SELECT * FROM cpms_equipment_usage WHERE id=:id AND project_id=:pid LIMIT 1");
            }else{
                $st=$pdo->prepare("SELECT * FROM cpms_outsourcing_costs WHERE id=:id AND project_id=:pid LIMIT 1");
            }
            $st->execute(array(':id'=>(int)$targetId,':pid'=>(int)$projectId)); $row=$st->fetch(PDO::FETCH_ASSOC); return is_array($row)?$row:null;
        }catch(Exception $e){ return null; }
    }
}
if (!function_exists('tcat_snapshot_master_vendor')) {
    function tcat_snapshot_master_vendor($pdo,$testType,$row)
    {
        $masterId=isset($row['master_id'])?(int)$row['master_id']:0;
        if($masterId<=0) return null;
        $table=$testType==='material'?'cpms_material_items':($testType==='equipment'?'cpms_equipment_items':'');
        if($table==='' || !tcat_table_exists($pdo,$table)) return null;
        $cols=tcat_table_columns($pdo,$table);
        if(!isset($cols['vendor_id'])) return array('table'=>$table,'id'=>$masterId,'has_vendor_id'=>0);
        try{ $st=$pdo->prepare("SELECT vendor_id FROM `".$table."` WHERE id=:id LIMIT 1"); $st->execute(array(':id'=>$masterId)); return array('table'=>$table,'id'=>$masterId,'has_vendor_id'=>1,'vendor_id'=>$st->fetchColumn()); }
        catch(Exception $e){ return null; }
    }
}
if (!function_exists('tcat_restore_meta')) {
    function tcat_restore_meta($pdo,$targetType,$targetId,$original)
    {
        if(!tcat_table_exists($pdo,'cpms_cost_record_meta')) return;
        if(!is_array($original)){
            $pdo->prepare("DELETE FROM cpms_cost_record_meta WHERE target_type=:t AND target_id=:id")->execute(array(':t'=>$targetType,':id'=>(string)$targetId));
            return;
        }
        $cols=tcat_table_columns($pdo,'cpms_cost_record_meta');
        $sets=array(); $params=array(':t'=>$targetType,':target'=>(string)$targetId); $n=0;
        foreach($original as $col=>$value){
            if($col==='id'||!isset($cols[$col]))continue;
            $key=':v'.$n++; $sets[]='`'.$col.'`='.$key; $params[$key]=$value;
        }
        if(count($sets)>0){
            $existsSt=$pdo->prepare("SELECT id FROM cpms_cost_record_meta WHERE target_type=:t AND target_id=:target LIMIT 1");
            $existsSt->execute(array(':t'=>$targetType,':target'=>(string)$targetId));
            $exists=((int)$existsSt->fetchColumn()>0);
            if($exists){
                $sql="UPDATE cpms_cost_record_meta SET ".implode(',',$sets)." WHERE target_type=:t AND target_id=:target";
                $st=$pdo->prepare($sql); $st->execute($params);
            }else{
                $fields=array();$marks=array();$insert=array();$n=0;
                foreach($original as $col=>$value){ if(!isset($cols[$col]))continue; $key=':i'.$n++;$fields[]='`'.$col.'`';$marks[]=$key;$insert[$key]=$value; }
                if(count($fields)>0){
                    $pdo->prepare("INSERT INTO cpms_cost_record_meta (".implode(',',$fields).") VALUES (".implode(',',$marks).")")->execute($insert);
                }
            }
        }
    }
}
if (!function_exists('tcat_restore_cost')) {
    function tcat_restore_cost($pdo,$snapshot)
    {
        $type=isset($snapshot['test_type'])?(string)$snapshot['test_type']:'';
        $projectId=isset($snapshot['project_id'])?(int)$snapshot['project_id']:0;
        $targetId=isset($snapshot['target_id'])?(int)$snapshot['target_id']:0;
        $row=isset($snapshot['original_row'])&&is_array($snapshot['original_row'])?$snapshot['original_row']:array();
        if($projectId<=0||$targetId<=0||count($row)===0) throw new Exception('원상복구 원본정보가 없습니다.');
        if($type==='material'){
            $fields=array('material_id','use_date','amount','advance_yn','memo'); $table='cpms_material_usage';
        }elseif($type==='equipment'){
            $fields=array('equipment_id','use_date','work_unit','base_rate_snapshot','amount','is_manual_unit','memo'); $table='cpms_equipment_usage';
        }elseif($type==='outsourcing'){
            $fields=array('expense_date','category','company_name','amount','memo','vendor_id','is_deleted','updated_at'); $table='cpms_outsourcing_costs';
        }else throw new Exception('지원하지 않는 복구 유형입니다.');
        $cols=tcat_table_columns($pdo,$table); $sets=array();$params=array(':id'=>$targetId,':pid'=>$projectId);$n=0;
        for($i=0;$i<count($fields);$i++){ $col=$fields[$i]; if(!isset($cols[$col])||!array_key_exists($col,$row))continue; $key=':v'.$n++;$sets[]='`'.$col.'`='.$key;$params[$key]=$row[$col]; }
        if(count($sets)===0) throw new Exception('복구할 원본 컬럼을 찾지 못했습니다.');
        $pdo->prepare("UPDATE `".$table."` SET ".implode(',',$sets)." WHERE id=:id AND project_id=:pid")->execute($params);
        if(isset($snapshot['master_vendor'])&&is_array($snapshot['master_vendor'])&&!empty($snapshot['master_vendor']['has_vendor_id'])){
            $mv=$snapshot['master_vendor']; $mt=isset($mv['table'])?(string)$mv['table']:''; $mid=isset($mv['id'])?(int)$mv['id']:0;
            if($mt!==''&&$mid>0&&tcat_table_exists($pdo,$mt)){
                $safeMasterTable=str_replace('`','',$mt);
                $restoreVendorSt=$pdo->prepare("UPDATE `".$safeMasterTable."` SET vendor_id=:v WHERE id=:id");
                $restoreVendorSt->execute(array(':v'=>isset($mv['vendor_id'])?$mv['vendor_id']:null,':id'=>$mid));
            }
        }
        $targetType=$type==='material'?'material':($type==='equipment'?'equipment':'outsourcing');
        tcat_restore_meta($pdo,$targetType,(string)$targetId,isset($snapshot['original_meta'])?$snapshot['original_meta']:null);
    }
}
if (!function_exists('tcat_type_label')) {
    function tcat_type_label($type)
    {
        $map=array('labor'=>'노무비','equipment'=>'장비비','outsourcing'=>'외주비','material'=>'자재비');
        return isset($map[$type])?$map[$type]:$type;
    }
}
if (!function_exists('tcat_confirm_url')) {
    function tcat_confirm_url($snapshot)
    {
        $pid=isset($snapshot['project_id'])?(int)$snapshot['project_id']:0;
        $type=isset($snapshot['test_type'])?(string)$snapshot['test_type']:'';
        if($type==='labor'){
            $month=isset($snapshot['work_date'])?substr((string)$snapshot['work_date'],0,7):date('Y-m');
            return './?r=공사&pid='.$pid.'&tab=labor&labor_tab=timesheet&month='.rawurlencode($month);
        }
        if($type==='equipment')return './?r=공사&pid='.$pid.'&tab=equipment';
        if($type==='outsourcing')return './?r=공사&pid='.$pid.'&tab=outsourcing';
        return './?r=공사&pid='.$pid.'&tab=materials';
    }
}

$identity=tcat_user_identity($user);

/* AJAX */
if($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['ajax'])){
    $ajax=trim((string)$_GET['ajax']);
    $projectId=isset($_GET['project_id'])?(int)$_GET['project_id']:0;
    $workDate=tcat_valid_date(isset($_GET['work_date'])?$_GET['work_date']:'');
    if($ajax==='workers'){
        tcat_json(array('ok'=>true,'rows'=>ApprovalCostCorrectionService::laborWorkersForMonth($pdo,$projectId,$workDate)),200);
    }
    if($ajax==='targets'){
        $testType=isset($_GET['test_type'])?trim((string)$_GET['test_type']):'';
        $formType='';$category='';
        if($testType==='equipment')$formType=ApprovalCostCorrectionService::FORM_EQUIPMENT;
        elseif($testType==='outsourcing')$formType=ApprovalCostCorrectionService::FORM_OUTSOURCING;
        elseif($testType==='material'){ $formType=ApprovalCostCorrectionService::FORM_INPUT;$category='자재비'; }
        else tcat_json(array('ok'=>false,'rows'=>array(),'message'=>'비용유형을 확인해주세요.'),400);
        $rows=ApprovalCostCorrectionService::existingTargets($pdo,$formType,$projectId,$category,$workDate);
        $result=array();
        for($i=0;$i<count($rows);$i++)$result[]=tcat_enrich_target($pdo,$testType,$projectId,$rows[$i]);
        tcat_json(array('ok'=>true,'rows'=>$result),200);
    }
    tcat_json(array('ok'=>false,'message'=>'지원하지 않는 AJAX 요청입니다.'),404);
}

$message='';$messageType='info';$justApplied=null;

/* POST - 실제 반영 */
if($_SERVER['REQUEST_METHOD']==='POST'){
    $csrf=isset($_POST['_csrf'])?(string)$_POST['_csrf']:'';
    if(!csrf_check($csrf)){ $message='보안 토큰이 만료되었습니다. 새로고침 후 다시 시도해주세요.';$messageType='danger'; }
    else{
        $action=isset($_POST['action'])?trim((string)$_POST['action']):'';
        try{
            if($action==='apply_test'){
                $testType=isset($_POST['test_type'])?trim((string)$_POST['test_type']):'';
                if(!in_array($testType,array('labor','equipment','outsourcing','material'),true))throw new Exception('테스트 유형을 선택해주세요.');
                $projectId=isset($_POST['project_id'])?(int)$_POST['project_id']:0;
                $projectName=tcat_project_name($pdo,$projectId);
                if($projectId<=0||$projectName==='')throw new Exception('현장을 선택해주세요.');
                $workDate=tcat_valid_date(isset($_POST['work_date'])?$_POST['work_date']:'');
                if($workDate==='')throw new Exception('수정 신청 날짜를 선택해주세요.');
                $token=tcat_make_token();
                $marker='[AUTO_APPLY_TEST:'.$token.']';
                $content=array(
                    'cpms_cost_correction'=>'1','correction_mode'=>'MODIFY','project_id'=>$projectId,'project_name'=>$projectName,
                    'use_date'=>$workDate,'reason'=>$marker.' 자동반영 실테스트','auto_apply_status'=>'PENDING'
                );
                $snapshot=array(
                    'token'=>$token,'owner_id'=>isset($identity['id'])?(int)$identity['id']:0,'owner_email'=>isset($identity['email'])?(string)$identity['email']:'',
                    'test_type'=>$testType,'project_id'=>$projectId,'project_name'=>$projectName,'created_at'=>date('Y-m-d H:i:s'),'status'=>'prepared'
                );

                if($testType==='labor'){
                    $workerName=isset($_POST['worker_name'])?trim((string)$_POST['worker_name']):'';
                    $requested=isset($_POST['requested_gongsu'])?(float)$_POST['requested_gongsu']:-1;
                    $allowedG=array(0.5,1.0,1.1,1.2,1.3,1.4,1.5,2.0);$valid=false;
                    for($gi=0;$gi<count($allowedG);$gi++)if(abs($requested-$allowedG[$gi])<0.0001){$valid=true;break;}
                    if(!$valid)throw new Exception('테스트 변경공수를 선택해주세요.');
                    $workers=ApprovalCostCorrectionService::laborWorkersForMonth($pdo,$projectId,$workDate);$selected=null;
                    for($wi=0;$wi<count($workers);$wi++)if(isset($workers[$wi]['worker_name'])&&(string)$workers[$wi]['worker_name']===$workerName){$selected=$workers[$wi];break;}
                    if(!is_array($selected))throw new Exception('선택한 월의 근로자를 찾을 수 없습니다.');
                    $current=isset($selected['current_gongsu'])?(float)$selected['current_gongsu']:0.0;
                    if(abs($current-$requested)<0.0001)throw new Exception('현재 공수와 다른 값을 선택해주세요.');
                    $content['correction_form_type']='labor';
                    $content['labor_changes']=array(array('worker_name'=>$workerName,'current_gongsu'=>$current,'requested_gongsu'=>$requested));
                    $content['worker_name']=$workerName;$content['current_gongsu']=$current;$content['requested_gongsu']=$requested;
                    $snapshot['worker_name']=$workerName;$snapshot['work_date']=$workDate;$snapshot['original_amount']=$current;$snapshot['test_amount']=$requested;
                }else{
                    $targetId=isset($_POST['target_id'])?trim((string)$_POST['target_id']):'';
                    $testAmount=tcat_money(isset($_POST['test_amount'])?$_POST['test_amount']:0);
                    if($targetId===''||$testAmount<=0)throw new Exception('기존자료와 테스트 변경금액을 선택해주세요.');
                    $target=tcat_find_target($pdo,$testType,$projectId,$workDate,$targetId);
                    if(!is_array($target))throw new Exception('선택한 수정 신청 날짜의 마감기간에서 원본자료를 찾을 수 없습니다.');
                    $target=tcat_enrich_target($pdo,$testType,$projectId,$target);
                    $oldAmount=isset($target['amount'])?(float)$target['amount']:0.0;
                    if(abs($oldAmount-$testAmount)<0.0001)throw new Exception('현재 금액과 다른 테스트 금액을 입력해주세요.');
                    $vendorId=isset($target['resolved_vendor_id'])?(int)$target['resolved_vendor_id']:0;
                    if($vendorId<=0)throw new Exception('이 원본자료의 업체를 업체관리와 연결할 수 없습니다. 다른 자료를 선택하거나 업체관리를 먼저 확인해주세요.');
                    $formType=$testType==='equipment'?'equipment':($testType==='outsourcing'?'outsourcing':'input');
                    $targetType=$testType==='material'?'material':$testType;
                    $category=$testType==='material'?'자재비':($testType==='equipment'?'장비비':'외주비');
                    $actualDate=isset($target['use_date'])?tcat_valid_date($target['use_date']):'';
                    if($actualDate==='')throw new Exception('원본자료 날짜를 확인할 수 없습니다.');
                    $content['correction_form_type']=$formType;$content['cost_category']=$category;$content['target_type']=$targetType;
                    $content['target_id']=(string)$targetId;$content['vendor_id']=$vendorId;$content['vendor_name']=isset($target['vendor_name'])?(string)$target['vendor_name']:'';
                    $content['item_name']=isset($target['item_name'])?(string)$target['item_name']:'';$content['quantity']=isset($target['quantity'])?(float)$target['quantity']:1;
                    $content['unit_price']=isset($target['unit_price'])?(float)$target['unit_price']:$testAmount;$content['amount']=$testAmount;$content['memo']=isset($target['memo'])?(string)$target['memo']:'';
                    $content['use_date']=$actualDate;$content['old_target']=$target;
                    try{$content['settlement_ym']=CostChangeService::settlementYm($targetType,$actualDate);}catch(Exception $e){$content['settlement_ym']=substr($actualDate,0,7);}
                    $snapshot['target_id']=(string)$targetId;$snapshot['work_date']=$actualDate;$snapshot['original_amount']=$oldAmount;$snapshot['test_amount']=$testAmount;
                    $snapshot['original_row']=tcat_snapshot_db_row($pdo,$testType,$projectId,$targetId);
                    if(!is_array($snapshot['original_row']))throw new Exception('원상복구용 원본자료를 읽지 못했습니다.');
                    $snapshot['original_meta']=tcat_snapshot_cost_meta($pdo,$targetType,$targetId);
                    $snapshot['master_vendor']=tcat_snapshot_master_vendor($pdo,$testType,$target);
                    $snapshot['item_name']=isset($target['item_name'])?(string)$target['item_name']:'';$snapshot['vendor_name']=isset($target['vendor_name'])?(string)$target['vendor_name']:'';
                }

                tcat_save_snapshot($snapshot);
                $docId=0;
                try{
                    $docId=tcat_insert_temp_document($pdo,$identity,$projectId,'[자동반영 테스트] '.$token.' '.tcat_type_label($testType),$content);
                    $result=ApprovalCostCorrectionService::applyApprovedDocument($pdo,$docId,$user);
                    if(!is_array($result)||empty($result['ok'])||!empty($result['skipped']))throw new Exception(is_array($result)&&isset($result['message'])?$result['message']:'자동반영 테스트에 실패했습니다.');
                    $apply=isset($result['result'])&&is_array($result['result'])?$result['result']:array();
                    if($testType==='labor'){
                        $ids=isset($apply['target_ids'])&&is_array($apply['target_ids'])?$apply['target_ids']:array();
                        if(count($ids)===0 && isset($apply['target_id']))$ids=explode(',',(string)$apply['target_id']);
                        $snapshot['override_ids']=$ids;
                    }
                    $snapshot['status']='applied';$snapshot['applied_at']=date('Y-m-d H:i:s');
                    tcat_save_snapshot($snapshot);
                    tcat_cleanup_temp_document($pdo,$docId);$docId=0;
                    $message=tcat_type_label($testType).' 실제 자동반영 테스트가 완료되었습니다. 공사 화면에서 확인한 뒤 반드시 원상복구하세요.';$messageType='success';$justApplied=$snapshot;
                }catch(Exception $inner){
                    if($docId>0)tcat_cleanup_temp_document($pdo,$docId);
                    tcat_delete_snapshot($token);
                    throw $inner;
                }
            }elseif($action==='rollback_test'){
                $token=isset($_POST['test_token'])?trim((string)$_POST['test_token']):'';
                $snapshot=tcat_load_snapshot($token);
                if(!is_array($snapshot)||!tcat_owned_snapshot($snapshot,$identity))throw new Exception('복구 가능한 테스트 기록을 찾을 수 없습니다.');
                $testType=isset($snapshot['test_type'])?(string)$snapshot['test_type']:'';
                $pdo->beginTransaction();
                try{
                    if($testType==='labor'){
                        $ids=isset($snapshot['override_ids'])&&is_array($snapshot['override_ids'])?$snapshot['override_ids']:array();
                        if(count($ids)===0)throw new Exception('복구할 노무비 테스트 행 번호가 없습니다.');
                        for($ii=0;$ii<count($ids);$ii++){
                            $oid=(int)$ids[$ii]; if($oid<=0)continue;
                            $st=$pdo->prepare("DELETE FROM cpms_labor_gongsu_overrides WHERE id=:id AND project_id=:pid AND reason LIKE :reason");
                            $st->execute(array(':id'=>$oid,':pid'=>(int)$snapshot['project_id'],':reason'=>'[AUTO_APPLY_TEST:'.$token.']%'));
                        }
                    }else{
                        tcat_restore_cost($pdo,$snapshot);
                    }
                    $pdo->commit();
                }catch(Exception $rb){ if($pdo->inTransaction())$pdo->rollBack();throw $rb; }
                tcat_delete_snapshot($token);
                $message=tcat_type_label($testType).' 테스트 값을 원상복구했습니다.';$messageType='success';
            }
        }catch(Exception $e){ $message=$e->getMessage();$messageType='danger'; }
    }
}

$projects=ApprovalCostCorrectionService::projects($pdo);
$snapshots=tcat_list_snapshots($identity);
$today=date('Y-m-d');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS 자동반영 실테스트 V2</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#0f172a;font-family:Arial,'Malgun Gothic',sans-serif}.wrap{max-width:1120px;margin:0 auto;padding:28px 18px 60px}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px}.top h1{margin:0;font-size:26px}.muted{color:#64748b;font-size:13px;line-height:1.6}.back,.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:11px;padding:10px 14px;text-decoration:none;font-weight:800;cursor:pointer}.back{background:white;color:#334155;border:1px solid #cbd5e1}.warning{border:2px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:16px;padding:15px 17px;line-height:1.65;margin-bottom:18px}.alert{border-radius:14px;padding:14px 16px;margin-bottom:18px;font-weight:800}.alert-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.alert-danger{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}.card{background:#fff;border:1px solid #dbe2ea;border-radius:18px;padding:20px;margin-bottom:18px;box-shadow:0 4px 14px rgba(15,23,42,.05)}.card h2{margin:0 0 6px;font-size:18px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px}.field.full{grid-column:1/-1}.field label{display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:6px}.field select,.field input{width:100%;border:1px solid #cbd5e1;border-radius:11px;padding:11px 12px;background:#fff;font-size:14px}.current{border:1px solid #e2e8f0;background:#f8fafc;border-radius:11px;padding:11px 12px;font-weight:800;min-height:42px}.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:16px}.btn-dark{background:#0f172a;color:#fff}.btn-blue{background:#2563eb;color:#fff}.btn-red{background:#dc2626;color:#fff}.result{border:2px solid #22c55e;background:#f0fdf4;border-radius:16px;padding:17px;margin-bottom:18px}.result strong{font-size:19px}.test-row{display:flex;justify-content:space-between;align-items:center;gap:15px;border-top:1px solid #e2e8f0;padding:14px 0}.test-main{min-width:0}.test-title{font-weight:900;word-break:break-all}.test-sub{font-size:12px;color:#64748b;margin-top:5px}.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:11px;font-weight:900;margin-right:6px}.hidden{display:none!important}@media(max-width:720px){.grid{grid-template-columns:1fr}.field.full{grid-column:auto}.test-row{align-items:flex-start;flex-direction:column}.wrap{padding:18px 12px 50px}}
</style>
</head>
<body>
<div class="wrap">
<div class="top"><div><h1>전자결재 자동반영 실테스트 V2</h1><div class="muted">노무비 · 장비비 · 외주비 · 자재비 / 실제 최종승인 자동반영 코드 호출</div></div><a class="back" href="./?r=approval_home">전자결재로 돌아가기</a></div>
<div class="warning"><b>진짜 공사 원가 데이터가 잠깐 변경됩니다.</b> 테스트 후 반드시 원상복구하세요.<br>결재자에게 테스트 승인을 요청하지 않고, <b>최종승인 직후 실행되는 ApprovalCostCorrectionService::applyApprovedDocument()</b>만 그대로 실행합니다.</div>
<?php if($message!==''):?><div class="alert alert-<?php echo tcat_h($messageType);?>"><?php echo tcat_h($message);?></div><?php endif;?>
<?php if(is_array($justApplied)):?>
<div class="result"><strong><?php echo tcat_h(tcat_type_label($justApplied['test_type']));?> 실제 반영 완료</strong><div style="margin-top:8px"><?php echo tcat_h($justApplied['project_name']);?> · <?php echo tcat_h(isset($justApplied['work_date'])?$justApplied['work_date']:'');?></div><div style="margin-top:6px;font-weight:800"><?php if($justApplied['test_type']==='labor'):?><?php echo tcat_h($justApplied['worker_name']);?> : <?php echo tcat_h(number_format((float)$justApplied['original_amount'],1));?> → <?php echo tcat_h(number_format((float)$justApplied['test_amount'],1));?> 공수<?php else:?><?php echo tcat_h(isset($justApplied['vendor_name'])?$justApplied['vendor_name']:'');?> <?php echo tcat_h(isset($justApplied['item_name'])?$justApplied['item_name']:'');?> : <?php echo number_format((float)$justApplied['original_amount']);?>원 → <?php echo number_format((float)$justApplied['test_amount']);?>원<?php endif;?></div><div class="actions"><a target="_blank" class="btn btn-blue" href="<?php echo tcat_h(tcat_confirm_url($justApplied));?>">공사 화면에서 직접 확인</a><form method="post" style="margin:0" onsubmit="return confirm('방금 테스트 값을 원상복구할까요?');"><input type="hidden" name="_csrf" value="<?php echo tcat_h(csrf_token());?>"><input type="hidden" name="action" value="rollback_test"><input type="hidden" name="test_token" value="<?php echo tcat_h($justApplied['token']);?>"><button type="submit" class="btn btn-red">테스트 원상복구</button></form></div></div>
<?php endif;?>

<div class="card"><h2>1. 실제 자동반영 테스트</h2><div class="muted">비용유형 → 현장 → 수정 신청 날짜 순서로 고르면 그 기간의 실제 원본자료를 선택할 수 있습니다.</div>
<form method="post" id="testForm" onsubmit="return confirm('실제 공사 원가자료에 테스트 값을 잠깐 반영합니다. 계속할까요?');"><input type="hidden" name="_csrf" value="<?php echo tcat_h(csrf_token());?>"><input type="hidden" name="action" value="apply_test">
<div class="grid">
<div class="field"><label for="testType">비용유형</label><select id="testType" name="test_type" required><option value="labor">노무비</option><option value="equipment">장비비</option><option value="outsourcing">외주비</option><option value="material">자재비</option></select></div>
<div class="field"><label for="projectId">현장</label><select id="projectId" name="project_id" required><option value="">현장을 선택해주세요</option><?php for($p=0;$p<count($projects);$p++):?><option value="<?php echo isset($projects[$p]['id'])?(int)$projects[$p]['id']:0;?>"><?php echo tcat_h(isset($projects[$p]['name'])?$projects[$p]['name']:'');?></option><?php endfor;?></select></div>
<div class="field"><label for="workDate">수정 신청 날짜</label><input id="workDate" name="work_date" type="date" value="<?php echo tcat_h($today);?>" required></div>
<div class="field"><label>현재값</label><div class="current" id="currentValue">-</div></div>
<div id="laborFields" class="field full"><div class="grid" style="margin-top:0"><div class="field"><label for="workerName">근로자</label><select id="workerName" name="worker_name" disabled><option value="">현장과 날짜를 먼저 선택해주세요</option></select></div><div class="field"><label for="requestedGongsu">테스트 변경공수</label><select id="requestedGongsu" name="requested_gongsu"><option value="">변경공수 선택</option><option value="0.5">0.5 공수</option><option value="1.0">1.0 공수</option><option value="1.1">1.1 공수</option><option value="1.2">1.2 공수</option><option value="1.3">1.3 공수</option><option value="1.4">1.4 공수</option><option value="1.5">1.5 공수</option><option value="2.0">2.0 공수</option></select></div></div></div>
<div id="costFields" class="field full hidden"><div class="grid" style="margin-top:0"><div class="field full"><label for="targetId">기존자료</label><select id="targetId" name="target_id" disabled><option value="">현장과 날짜를 먼저 선택해주세요</option></select></div><div class="field"><label for="testAmount">테스트 변경금액</label><input id="testAmount" name="test_amount" inputmode="numeric" placeholder="현재금액과 다른 금액"></div><div class="field"><label>원본 설명</label><div class="current" id="targetDescription">-</div></div></div></div>
</div><div class="actions"><button type="submit" class="btn btn-dark">실제 자동반영 테스트 실행</button></div></form></div>

<div class="card"><h2>2. 복구 가능한 테스트 기록</h2><div class="muted">브라우저를 닫아도 복구정보는 storage에 남습니다. 확인이 끝난 테스트는 여기서 원상복구하세요.</div>
<?php if(count($snapshots)===0):?><div class="current" style="margin-top:14px">현재 남아 있는 테스트 기록이 없습니다.</div><?php else:?><?php for($i=0;$i<count($snapshots);$i++):$sn=$snapshots[$i];?><div class="test-row"><div class="test-main"><div class="test-title"><span class="badge">TEST</span><?php echo tcat_h(tcat_type_label(isset($sn['test_type'])?$sn['test_type']:''));?> · <?php echo tcat_h(isset($sn['project_name'])?$sn['project_name']:'');?></div><div class="test-sub"><?php echo tcat_h(isset($sn['token'])?$sn['token']:'');?> · <?php echo tcat_h(isset($sn['work_date'])?$sn['work_date']:'');?> · <?php if(isset($sn['test_type'])&&$sn['test_type']==='labor'):?><?php echo tcat_h(isset($sn['worker_name'])?$sn['worker_name']:'');?> / <?php echo tcat_h(number_format((float)$sn['original_amount'],1));?> → <?php echo tcat_h(number_format((float)$sn['test_amount'],1));?> 공수<?php else:?><?php echo number_format((float)$sn['original_amount']);?>원 → <?php echo number_format((float)$sn['test_amount']);?>원<?php endif;?> · <?php echo tcat_h(isset($sn['created_at'])?$sn['created_at']:'');?></div></div><div class="actions" style="margin-top:0"><a target="_blank" class="btn btn-blue" href="<?php echo tcat_h(tcat_confirm_url($sn));?>">확인</a><form method="post" style="margin:0" onsubmit="return confirm('이 테스트 값을 원상복구할까요?');"><input type="hidden" name="_csrf" value="<?php echo tcat_h(csrf_token());?>"><input type="hidden" name="action" value="rollback_test"><input type="hidden" name="test_token" value="<?php echo tcat_h(isset($sn['token'])?$sn['token']:'');?>"><button type="submit" class="btn btn-red">원상복구</button></form></div></div><?php endfor;?><?php endif;?></div>
</div>
<script>
(function(){
'use strict';
var type=document.getElementById('testType'),project=document.getElementById('projectId'),date=document.getElementById('workDate');
var laborFields=document.getElementById('laborFields'),costFields=document.getElementById('costFields'),worker=document.getElementById('workerName'),gongsu=document.getElementById('requestedGongsu');
var target=document.getElementById('targetId'),testAmount=document.getElementById('testAmount'),current=document.getElementById('currentValue'),desc=document.getElementById('targetDescription');
var targetRows={};
function url(params){var a=[];for(var k in params)if(Object.prototype.hasOwnProperty.call(params,k))a.push(encodeURIComponent(k)+'='+encodeURIComponent(params[k]));return 'test_approval_cost_apply.php?'+a.join('&');}
function money(v){var n=String(v||'').replace(/[^0-9]/g,'');return n?n.replace(/\B(?=(\d{3})+(?!\d))/g,','):'';}
function targetLabel(r){var a=[];if(r.use_date)a.push(r.use_date);if(r.vendor_name)a.push(r.vendor_name);if(r.item_name)a.push(r.item_name);a.push(Number(r.amount||0).toLocaleString('ko-KR')+'원');if(!Number(r.resolved_vendor_id||0))a.push('[업체연결 필요]');return a.join(' / ');}
function applyType(){var labor=type.value==='labor';laborFields.classList.toggle('hidden',!labor);costFields.classList.toggle('hidden',labor);worker.required=labor;gongsu.required=labor;target.required=!labor;testAmount.required=!labor;current.textContent='-';desc.textContent='-';loadData();}
function loadData(){var pid=project.value,d=date.value;if(!pid||!d){worker.disabled=true;target.disabled=true;return;}if(type.value==='labor')loadWorkers(pid,d);else loadTargets(pid,d);}
function loadWorkers(pid,d){worker.disabled=true;worker.innerHTML='<option value="">불러오는 중...</option>';fetch(url({ajax:'workers',project_id:pid,work_date:d}),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(data){worker.innerHTML='';var first=document.createElement('option');first.value='';first.textContent=data.ok&&data.rows&&data.rows.length?'근로자를 선택해주세요':'해당 월 노무비 인원이 없습니다';worker.appendChild(first);if(data.ok&&data.rows){for(var i=0;i<data.rows.length;i++){var row=data.rows[i]||{},name=row.worker_name||'';if(!name)continue;var o=document.createElement('option');o.value=name;o.textContent=name;o.setAttribute('data-current',String(row.current_gongsu||0));worker.appendChild(o);}}worker.disabled=false;}).catch(function(){worker.innerHTML='<option>불러오기 실패</option>';worker.disabled=false;});}
function loadTargets(pid,d){targetRows={};target.disabled=true;target.innerHTML='<option value="">불러오는 중...</option>';fetch(url({ajax:'targets',project_id:pid,work_date:d,test_type:type.value}),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(data){target.innerHTML='';var first=document.createElement('option');first.value='';first.textContent=data.ok&&data.rows&&data.rows.length?'기존자료를 선택해주세요':'해당 기간 기존자료가 없습니다';target.appendChild(first);if(data.ok&&data.rows){for(var i=0;i<data.rows.length;i++){var r=data.rows[i]||{},id=String(r.target_id||'');if(!id)continue;targetRows[id]=r;var o=document.createElement('option');o.value=id;o.textContent=targetLabel(r);target.appendChild(o);}}target.disabled=false;}).catch(function(){target.innerHTML='<option>불러오기 실패</option>';target.disabled=false;});}
worker.addEventListener('change',function(){var o=worker.options[worker.selectedIndex],v=o?o.getAttribute('data-current'):null;current.textContent=v!==null&&worker.value?Number(v).toFixed(1)+' 공수':'-';});
target.addEventListener('change',function(){var r=targetRows[String(target.value||'')];if(!r){current.textContent='-';desc.textContent='-';return;}current.textContent=Number(r.amount||0).toLocaleString('ko-KR')+'원';desc.textContent=(r.vendor_name||'-')+' / '+(r.item_name||'-');testAmount.value=money(Math.max(1,Number(r.amount||0)+1000));});
testAmount.addEventListener('input',function(){this.value=money(this.value);});
type.addEventListener('change',applyType);project.addEventListener('change',loadData);date.addEventListener('change',loadData);applyType();
})();
</script>
</body>
</html>
