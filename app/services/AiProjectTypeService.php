<?php
/**
 * Company-managed project types and comparable-project selection.
 * PHP 5.6 / MySQL 5.6 compatible.
 */
namespace App\Services;

use App\Core\Db;
use App\Core\Auth;
use PDO;
use Exception;

class AiProjectTypeService
{
    const TYPE_TABLE = 'cpms_ai_project_types';
    const ASSIGN_TABLE = 'cpms_ai_project_type_assignments';
    const HISTORY_TABLE = 'cpms_ai_project_type_history';
    const SNAPSHOT_TABLE = 'cpms_ai_daily_snapshots';

    private static function pdo($pdo)
    {
        return $pdo ? $pdo : Db::pdo();
    }

    private static function actor()
    {
        $user = Auth::user();
        return array(
            'id' => is_array($user) && isset($user['id']) ? (int)$user['id'] : 0,
            'name' => is_array($user) && isset($user['name']) ? trim((string)$user['name']) : ''
        );
    }

    public static function tableExists($pdo, $table)
    {
        if (!$pdo || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) return false;
        try {
            $st = $pdo->prepare('SHOW TABLES LIKE :table');
            if (!$st || !$st->execute(array(':table' => $table))) return false;
            return $st->fetchColumn() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function columnExists($pdo,$table,$column)
    {
        if(!$pdo||!preg_match('/^[A-Za-z0-9_]+$/',(string)$table)||!preg_match('/^[A-Za-z0-9_]+$/',(string)$column))return false;
        try{$st=$pdo->prepare('SHOW COLUMNS FROM `'.$table.'` LIKE :column');return $st&&$st->execute(array(':column'=>$column))&&$st->fetch(PDO::FETCH_ASSOC)!==false;}catch(Exception $e){return false;}
    }

    public static function installOrUpdate($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return array('ok' => false, 'message' => 'DB 연결 상태를 확인할 수 없습니다.');
        $sql = array(
            "CREATE TABLE IF NOT EXISTS `" . self::TYPE_TABLE . "` (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              type_code VARCHAR(60) NOT NULL,
              type_name VARCHAR(120) NOT NULL,
              description VARCHAR(500) NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sort_order INT NOT NULL DEFAULT 100,
              created_by INT UNSIGNED NULL,
              created_by_name VARCHAR(100) NULL,
              created_at DATETIME NOT NULL,
              updated_by INT UNSIGNED NULL,
              updated_by_name VARCHAR(100) NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE KEY uk_ai_project_type_code (type_code),
              KEY idx_ai_project_type_active (is_active,sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `" . self::ASSIGN_TABLE . "` (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              project_id INT UNSIGNED NOT NULL,
              project_type_id INT UNSIGNED NOT NULL,
              assigned_by INT UNSIGNED NULL,
              assigned_by_name VARCHAR(100) NULL,
              assigned_at DATETIME NOT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              updated_at DATETIME NOT NULL,
              UNIQUE KEY uk_ai_project_type_project (project_id),
              KEY idx_ai_project_type_assignment (project_type_id,project_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `" . self::HISTORY_TABLE . "` (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              entity_type VARCHAR(30) NOT NULL,
              entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
              project_id INT UNSIGNED NULL,
              action_type VARCHAR(30) NOT NULL,
              old_data MEDIUMTEXT NULL,
              new_data MEDIUMTEXT NULL,
              reason VARCHAR(500) NULL,
              actor_employee_id INT UNSIGNED NULL,
              actor_name VARCHAR(100) NULL,
              changed_at DATETIME NOT NULL,
              KEY idx_ai_project_type_history_entity (entity_type,entity_id,changed_at),
              KEY idx_ai_project_type_history_project (project_id,changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        try {
            foreach ($sql as $statement) {
                if ($pdo->exec($statement) === false) return array('ok' => false, 'message' => '현장유형 구조를 설치하지 못했습니다.');
            }
            if(!self::columnExists($pdo,self::ASSIGN_TABLE,'is_active')&&$pdo->exec('ALTER TABLE `'.self::ASSIGN_TABLE.'` ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1')===false)return array('ok'=>false,'message'=>'현장유형 지정 보존구조를 추가하지 못했습니다.');
            return array('ok' => true, 'message' => '현장유형 구조 설치를 확인했습니다.');
        } catch (Exception $e) {
            error_log('[AI Project Type] install failed: ' . $e->getMessage());
            return array('ok' => false, 'message' => '현장유형 구조 설치 중 오류가 발생했습니다.');
        }
    }

    public static function isInstalled($pdo = null)
    {
        $pdo = self::pdo($pdo);
        return self::tableExists($pdo, self::TYPE_TABLE)
            && self::tableExists($pdo, self::ASSIGN_TABLE)
            && self::tableExists($pdo, self::HISTORY_TABLE);
    }

    public static function schemaStatus($pdo = null)
    {
        $pdo = self::pdo($pdo);
        return array(
            'db_available' => (bool)$pdo,
            'installed' => self::isInstalled($pdo),
            'types' => self::tableExists($pdo, self::TYPE_TABLE),
            'assignments' => self::tableExists($pdo, self::ASSIGN_TABLE),
            'history' => self::tableExists($pdo, self::HISTORY_TABLE)
        );
    }

    private static function encode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    private static function history($pdo, $entityType, $entityId, $projectId, $action, $old, $new, $reason)
    {
        $actor = self::actor();
        $sql = 'INSERT INTO `' . self::HISTORY_TABLE . '` (entity_type,entity_id,project_id,action_type,old_data,new_data,reason,actor_employee_id,actor_name,changed_at) VALUES (:entity_type,:entity_id,:project_id,:action_type,:old_data,:new_data,:reason,:actor_id,:actor_name,:changed_at)';
        $st = $pdo->prepare($sql);
        if (!$st) return false;
        return $st->execute(array(
            ':entity_type' => $entityType, ':entity_id' => (int)$entityId,
            ':project_id' => $projectId === null ? null : (int)$projectId,
            ':action_type' => $action, ':old_data' => self::encode($old),
            ':new_data' => self::encode($new), ':reason' => trim((string)$reason),
            ':actor_id' => $actor['id'] > 0 ? $actor['id'] : null,
            ':actor_name' => $actor['name'], ':changed_at' => date('Y-m-d H:i:s')
        ));
    }

    private static function normalizeCode($code)
    {
        $code = strtoupper(trim((string)$code));
        $code = preg_replace('/[^A-Z0-9_\-]/', '_', $code);
        return substr($code, 0, 60);
    }

    public static function saveType($pdo, $data, $reason)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return array('ok' => false, 'message' => '현장유형 구조를 먼저 설치해주세요.');
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $code = self::normalizeCode(isset($data['type_code']) ? $data['type_code'] : '');
        $name = trim(isset($data['type_name']) ? (string)$data['type_name'] : '');
        $description = trim(isset($data['description']) ? (string)$data['description'] : '');
        $active = !empty($data['is_active']) ? 1 : 0;
        $sort = isset($data['sort_order']) ? (int)$data['sort_order'] : 100;
        if ($code === '' || $name === '') return array('ok' => false, 'message' => '현장유형 코드와 이름을 입력해주세요.');
        $actor = self::actor();
        $old = array();
        try {
            if ($id > 0) {
                $read = $pdo->prepare('SELECT * FROM `' . self::TYPE_TABLE . '` WHERE id=:id LIMIT 1');
                if (!$read || !$read->execute(array(':id' => $id))) return array('ok' => false, 'message' => '기존 현장유형을 확인하지 못했습니다.');
                $old = $read->fetch(PDO::FETCH_ASSOC);
                if (!is_array($old)) return array('ok' => false, 'message' => '수정할 현장유형이 없습니다.');
                $st = $pdo->prepare('UPDATE `' . self::TYPE_TABLE . '` SET type_code=:code,type_name=:name,description=:description,is_active=:active,sort_order=:sort,updated_by=:actor,updated_by_name=:actor_name,updated_at=:updated WHERE id=:id');
                $params = array(':code'=>$code,':name'=>$name,':description'=>$description,':active'=>$active,':sort'=>$sort,':actor'=>$actor['id']>0?$actor['id']:null,':actor_name'=>$actor['name'],':updated'=>date('Y-m-d H:i:s'),':id'=>$id);
            } else {
                $now = date('Y-m-d H:i:s');
                $st = $pdo->prepare('INSERT INTO `' . self::TYPE_TABLE . '` (type_code,type_name,description,is_active,sort_order,created_by,created_by_name,created_at,updated_by,updated_by_name,updated_at) VALUES (:code,:name,:description,:active,:sort,:actor,:actor_name,:created,:actor2,:actor_name2,:updated)');
                $params = array(':code'=>$code,':name'=>$name,':description'=>$description,':active'=>$active,':sort'=>$sort,':actor'=>$actor['id']>0?$actor['id']:null,':actor_name'=>$actor['name'],':created'=>$now,':actor2'=>$actor['id']>0?$actor['id']:null,':actor_name2'=>$actor['name'],':updated'=>$now);
            }
            if (!$st || !$st->execute($params)) return array('ok' => false, 'message' => '현장유형을 저장하지 못했습니다.');
            if ($id <= 0) $id = (int)$pdo->lastInsertId();
            $new = array('id'=>$id,'type_code'=>$code,'type_name'=>$name,'description'=>$description,'is_active'=>$active,'sort_order'=>$sort);
            self::history($pdo, 'PROJECT_TYPE', $id, null, empty($old)?'CREATE':'UPDATE', $old, $new, $reason);
            return array('ok' => true, 'message' => '현장유형을 저장했습니다.', 'id' => $id);
        } catch (Exception $e) {
            error_log('[AI Project Type] save failed: ' . $e->getMessage());
            return array('ok' => false, 'message' => '현장유형 저장 중 오류가 발생했습니다.');
        }
    }

    public static function listTypes($pdo = null, $activeOnly = false)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::tableExists($pdo, self::TYPE_TABLE)) return array();
        try {
            $sql = 'SELECT * FROM `' . self::TYPE_TABLE . '`';
            if ($activeOnly) $sql .= ' WHERE is_active=1';
            $sql .= ' ORDER BY sort_order,type_name,id';
            $st = $pdo->query($sql);
            if (!$st) return array();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    public static function assignProject($pdo, $projectId, $typeId, $reason)
    {
        $pdo = self::pdo($pdo);
        $projectId = (int)$projectId;
        $typeId = (int)$typeId;
        if (!$pdo || !self::isInstalled($pdo) || $projectId <= 0) return array('ok'=>false,'message'=>'현장유형 저장 조건을 확인해주세요.');
        $actor = self::actor();
        try {
            $st = $pdo->prepare('SELECT * FROM `' . self::ASSIGN_TABLE . '` WHERE project_id=:project LIMIT 1');
            if (!$st || !$st->execute(array(':project'=>$projectId))) return array('ok'=>false,'message'=>'기존 현장유형을 확인하지 못했습니다.');
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($old)) $old = array();
            if ($typeId <= 0) {
                if (!empty($old) && isset($old['is_active']) && (int)$old['is_active'] === 0) return array('ok'=>true,'message'=>'현장유형은 이미 미지정 상태입니다.');
                if (!empty($old)) {
                    $deactivate = $pdo->prepare('UPDATE `' . self::ASSIGN_TABLE . '` SET is_active=0,updated_at=:updated WHERE project_id=:project');
                    if (!$deactivate || !$deactivate->execute(array(':updated'=>date('Y-m-d H:i:s'),':project'=>$projectId))) return array('ok'=>false,'message'=>'현장유형 지정을 해제하지 못했습니다.');
                    self::history($pdo,'PROJECT_ASSIGNMENT',(int)$old['id'],$projectId,'UNASSIGN',$old,array(),'사용자 요청: ' . trim((string)$reason));
                }
                return array('ok'=>true,'message'=>'현장유형을 미지정 상태로 저장했습니다.');
            }
            $check = $pdo->prepare('SELECT id,type_code,type_name FROM `' . self::TYPE_TABLE . '` WHERE id=:id AND is_active=1 LIMIT 1');
            if (!$check || !$check->execute(array(':id'=>$typeId)) || !$check->fetch(PDO::FETCH_ASSOC)) return array('ok'=>false,'message'=>'선택한 활성 현장유형이 없습니다.');
            $now = date('Y-m-d H:i:s');
            if (empty($old)) {
                $save = $pdo->prepare('INSERT INTO `' . self::ASSIGN_TABLE . '` (project_id,project_type_id,assigned_by,assigned_by_name,assigned_at,is_active,updated_at) VALUES (:project,:type,:actor,:actor_name,:assigned,1,:updated)');
            } else {
                $save = $pdo->prepare('UPDATE `' . self::ASSIGN_TABLE . '` SET project_type_id=:type,assigned_by=:actor,assigned_by_name=:actor_name,assigned_at=:assigned,is_active=1,updated_at=:updated WHERE project_id=:project');
            }
            if (!$save || !$save->execute(array(':project'=>$projectId,':type'=>$typeId,':actor'=>$actor['id']>0?$actor['id']:null,':actor_name'=>$actor['name'],':assigned'=>$now,':updated'=>$now))) return array('ok'=>false,'message'=>'현장유형을 지정하지 못했습니다.');
            $entityId = empty($old) ? (int)$pdo->lastInsertId() : (int)$old['id'];
            self::history($pdo,'PROJECT_ASSIGNMENT',$entityId,$projectId,empty($old)?'ASSIGN':'REASSIGN',$old,array('project_id'=>$projectId,'project_type_id'=>$typeId),$reason);
            return array('ok'=>true,'message'=>'현장유형을 지정했습니다.');
        } catch (Exception $e) {
            error_log('[AI Project Type] assignment failed: ' . $e->getMessage());
            return array('ok'=>false,'message'=>'현장유형 지정 중 오류가 발생했습니다.');
        }
    }

    public static function projectType($pdo, $projectId)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo) || (int)$projectId <= 0) return array('assigned'=>false,'display'=>'유사 현장 비교자료 부족');
        try {
            $sql = 'SELECT t.*,a.assigned_at FROM `' . self::ASSIGN_TABLE . '` a INNER JOIN `' . self::TYPE_TABLE . '` t ON t.id=a.project_type_id WHERE a.project_id=:project AND a.is_active=1 AND t.is_active=1 LIMIT 1';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':project'=>(int)$projectId))) return array('assigned'=>false,'display'=>'유사 현장 비교자료 부족');
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return array('assigned'=>false,'display'=>'유사 현장 비교자료 부족');
            $row['assigned'] = true;
            $row['display'] = (string)$row['type_name'];
            return $row;
        } catch (Exception $e) {
            return array('assigned'=>false,'display'=>'유사 현장 비교자료 부족');
        }
    }

    public static function comparableProjectIds($pdo, $projectId)
    {
        $pdo = self::pdo($pdo);
        $projectId = (int)$projectId;
        $type = self::projectType($pdo, $projectId);
        if (!$pdo || empty($type['assigned'])) return array();
        try {
            $st = $pdo->prepare('SELECT contract_amount,start_date,end_date FROM cpms_projects WHERE id=:project LIMIT 1');
            if (!$st || !$st->execute(array(':project'=>$projectId))) return array();
            $base = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($base)) return array();
            $sql = 'SELECT p.id,p.contract_amount,p.start_date,p.end_date FROM cpms_projects p INNER JOIN `' . self::ASSIGN_TABLE . '` a ON a.project_id=p.id WHERE a.project_type_id=:type AND a.is_active=1 AND p.id<>:project';
            $find = $pdo->prepare($sql);
            if (!$find || !$find->execute(array(':type'=>(int)$type['id'],':project'=>$projectId))) return array();
            $baseAmount = isset($base['contract_amount']) ? (float)$base['contract_amount'] : 0.0;
            $baseDuration = self::durationDays(isset($base['start_date'])?$base['start_date']:'',isset($base['end_date'])?$base['end_date']:'');
            $ids = array();
            foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $amount = isset($row['contract_amount']) ? (float)$row['contract_amount'] : 0.0;
                $duration = self::durationDays(isset($row['start_date'])?$row['start_date']:'',isset($row['end_date'])?$row['end_date']:'');
                if (!self::isComparableScaleDuration($baseAmount,$baseDuration,$amount,$duration)) continue;
                $ids[] = (int)$row['id'];
            }
            return $ids;
        } catch (Exception $e) {
            return array();
        }
    }

    private static function durationDays($start, $end)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$end)) return 0;
        $seconds = strtotime($end) - strtotime($start);
        return $seconds >= 0 ? (int)floor($seconds / 86400) + 1 : 0;
    }

    public static function isComparableScaleDuration($baseAmount,$baseDuration,$candidateAmount,$candidateDuration)
    {
        $baseAmount=(float)$baseAmount;$baseDuration=(int)$baseDuration;$candidateAmount=(float)$candidateAmount;$candidateDuration=(int)$candidateDuration;
        if($baseAmount>0&&($candidateAmount<=0||$candidateAmount<$baseAmount*0.5||$candidateAmount>$baseAmount*2.0))return false;
        if($baseDuration>0&&($candidateDuration<=0||$candidateDuration<$baseDuration*0.5||$candidateDuration>$baseDuration*2.0))return false;
        return true;
    }

    public static function comparableHistoricalMedian($pdo, $projectId, $targetYm, $costType, $column)
    {
        $pdo=self::pdo($pdo);$allowed=array('labor_amount','outsourcing_amount','purchase_amount','material_amount','equipment_amount','other_expense_amount','safety_amount','health_amount','other_amount');
        if(!$pdo||!self::tableExists($pdo,self::SNAPSHOT_TABLE)||!in_array((string)$column,$allowed,true))return array('available'=>false,'sample_count'=>0,'median'=>null,'project_count'=>0);
        $ids=self::comparableProjectIds($pdo,$projectId);if(count($ids)===0)return array('available'=>false,'sample_count'=>0,'median'=>null,'project_count'=>0);
        try{$holders=array();$params=array(':target_ym'=>$targetYm);foreach($ids as $i=>$id){$key=':project'.$i;$holders[]=$key;$params[$key]=(int)$id;}$sql='SELECT project_id,target_ym,snapshot_date,`'.$column.'` AS amount FROM `'.self::SNAPSHOT_TABLE.'` WHERE project_id IN ('.implode(',',$holders).') AND target_ym<:target_ym ORDER BY project_id,target_ym,snapshot_date DESC,id DESC LIMIT 5000';$st=$pdo->prepare($sql);if(!$st||!$st->execute($params))return array('available'=>false,'sample_count'=>0,'median'=>null,'project_count'=>0);$seen=array();$values=array();$projects=array();foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$key=(int)$row['project_id'].':'.(string)$row['target_ym'];if(isset($seen[$key]))continue;$close=(string)$costType==='labor'?date('Y-m-t',strtotime($row['target_ym'].'-01')):$row['target_ym'].'-25';if((string)$row['snapshot_date']<$close)continue;$seen[$key]=true;$values[]=(float)$row['amount'];$projects[(int)$row['project_id']]=true;}if(count($values)===0)return array('available'=>false,'sample_count'=>0,'median'=>null,'project_count'=>0);sort($values,SORT_NUMERIC);$count=count($values);$middle=(int)floor($count/2);$median=$count%2?$values[$middle]:($values[$middle-1]+$values[$middle])/2;return array('available'=>true,'sample_count'=>$count,'median'=>round($median,2),'project_count'=>count($projects));}catch(Exception $e){return array('available'=>false,'sample_count'=>0,'median'=>null,'project_count'=>0);}
    }
}
?>
