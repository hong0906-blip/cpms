<?php
/** Scoped GPT guidance memory. It never changes calculated amounts. */
namespace App\Services;

use App\Core\Db;
use App\Core\Auth;
use PDO;
use Exception;

class AiMemoryService
{
    const MEMORY_TABLE = 'cpms_ai_memories';
    const HISTORY_TABLE = 'cpms_ai_memory_history';

    private static function pdo($pdo){return $pdo?$pdo:Db::pdo();}
    private static function actorId(){ $u=Auth::user();if(is_array($u)&&isset($u['employee_id'])&&(int)$u['employee_id']>0)return (int)$u['employee_id'];return is_array($u)&&isset($u['id'])?(int)$u['id']:0; }
    private static function actorName(){ $u=Auth::user();return is_array($u)&&isset($u['name'])?trim((string)$u['name']):''; }
    private static function encode($v){$j=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($j)?$j:null;}
    public static function tableExists($pdo,$table){if(!$pdo||!preg_match('/^[A-Za-z0-9_]+$/',(string)$table))return false;try{$st=$pdo->prepare('SHOW TABLES LIKE :table');return $st&&$st->execute(array(':table'=>$table))&&$st->fetchColumn()!==false;}catch(Exception $e){return false;}}

    public static function installOrUpdate($pdo=null)
    {
        $pdo=self::pdo($pdo);if(!$pdo)return array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
        $memory="CREATE TABLE IF NOT EXISTS `".self::MEMORY_TABLE."` (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          memory_group_uid VARCHAR(64) NOT NULL,
          memory_scope VARCHAR(20) NOT NULL,
          memory_text TEXT NOT NULL,
          source_user_id INT UNSIGNED NOT NULL,
          project_id INT UNSIGNED NULL,
          approval_status VARCHAR(20) NOT NULL,
          approved_by INT UNSIGNED NULL,
          approved_by_name VARCHAR(100) NULL,
          approved_at DATETIME NULL,
          effective_from DATE NOT NULL,
          expires_at DATE NULL,
          version INT UNSIGNED NOT NULL DEFAULT 1,
          is_active TINYINT(1) NOT NULL DEFAULT 0,
          source_thread_id BIGINT UNSIGNED NULL,
          source_message_id BIGINT UNSIGNED NULL,
          supersedes_memory_id BIGINT UNSIGNED NULL,
          retired_reason VARCHAR(500) NULL,
          created_at DATETIME NOT NULL,
          updated_at DATETIME NOT NULL,
          KEY idx_ai_memory_scope (memory_scope,is_active,effective_from,expires_at),
          KEY idx_ai_memory_user (source_user_id,memory_scope,is_active),
          KEY idx_ai_memory_project (project_id,memory_scope,is_active),
          KEY idx_ai_memory_group (memory_group_uid,version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $history="CREATE TABLE IF NOT EXISTS `".self::HISTORY_TABLE."` (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          memory_id BIGINT UNSIGNED NOT NULL,
          action_type VARCHAR(30) NOT NULL,
          old_data MEDIUMTEXT NULL,
          new_data MEDIUMTEXT NULL,
          reason VARCHAR(500) NULL,
          actor_employee_id INT UNSIGNED NULL,
          actor_name VARCHAR(100) NULL,
          changed_at DATETIME NOT NULL,
          KEY idx_ai_memory_history (memory_id,changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try{if($pdo->exec($memory)===false||$pdo->exec($history)===false)return array('ok'=>false,'message'=>'GPT 기억 구조를 설치하지 못했습니다.');return array('ok'=>true,'message'=>'GPT 기억 및 변경이력 구조 설치를 확인했습니다.');}catch(Exception $e){error_log('[AI Memory] install failed: '.$e->getMessage());return array('ok'=>false,'message'=>'GPT 기억 구조 설치 중 오류가 발생했습니다.');}
    }

    public static function isInstalled($pdo=null){$pdo=self::pdo($pdo);return self::tableExists($pdo,self::MEMORY_TABLE)&&self::tableExists($pdo,self::HISTORY_TABLE);}
    public static function schemaStatus($pdo=null){$pdo=self::pdo($pdo);$r=array('db_available'=>(bool)$pdo,'installed'=>self::isInstalled($pdo),'active_count'=>0,'pending_count'=>0);if(!$pdo||!$r['installed'])return $r;try{$st=$pdo->query('SELECT SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS active_count,SUM(CASE WHEN approval_status=\'PENDING\' THEN 1 ELSE 0 END) AS pending_count FROM `'.self::MEMORY_TABLE.'`');if($st){$row=$st->fetch(PDO::FETCH_ASSOC);if(is_array($row)){$r['active_count']=(int)$row['active_count'];$r['pending_count']=(int)$row['pending_count'];}}}catch(Exception $e){}return $r;}
    public static function scopeLabels(){return array('CONVERSATION'=>'이번 대화에서만 사용','PERSONAL'=>'개인 기준으로 저장','PROJECT'=>'해당 현장 기준으로 저장','COMPANY'=>'회사 공통 기준으로 저장');}
    public static function statusLabels(){return array('PENDING'=>'승인 대기','APPROVED'=>'승인','REJECTED'=>'반려','RETIRED'=>'비활성');}
    public static function applicationPriority(){return array('CALCULATED_EVIDENCE','PROJECT','COMPANY','PERSONAL','CONVERSATION','GENERAL_EXPLANATION');}
    private static function validDate($v,$allowEmpty){$v=trim((string)$v);if($v===''&&$allowEmpty)return null;return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:false;}
    private static function canApproveOfficial(){if(Auth::isMaster())return true;if(Auth::isDevelopmentDepartment())return false;return Auth::canAccessCeoIndex();}

    private static function history($pdo,$id,$action,$old,$new,$reason)
    {
        $st=$pdo->prepare('INSERT INTO `'.self::HISTORY_TABLE.'` (memory_id,action_type,old_data,new_data,reason,actor_employee_id,actor_name,changed_at) VALUES (:memory,:action,:old_data,:new_data,:reason,:actor,:actor_name,:changed)');
        return $st&&$st->execute(array(':memory'=>(int)$id,':action'=>$action,':old_data'=>self::encode($old),':new_data'=>self::encode($new),':reason'=>trim((string)$reason),':actor'=>self::actorId()>0?self::actorId():null,':actor_name'=>self::actorName(),':changed'=>date('Y-m-d H:i:s')));
    }

    public static function saveMemory($pdo,$data)
    {
        $pdo=self::pdo($pdo);if(!$pdo||!self::isInstalled($pdo))return array('ok'=>false,'message'=>'GPT 기억 구조를 먼저 설치해주세요.');
        if(empty($data['confirmed']))return array('ok'=>false,'message'=>'저장할 문장과 적용범위를 확인해주세요.');
        $scope=isset($data['memory_scope'])?strtoupper(trim((string)$data['memory_scope'])):'';$text=isset($data['memory_text'])?trim((string)$data['memory_text']):'';$project=isset($data['project_id'])?(int)$data['project_id']:0;
        if(!in_array($scope,array('PERSONAL','PROJECT','COMPANY'),true)||$text==='')return array('ok'=>false,'message'=>'기억 문장과 적용범위를 확인해주세요.');
        if($scope==='PROJECT'&&$project<=0)return array('ok'=>false,'message'=>'현장 기준에는 대상 현장을 선택해주세요.');
        if($scope!=='PROJECT')$project=0;
        $effective=self::validDate(isset($data['effective_from'])?$data['effective_from']:date('Y-m-d'),false);$expires=self::validDate(isset($data['expires_at'])?$data['expires_at']:'',true);
        if($effective===false||$expires===false||($expires!==null&&$expires<$effective))return array('ok'=>false,'message'=>'적용일과 만료일을 확인해주세요.');
        $immediate=$scope==='PERSONAL'||self::canApproveOfficial();$status=$immediate?'APPROVED':'PENDING';$active=$immediate?1:0;$actor=self::actorId();$now=date('Y-m-d H:i:s');$uid=hash('sha256',uniqid('',true).microtime(true).mt_rand());
        try{$sql='INSERT INTO `'.self::MEMORY_TABLE.'` (memory_group_uid,memory_scope,memory_text,source_user_id,project_id,approval_status,approved_by,approved_by_name,approved_at,effective_from,expires_at,version,is_active,source_thread_id,source_message_id,created_at,updated_at) VALUES (:uid,:scope,:text,:user,:project,:status,:approved_by,:approved_name,:approved_at,:effective,:expires,1,:active,:thread,:message,:created,:updated)';$st=$pdo->prepare($sql);if(!$st||!$st->execute(array(':uid'=>$uid,':scope'=>$scope,':text'=>$text,':user'=>$actor,':project'=>$project>0?$project:null,':status'=>$status,':approved_by'=>$immediate&&$actor>0?$actor:null,':approved_name'=>$immediate?self::actorName():null,':approved_at'=>$immediate?$now:null,':effective'=>$effective,':expires'=>$expires,':active'=>$active,':thread'=>isset($data['source_thread_id'])?(int)$data['source_thread_id']:null,':message'=>isset($data['source_message_id'])?(int)$data['source_message_id']:null,':created'=>$now,':updated'=>$now)))return array('ok'=>false,'message'=>'GPT 기억을 저장하지 못했습니다.');$id=(int)$pdo->lastInsertId();self::history($pdo,$id,'CREATE',array(),array('scope'=>$scope,'text'=>$text,'project_id'=>$project,'status'=>$status,'active'=>$active),'사용자 확인 후 저장');return array('ok'=>true,'id'=>$id,'approval_status'=>$status,'message'=>$immediate?'기준을 저장하고 활성화했습니다.':'기준 제안을 저장했습니다. 승인 후 적용됩니다.');}catch(Exception $e){error_log('[AI Memory] save failed: '.$e->getMessage());return array('ok'=>false,'message'=>'GPT 기억 저장 중 오류가 발생했습니다.');}
    }

    private static function row($pdo,$id){$st=$pdo->prepare('SELECT * FROM `'.self::MEMORY_TABLE.'` WHERE id=:id LIMIT 1');if(!$st||!$st->execute(array(':id'=>(int)$id)))return array();$r=$st->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:array();}
    public static function review($pdo,$id,$decision,$reason)
    {
        $pdo=self::pdo($pdo);$decision=strtoupper(trim((string)$decision));if(!$pdo||!self::isInstalled($pdo)||!self::canApproveOfficial())return array('ok'=>false,'message'=>'회사·현장 기준 승인 권한이 없습니다.');if(!in_array($decision,array('APPROVE','REJECT'),true))return array('ok'=>false,'message'=>'승인 처리값을 확인해주세요.');$old=self::row($pdo,$id);if(empty($old)||!in_array($old['memory_scope'],array('PROJECT','COMPANY'),true))return array('ok'=>false,'message'=>'검토할 기준이 없습니다.');if($decision==='REJECT'&&trim((string)$reason)==='')return array('ok'=>false,'message'=>'반려 사유를 입력해주세요.');$status=$decision==='APPROVE'?'APPROVED':'REJECTED';$active=$decision==='APPROVE'?1:0;$now=date('Y-m-d H:i:s');$started=false;
        try{if(!$pdo->inTransaction()){$pdo->beginTransaction();$started=true;}if($decision==='APPROVE'&&!empty($old['supersedes_memory_id'])){$previous=self::row($pdo,(int)$old['supersedes_memory_id']);$retire=$pdo->prepare('UPDATE `'.self::MEMORY_TABLE.'` SET is_active=0,approval_status=\'RETIRED\',retired_reason=:reason,updated_at=:updated WHERE id=:id');if(!$retire||!$retire->execute(array(':reason'=>'승인된 새 버전으로 대체',':updated'=>$now,':id'=>(int)$old['supersedes_memory_id'])))throw new Exception('retire');if(!empty($previous))self::history($pdo,(int)$previous['id'],'SUPERSEDED',$previous,array('is_active'=>0),'승인된 새 버전으로 대체');}$st=$pdo->prepare('UPDATE `'.self::MEMORY_TABLE.'` SET approval_status=:status,is_active=:active,approved_by=:actor,approved_by_name=:actor_name,approved_at=:approved,retired_reason=:reason,updated_at=:updated WHERE id=:id');if(!$st||!$st->execute(array(':status'=>$status,':active'=>$active,':actor'=>self::actorId()>0?self::actorId():null,':actor_name'=>self::actorName(),':approved'=>$now,':reason'=>$decision==='REJECT'?trim((string)$reason):null,':updated'=>$now,':id'=>(int)$id)))throw new Exception('review');self::history($pdo,$id,$decision,$old,array('status'=>$status,'active'=>$active),$reason);if($started)$pdo->commit();return array('ok'=>true,'message'=>$decision==='APPROVE'?'기준을 승인했습니다.':'기준을 반려했습니다.');}catch(Exception $e){if($started&&$pdo->inTransaction())$pdo->rollBack();return array('ok'=>false,'message'=>'승인 처리 중 오류가 발생했습니다.');}
    }

    public static function deactivate($pdo,$id,$reason)
    {
        $pdo=self::pdo($pdo);$old=self::row($pdo,$id);if(!$pdo||empty($old))return array('ok'=>false,'message'=>'비활성화할 기준이 없습니다.');$own=(int)$old['source_user_id']===self::actorId();if(!$own&&!self::canApproveOfficial())return array('ok'=>false,'message'=>'기준을 비활성화할 권한이 없습니다.');if(trim((string)$reason)==='')return array('ok'=>false,'message'=>'비활성화 사유를 입력해주세요.');try{$st=$pdo->prepare('UPDATE `'.self::MEMORY_TABLE.'` SET is_active=0,approval_status=\'RETIRED\',retired_reason=:reason,updated_at=:updated WHERE id=:id');if(!$st||!$st->execute(array(':reason'=>trim((string)$reason),':updated'=>date('Y-m-d H:i:s'),':id'=>(int)$id)))return array('ok'=>false,'message'=>'기준을 비활성화하지 못했습니다.');self::history($pdo,$id,'DEACTIVATE',$old,array('is_active'=>0,'status'=>'RETIRED'),$reason);return array('ok'=>true,'message'=>'기준을 비활성화했습니다. 기존 대화와 분석결과는 변경되지 않습니다.');}catch(Exception $e){return array('ok'=>false,'message'=>'기준 비활성화 중 오류가 발생했습니다.');}
    }

    public static function createVersion($pdo,$id,$data)
    {
        $pdo=self::pdo($pdo);$old=self::row($pdo,$id);if(!$pdo||empty($old)||empty($data['confirmed']))return array('ok'=>false,'message'=>'새 버전의 문장과 범위를 확인해주세요.');$own=(int)$old['source_user_id']===self::actorId();if(!$own&&!self::canApproveOfficial())return array('ok'=>false,'message'=>'새 버전을 만들 권한이 없습니다.');$text=isset($data['memory_text'])?trim((string)$data['memory_text']):'';if($text==='')return array('ok'=>false,'message'=>'새 버전 문장을 입력해주세요.');$effective=self::validDate(isset($data['effective_from'])?$data['effective_from']:date('Y-m-d'),false);$expires=self::validDate(isset($data['expires_at'])?$data['expires_at']:'',true);if($effective===false||$expires===false||($expires!==null&&$expires<$effective))return array('ok'=>false,'message'=>'적용일과 만료일을 확인해주세요.');$immediate=$old['memory_scope']==='PERSONAL'||self::canApproveOfficial();$status=$immediate?'APPROVED':'PENDING';$active=$immediate?1:0;$now=date('Y-m-d H:i:s');$started=false;
        try{if(!$pdo->inTransaction()){$pdo->beginTransaction();$started=true;}if($immediate){$retire=$pdo->prepare('UPDATE `'.self::MEMORY_TABLE.'` SET is_active=0,approval_status=\'RETIRED\',retired_reason=:reason,updated_at=:updated WHERE id=:id');if(!$retire||!$retire->execute(array(':reason'=>'새 버전으로 대체',':updated'=>$now,':id'=>(int)$old['id'])))throw new Exception('retire');}$sql='INSERT INTO `'.self::MEMORY_TABLE.'` (memory_group_uid,memory_scope,memory_text,source_user_id,project_id,approval_status,approved_by,approved_by_name,approved_at,effective_from,expires_at,version,is_active,source_thread_id,source_message_id,supersedes_memory_id,created_at,updated_at) VALUES (:uid,:scope,:text,:user,:project,:status,:approved_by,:approved_name,:approved_at,:effective,:expires,:version,:active,:thread,:message,:supersedes,:created,:updated)';$st=$pdo->prepare($sql);if(!$st||!$st->execute(array(':uid'=>$old['memory_group_uid'],':scope'=>$old['memory_scope'],':text'=>$text,':user'=>(int)$old['source_user_id'],':project'=>$old['project_id'],':status'=>$status,':approved_by'=>$immediate&&self::actorId()>0?self::actorId():null,':approved_name'=>$immediate?self::actorName():null,':approved_at'=>$immediate?$now:null,':effective'=>$effective,':expires'=>$expires,':version'=>(int)$old['version']+1,':active'=>$active,':thread'=>isset($data['source_thread_id'])?(int)$data['source_thread_id']:$old['source_thread_id'],':message'=>isset($data['source_message_id'])?(int)$data['source_message_id']:$old['source_message_id'],':supersedes'=>(int)$old['id'],':created'=>$now,':updated'=>$now)))throw new Exception('insert');$newId=(int)$pdo->lastInsertId();if($immediate)self::history($pdo,(int)$old['id'],'SUPERSEDED',$old,array('is_active'=>0),'새 버전 생성');self::history($pdo,$newId,'CREATE_VERSION',array('supersedes_memory_id'=>(int)$old['id']),array('version'=>(int)$old['version']+1,'text'=>$text,'status'=>$status),'사용자 확인 후 새 버전 생성');if($started)$pdo->commit();return array('ok'=>true,'id'=>$newId,'message'=>$immediate?'새 버전을 저장하고 활성화했습니다.':'새 버전을 저장했습니다. 승인 후 적용됩니다.');}catch(Exception $e){if($started&&$pdo->inTransaction())$pdo->rollBack();return array('ok'=>false,'message'=>'새 버전을 저장하지 못했습니다.');}
    }

    public static function reactivate($pdo,$id,$reason)
    {
        $pdo=self::pdo($pdo);$old=self::row($pdo,$id);if(!$pdo||empty($old))return array('ok'=>false,'message'=>'재활성화할 기준이 없습니다.');$own=(int)$old['source_user_id']===self::actorId();$official=in_array($old['memory_scope'],array('PROJECT','COMPANY'),true);if(($official&&!self::canApproveOfficial())||(!$official&&!$own&&!self::canApproveOfficial()))return array('ok'=>false,'message'=>'재활성화 권한이 없습니다.');if(trim((string)$reason)==='')return array('ok'=>false,'message'=>'재활성화 사유를 입력해주세요.');if($old['expires_at']!==null&&$old['expires_at']<date('Y-m-d'))return array('ok'=>false,'message'=>'만료된 기준은 새 버전으로 등록해주세요.');$started=false;
        try{if(!$pdo->inTransaction()){$pdo->beginTransaction();$started=true;}$activeRows=array();$activeSt=$pdo->prepare('SELECT * FROM `'.self::MEMORY_TABLE.'` WHERE memory_group_uid=:uid AND is_active=1 AND id<>:id');if($activeSt&&$activeSt->execute(array(':uid'=>$old['memory_group_uid'],':id'=>(int)$id))){$activeRows=$activeSt->fetchAll(PDO::FETCH_ASSOC);if(!is_array($activeRows))$activeRows=array();}$now=date('Y-m-d H:i:s');$off=$pdo->prepare('UPDATE `'.self::MEMORY_TABLE.'` SET is_active=0,approval_status=CASE WHEN id=:id THEN approval_status ELSE \'RETIRED\' END,retired_reason=CASE WHEN id=:id THEN retired_reason ELSE :retired_reason END,updated_at=:updated WHERE memory_group_uid=:uid AND is_active=1');if(!$off||!$off->execute(array(':id'=>(int)$id,':retired_reason'=>'다른 버전 재활성화로 대체',':updated'=>$now,':uid'=>$old['memory_group_uid'])))throw new Exception('off');foreach($activeRows as $activeRow)self::history($pdo,(int)$activeRow['id'],'SUPERSEDED',$activeRow,array('is_active'=>0,'status'=>'RETIRED'),'다른 버전 재활성화로 대체');$up=$pdo->prepare('UPDATE `'.self::MEMORY_TABLE.'` SET is_active=1,approval_status=\'APPROVED\',retired_reason=NULL,approved_by=:actor,approved_by_name=:actor_name,approved_at=:approved,updated_at=:updated WHERE id=:id');if(!$up||!$up->execute(array(':actor'=>self::actorId()>0?self::actorId():null,':actor_name'=>self::actorName(),':approved'=>$now,':updated'=>$now,':id'=>(int)$id)))throw new Exception('up');self::history($pdo,$id,'REACTIVATE',$old,array('is_active'=>1,'status'=>'APPROVED'),$reason);if($started)$pdo->commit();return array('ok'=>true,'message'=>'기준을 재활성화했습니다.');}catch(Exception $e){if($started&&$pdo->inTransaction())$pdo->rollBack();return array('ok'=>false,'message'=>'기준을 재활성화하지 못했습니다.');}
    }

    public static function activeContext($pdo,$userId,$projectIds)
    {
        $pdo=self::pdo($pdo);if(!$pdo||!self::isInstalled($pdo))return array('project'=>array(),'company'=>array(),'personal'=>array());$today=date('Y-m-d');$projectIds=is_array($projectIds)?array_values(array_unique(array_map('intval',$projectIds))):array();
        try{$sql='SELECT id,memory_scope,memory_text,source_user_id,project_id,version,effective_from,expires_at FROM `'.self::MEMORY_TABLE.'` WHERE is_active=1 AND approval_status=\'APPROVED\' AND effective_from<=:today AND (expires_at IS NULL OR expires_at>=:today2) AND (memory_scope=\'COMPANY\' OR (memory_scope=\'PERSONAL\' AND source_user_id=:user)';$params=array(':today'=>$today,':today2'=>$today,':user'=>(int)$userId);if(count($projectIds)>0){$holders=array();foreach($projectIds as $i=>$id){if($id<=0)continue;$key=':p'.$i;$holders[]=$key;$params[$key]=$id;}if(count($holders)>0)$sql.=' OR (memory_scope=\'PROJECT\' AND project_id IN ('.implode(',',$holders).'))';}$sql.=') ORDER BY memory_scope,project_id,memory_group_uid,version DESC,id DESC';$st=$pdo->prepare($sql);if(!$st||!$st->execute($params))return array('project'=>array(),'company'=>array(),'personal'=>array());$result=array('project'=>array(),'company'=>array(),'personal'=>array());$seen=array();foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$key=$row['memory_scope'].':'.(int)$row['project_id'].':'.trim((string)$row['memory_text']);if(isset($seen[$key]))continue;$seen[$key]=true;if($row['memory_scope']==='PROJECT')$result['project'][]=$row;else if($row['memory_scope']==='COMPANY')$result['company'][]=$row;else if($row['memory_scope']==='PERSONAL')$result['personal'][]=$row;}return $result;}catch(Exception $e){return array('project'=>array(),'company'=>array(),'personal'=>array());}
    }

    public static function listMemories($pdo=null,$filters=array())
    {
        $pdo=self::pdo($pdo);if(!$pdo||!self::isInstalled($pdo))return array();try{$where=' WHERE (m.memory_scope<>\'PERSONAL\' OR m.source_user_id=:viewer)';$params=array(':viewer'=>self::actorId());if(!empty($filters['mine_only'])){$where.=' AND m.source_user_id=:user';$params[':user']=self::actorId();}if(isset($filters['approval_status'])&&in_array($filters['approval_status'],array('PENDING','APPROVED','REJECTED','RETIRED'),true)){$where.=' AND m.approval_status=:status';$params[':status']=$filters['approval_status'];}$sql='SELECT m.*,p.name AS project_name FROM `'.self::MEMORY_TABLE.'` m LEFT JOIN cpms_projects p ON p.id=m.project_id'.$where.' ORDER BY m.created_at DESC,m.id DESC LIMIT 200';$st=$pdo->prepare($sql);if(!$st||!$st->execute($params))return array();$r=$st->fetchAll(PDO::FETCH_ASSOC);return is_array($r)?$r:array();}catch(Exception $e){return array();}
    }
}
?>
