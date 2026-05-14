<?php
use App\Core\Db;
require_once __DIR__.'/template_helpers.php';
if (!(\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees() || \App\Core\Auth::userRole()==='executive')) { http_response_code(403); exit('403'); }
$pdo = Db::pdo();
$settings = array('google_holiday_calendar_enabled'=>'0','google_holiday_calendar_id'=>'ko.south_korea#holiday@group.v.calendar.google.com','google_holiday_calendar_api_key'=>'','google_holiday_sync_years'=>'2');
try { $rows=$pdo->query("SELECT setting_key,setting_value FROM cpms_approval_settings WHERE setting_key IN ('google_holiday_calendar_enabled','google_holiday_calendar_id','google_holiday_calendar_api_key','google_holiday_sync_years')")->fetchAll(); foreach($rows as $r){$settings[$r['setting_key']]=$r['setting_value'];} } catch(Exception $e){}
$msg=''; $ok=0;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $api=trim((string)$settings['google_holiday_calendar_api_key']); $cal=trim((string)$settings['google_holiday_calendar_id']);
  if ($api==='' || $cal==='') $msg='API Key/Calendar ID가 없어 캐시만 사용합니다.';
  else {
    $years=(int)$settings['google_holiday_sync_years']; if($years<1)$years=2; $nowY=(int)date('Y'); $total=0;
    for($i=0;$i<$years;$i++){
      $y=$nowY+$i; $timeMin=$y.'-01-01T00:00:00Z'; $timeMax=$y.'-12-31T23:59:59Z';
      $url='https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($cal).'/events?key='.urlencode($api).'&singleEvents=true&orderBy=startTime&timeMin='.urlencode($timeMin).'&timeMax='.urlencode($timeMax);
      $res=@file_get_contents($url); if($res===false) continue; $json=json_decode($res,true); if(!is_array($json)||!isset($json['items'])||!is_array($json['items'])) continue;
      foreach($json['items'] as $it){ if(!isset($it['start']['date'])) continue; $d=(string)$it['start']['date']; if($d==='') continue; $n=isset($it['summary'])?(string)$it['summary']:''; $eid=isset($it['id'])?(string)$it['id']:'';
        $st=$pdo->prepare("INSERT INTO cpms_holiday_cache (holiday_date,holiday_name,source,source_calendar_id,source_event_id,year_no,is_active,synced_at,created_at,updated_at) VALUES (:d,:n,'GOOGLE_CALENDAR',:c,:e,:y,1,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE holiday_name=VALUES(holiday_name), source_calendar_id=VALUES(source_calendar_id), source_event_id=VALUES(source_event_id), year_no=VALUES(year_no), is_active=1, synced_at=NOW(), updated_at=NOW()");
        $st->execute(array(':d'=>$d,':n'=>$n,':c'=>$cal,':e'=>$eid,':y'=>$y)); $total++;
      }
    }
    $ok=1; $msg='동기화 완료: '.$total.'건';
  }
}
?><div class="bg-white rounded-2xl border p-4"><h2 class="text-xl font-bold">Google 공휴일 동기화</h2><p class="text-sm mt-2">설정된 캘린더에서 현재/다음년도 공휴일 캐시를 동기화합니다.</p><?php if($msg!==''){?><div class="mt-3 <?php echo $ok?'text-emerald-700':'text-amber-700';?>"><?php echo h($msg);?></div><?php }?><form method="post" class="mt-4"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token());?>"><button class="px-3 py-2 bg-indigo-600 text-white rounded">동기화 실행</button></form></div>