<?php
require_once __DIR__ . '/../../attendance/common.php';

$cpmsAttendanceActionsShowRequest = isset($cpmsAttendanceActionsShowRequest) ? (bool)$cpmsAttendanceActionsShowRequest : true;
$eid_btn = attendance_employee_id($pdo);
$today_btn = attendance_today();
$row_btn = attendance_today_record($pdo, $eid_btn);
$todayRecordId = ($row_btn && isset($row_btn['id'])) ? (int)$row_btn['id'] : 0;
$todayCheckIn = ($row_btn && isset($row_btn['check_in']) && $row_btn['check_in']) ? (string)$row_btn['check_in'] : '';
$todayCheckOut = ($row_btn && isset($row_btn['check_out']) && $row_btn['check_out']) ? (string)$row_btn['check_out'] : '';
$attendanceGeofence = attendance_geofence_settings($pdo);
$canCheckIn = false;
$canCheckOut = false;
$showDone = false;
$hasEmployee = ((int)$eid_btn > 0);
if ($hasEmployee) {
    if (!$row_btn || $todayCheckIn === '') {
        $canCheckIn = true;
    } else if ($todayCheckIn !== '' && $todayCheckOut === '') {
        $canCheckOut = true;
    } else {
        $showDone = true;
    }
}
$debugAttendance = isset($_GET['debug_attendance']) && (string)$_GET['debug_attendance'] === '1';
?>
<div class="cpms-attendance-actions flex flex-wrap items-center gap-3">
    <?php if (!$hasEmployee): ?>
        <div class="px-5 py-3 rounded-2xl bg-amber-100 text-amber-800 font-extrabold text-base">직원 정보를 찾을 수 없습니다.</div>
    <?php else: ?>
        <?php if ($canCheckIn): ?>
            <form method="post" action="?r=attendance/check_in">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <button class="px-5 py-3 rounded-2xl bg-white text-blue-700 font-extrabold text-base">출근</button>
            </form>
        <?php endif; ?>
        <?php if ($canCheckOut): ?>
            <form method="post" action="?r=attendance/check_out">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <button class="px-5 py-3 rounded-2xl bg-emerald-100 text-emerald-700 font-extrabold text-base">퇴근</button>
            </form>
        <?php endif; ?>
        <?php if ($showDone): ?>
            <div class="px-5 py-3 rounded-2xl bg-slate-100 text-slate-700 font-extrabold text-base">오늘 근무 완료</div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($cpmsAttendanceActionsShowRequest): ?>
        <button type="button" data-attendance-request-open class="px-5 py-3 rounded-2xl bg-blue-900/80 text-white font-extrabold text-base border border-white/40">출퇴근 요청</button>
    <?php endif; ?>
    <?php if ($debugAttendance): ?>
        <div class="basis-full mt-1 p-3 rounded-xl bg-black/60 text-white text-xs leading-6">
            employee_id: <?php echo h((string)$eid_btn); ?><br>
            attendance_today(): <?php echo h($today_btn); ?><br>
            attendance_now(): <?php echo h(attendance_now()); ?><br>
            today_record_id: <?php echo h($todayRecordId > 0 ? (string)$todayRecordId : '없음'); ?><br>
            today_record_work_date: <?php echo h(($row_btn && isset($row_btn['work_date']) && $row_btn['work_date']) ? (string)$row_btn['work_date'] : '없음'); ?><br>
            today_check_in: <?php echo h($todayCheckIn !== '' ? $todayCheckIn : '없음'); ?><br>
            today_check_out: <?php echo h($todayCheckOut !== '' ? $todayCheckOut : '없음'); ?><br>
            canCheckIn: <?php echo $canCheckIn ? 'true' : 'false'; ?><br>
            canCheckOut: <?php echo $canCheckOut ? 'true' : 'false'; ?>
        </div>
    <?php endif; ?>
</div>
<script>
(function(){
    try{
        var forms = document.querySelectorAll("form[action='?r=attendance/check_in'], form[action='?r=attendance/check_out']");
        if(!forms || !forms.length) return;
        var geofenceEnabled = <?php echo !empty($attendanceGeofence['enabled']) ? 'true' : 'false'; ?>;
        function ensureHidden(form, name){
            var input = form.querySelector("input[name='" + name + "']");
            if(!input){
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                form.appendChild(input);
            }
            return input;
        }
        function restoreButton(button, label){
            if(!button) return;
            button.disabled = false;
            button.textContent = label;
        }
        function handleSubmit(event){
            var form = event.currentTarget;
            if(!form || !geofenceEnabled) return;
            if(form.getAttribute('data-geo-ready') === '1') return;
            event.preventDefault();
            if(!navigator.geolocation){
                alert('이 브라우저에서는 위치 확인을 지원하지 않습니다.');
                return;
            }
            var button = form.querySelector('button[type="submit"], button:not([type])');
            var originalText = button ? button.textContent : '';
            if(button){
                button.disabled = true;
                button.textContent = '위치 확인 중...';
            }
            navigator.geolocation.getCurrentPosition(function(position){
                ensureHidden(form, 'geo_lat').value = position.coords.latitude;
                ensureHidden(form, 'geo_lng').value = position.coords.longitude;
                ensureHidden(form, 'geo_accuracy').value = position.coords.accuracy;
                form.setAttribute('data-geo-ready', '1');
                form.submit();
            }, function(error){
                restoreButton(button, originalText);
                if(error && error.code === 1){
                    alert('위치 권한을 허용해야 출퇴근을 등록할 수 있습니다.');
                    return;
                }
                alert('현재 위치를 확인하지 못했습니다. 잠시 후 다시 시도해주세요.');
            }, {
                enableHighAccuracy: true,
                timeout: 12000,
                maximumAge: 0
            });
        }
        for(var i=0;i<forms.length;i++){
            forms[i].addEventListener('submit', handleSubmit);
        }
    }catch(e){}
})();
</script>
