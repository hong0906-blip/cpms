<?php
if (!function_exists('cpms_schedule_auto_table_exists')) {
function cpms_schedule_auto_table_exists($pdo, $table) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', (string)$table);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_schedule_auto_column_exists')) {
function cpms_schedule_auto_column_exists($pdo, $table, $column) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_schedule_auto_index_exists')) {
function cpms_schedule_auto_index_exists($pdo, $table, $indexName) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW INDEX FROM `" . $table . "` WHERE Key_name = :idx");
        $st->bindValue(':idx', (string)$indexName);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_schedule_auto_ensure_schema')) {
function cpms_schedule_auto_ensure_schema($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_schedule_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            task_id INT NOT NULL,
            work_date DATE NOT NULL,
            total_qty DECIMAL(18,4) NULL,
            done_qty DECIMAL(18,4) NULL,
            is_auto TINYINT(1) NOT NULL DEFAULT 0,
            is_manual TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_schedule_progress_day (project_id, task_id, work_date),
            KEY idx_project_task (project_id, task_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_schedule_task_item_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            task_id INT NOT NULL,
            unit_price_id INT NOT NULL,
            work_date DATE NOT NULL,
            total_qty DECIMAL(18,4) NULL,
            done_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
            is_auto TINYINT(1) NOT NULL DEFAULT 0,
            is_manual TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_project_task_unit_date (project_id, task_id, unit_price_id, work_date),
            KEY idx_project_task (project_id, task_id),
            KEY idx_unit_price_id (unit_price_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $tables = array(
        'cpms_schedule_progress' => array(
            'is_auto' => "ALTER TABLE cpms_schedule_progress ADD COLUMN is_auto TINYINT(1) NOT NULL DEFAULT 0",
            'is_manual' => "ALTER TABLE cpms_schedule_progress ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0",
            'updated_at' => "ALTER TABLE cpms_schedule_progress ADD COLUMN updated_at DATETIME NULL"
        ),
        'cpms_schedule_task_item_progress' => array(
            'work_date' => "ALTER TABLE cpms_schedule_task_item_progress ADD COLUMN work_date DATE NULL AFTER done_qty",
            'total_qty' => "ALTER TABLE cpms_schedule_task_item_progress ADD COLUMN total_qty DECIMAL(18,4) NULL AFTER work_date",
            'is_auto' => "ALTER TABLE cpms_schedule_task_item_progress ADD COLUMN is_auto TINYINT(1) NOT NULL DEFAULT 0",
            'is_manual' => "ALTER TABLE cpms_schedule_task_item_progress ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0"
        )
    );
    foreach ($tables as $table => $columns) {
        if (!cpms_schedule_auto_table_exists($pdo, $table)) continue;
        foreach ($columns as $column => $sql) {
            if (!cpms_schedule_auto_column_exists($pdo, $table, $column)) {
                try { $pdo->exec($sql); } catch (Exception $e) {}
            }
        }
    }

    if (cpms_schedule_auto_table_exists($pdo, 'cpms_schedule_progress') && !cpms_schedule_auto_index_exists($pdo, 'cpms_schedule_progress', 'uniq_schedule_progress_day')) {
        try { $pdo->exec("ALTER TABLE cpms_schedule_progress ADD UNIQUE KEY uniq_schedule_progress_day(project_id, task_id, work_date)"); } catch (Exception $e) {}
    }
    if (cpms_schedule_auto_table_exists($pdo, 'cpms_schedule_task_item_progress')) {
        if (cpms_schedule_auto_index_exists($pdo, 'cpms_schedule_task_item_progress', 'uniq_project_task_unit')) {
            try { $pdo->exec("ALTER TABLE cpms_schedule_task_item_progress DROP KEY uniq_project_task_unit"); } catch (Exception $e) {}
        }
        if (!cpms_schedule_auto_index_exists($pdo, 'cpms_schedule_task_item_progress', 'uniq_project_task_unit_date')) {
            try { $pdo->exec("ALTER TABLE cpms_schedule_task_item_progress ADD UNIQUE KEY uniq_project_task_unit_date(project_id, task_id, unit_price_id, work_date)"); } catch (Exception $e) {}
        }
    }
    return true;
}}

if (!function_exists('cpms_schedule_auto_date_days')) {
function cpms_schedule_auto_date_days($startDate, $endDate) {
    $s = strtotime($startDate . ' 00:00:00');
    $e = strtotime($endDate . ' 00:00:00');
    if ($s === false || $e === false || $s <= 0 || $e <= 0) return 0;
    if ($e < $s) { $tmp = $s; $s = $e; $e = $tmp; }
    return (int)floor(($e - $s) / 86400) + 1;
}}

if (!function_exists('cpms_schedule_auto_upsert_progress')) {
function cpms_schedule_auto_upsert_progress($pdo, $projectId, $taskId, $workDate, $totalQty, $doneQty) {
    $st = $pdo->prepare("SELECT id, is_auto, is_manual FROM cpms_schedule_progress WHERE project_id=:pid AND task_id=:tid AND work_date=:wd LIMIT 1");
    $st->execute(array(':pid'=>(int)$projectId, ':tid'=>(int)$taskId, ':wd'=>$workDate));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $isAuto = isset($row['is_auto']) ? (int)$row['is_auto'] : 0;
        $isManual = isset($row['is_manual']) ? (int)$row['is_manual'] : 0;
        if ($isAuto !== 1 || $isManual === 1) return;
        $up = $pdo->prepare("UPDATE cpms_schedule_progress SET total_qty=:tq, done_qty=:dq, is_auto=1, is_manual=0, updated_at=NOW() WHERE id=:id");
        $up->execute(array(':tq'=>$totalQty, ':dq'=>$doneQty, ':id'=>(int)$row['id']));
        return;
    }
    $ins = $pdo->prepare("INSERT INTO cpms_schedule_progress(project_id, task_id, work_date, total_qty, done_qty, is_auto, is_manual, created_at, updated_at) VALUES(:pid,:tid,:wd,:tq,:dq,1,0,NOW(),NOW())");
    $ins->execute(array(':pid'=>(int)$projectId, ':tid'=>(int)$taskId, ':wd'=>$workDate, ':tq'=>$totalQty, ':dq'=>$doneQty));
}}

if (!function_exists('cpms_schedule_auto_upsert_item_progress')) {
function cpms_schedule_auto_upsert_item_progress($pdo, $projectId, $taskId, $unitPriceId, $workDate, $totalQty, $doneQty) {
    $st = $pdo->prepare("SELECT id, is_auto, is_manual FROM cpms_schedule_task_item_progress WHERE project_id=:pid AND task_id=:tid AND unit_price_id=:uid AND work_date=:wd LIMIT 1");
    $st->execute(array(':pid'=>(int)$projectId, ':tid'=>(int)$taskId, ':uid'=>(int)$unitPriceId, ':wd'=>$workDate));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $isAuto = isset($row['is_auto']) ? (int)$row['is_auto'] : 0;
        $isManual = isset($row['is_manual']) ? (int)$row['is_manual'] : 0;
        if ($isAuto !== 1 || $isManual === 1) return;
        $up = $pdo->prepare("UPDATE cpms_schedule_task_item_progress SET total_qty=:tq, done_qty=:dq, is_auto=1, is_manual=0, updated_at=NOW() WHERE id=:id");
        $up->execute(array(':tq'=>$totalQty, ':dq'=>$doneQty, ':id'=>(int)$row['id']));
        return;
    }
    $ins = $pdo->prepare("INSERT INTO cpms_schedule_task_item_progress(project_id, task_id, unit_price_id, work_date, total_qty, done_qty, is_auto, is_manual, created_at, updated_at) VALUES(:pid,:tid,:uid,:wd,:tq,:dq,1,0,NOW(),NOW())");
    $ins->execute(array(':pid'=>(int)$projectId, ':tid'=>(int)$taskId, ':uid'=>(int)$unitPriceId, ':wd'=>$workDate, ':tq'=>$totalQty, ':dq'=>$doneQty));
}}

if (!function_exists('cpms_schedule_recalculate_task_progress')) {
function cpms_schedule_recalculate_task_progress($pdo, $projectId, $taskId, $totalQty) {
    if (!$pdo || (int)$projectId <= 0 || (int)$taskId <= 0 || (float)$totalQty <= 0) return;
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(done_qty,0)),0) FROM cpms_schedule_progress WHERE project_id=:pid AND task_id=:tid");
        $st->execute(array(':pid'=>(int)$projectId, ':tid'=>(int)$taskId));
        $doneSum = (float)$st->fetchColumn();
        $pct = 0;
        if ($doneSum > 0) $pct = (int)round(min(100, ($doneSum / (float)$totalQty) * 100));
        if ($pct < 0) $pct = 0;
        if ($pct > 100) $pct = 100;
        $up = $pdo->prepare("UPDATE cpms_schedule_tasks SET progress=:pct WHERE project_id=:pid AND id=:tid");
        $up->execute(array(':pct'=>$pct, ':pid'=>(int)$projectId, ':tid'=>(int)$taskId));
    } catch (Exception $e) {}
}}

if (!function_exists('cpms_schedule_apply_auto_progress')) {
function cpms_schedule_apply_auto_progress($pdo, $projectId) {
    if (!$pdo || (int)$projectId <= 0) return false;
    cpms_schedule_auto_ensure_schema($pdo);
    if (!cpms_schedule_auto_table_exists($pdo, 'cpms_schedule_progress') || !cpms_schedule_auto_table_exists($pdo, 'cpms_schedule_task_item_progress')) return false;

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));

    try {
        $st = $pdo->prepare("SELECT id, work_id, start_date, end_date FROM cpms_schedule_tasks WHERE project_id=:pid AND work_id IS NOT NULL AND work_id > 0");
        $st->execute(array(':pid'=>(int)$projectId));
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($tasks)) return true;

        foreach ($tasks as $task) {
            $taskId = isset($task['id']) ? (int)$task['id'] : 0;
            $workId = isset($task['work_id']) ? (int)$task['work_id'] : 0;
            $startDate = isset($task['start_date']) ? trim((string)$task['start_date']) : '';
            $endDate = isset($task['end_date']) ? trim((string)$task['end_date']) : '';
            if ($taskId <= 0 || $workId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) continue;
            if ($endDate < $startDate) { $tmpDate = $startDate; $startDate = $endDate; $endDate = $tmpDate; }

            $durationDays = cpms_schedule_auto_date_days($startDate, $endDate);
            if ($durationDays <= 0) continue;
            $autoEnd = ($endDate < $today) ? $endDate : $yesterday;
            if ($autoEnd < $startDate) {
                cpms_schedule_recalculate_task_progress($pdo, $projectId, $taskId, 0);
                continue;
            }

            $stLines = $pdo->prepare("SELECT l.unit_price_id, COALESCE(l.planned_qty, u.qty, 0) AS qty FROM cpms_work_item_lines l INNER JOIN cpms_project_unit_prices u ON u.id=l.unit_price_id WHERE l.work_id=:wid AND u.project_id=:pid ORDER BY u.id ASC");
            $stLines->execute(array(':wid'=>$workId, ':pid'=>(int)$projectId));
            $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($lines) || count($lines) === 0) continue;

            $totalQty = 0.0;
            foreach ($lines as $line) {
                $totalQty += isset($line['qty']) && is_numeric((string)$line['qty']) ? (float)$line['qty'] : 0.0;
            }
            if ($totalQty <= 0) continue;

            $cursorTs = strtotime($startDate . ' 00:00:00');
            $endTs = strtotime($autoEnd . ' 00:00:00');
            $dayIndex = 0;
            while ($cursorTs !== false && $cursorTs <= $endTs) {
                $workDate = date('Y-m-d', $cursorTs);
                $dayIndex++;
                $taskDone = 0.0;
                foreach ($lines as $line) {
                    $uid = isset($line['unit_price_id']) ? (int)$line['unit_price_id'] : 0;
                    $lineTotal = isset($line['qty']) && is_numeric((string)$line['qty']) ? (float)$line['qty'] : 0.0;
                    if ($uid <= 0 || $lineTotal <= 0) continue;
                    if ($dayIndex >= $durationDays) $lineDone = $lineTotal - (($lineTotal / $durationDays) * ($durationDays - 1));
                    else $lineDone = $lineTotal / $durationDays;
                    $lineDone = round($lineDone, 4);
                    $taskDone += $lineDone;
                    cpms_schedule_auto_upsert_item_progress($pdo, $projectId, $taskId, $uid, $workDate, $lineTotal, $lineDone);
                }
                cpms_schedule_auto_upsert_progress($pdo, $projectId, $taskId, $workDate, $totalQty, round($taskDone, 4));
                $cursorTs += 86400;
            }
            cpms_schedule_recalculate_task_progress($pdo, $projectId, $taskId, $totalQty);
        }
    } catch (Exception $e) {
        error_log('[schedule_auto_progress] ' . $e->getMessage());
        return false;
    }
    return true;
}}
?>
