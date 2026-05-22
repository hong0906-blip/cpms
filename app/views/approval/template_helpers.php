<?php
if (!function_exists('approval_default_leave_agreement')) {
    function approval_default_leave_agreement()
    {
        return urldecode('%EC%83%81%EA%B8%B0%20%EB%B3%B8%EC%9D%B8%EC%9D%80%20%EC%9C%84%EC%99%80%20%EA%B0%99%EC%9D%B4%20%ED%9C%B4%EA%B0%80%EB%A5%BC%20%EC%8B%A0%EC%B2%AD%ED%95%98%EB%A9%B0%2C%20%ED%9A%8C%EC%82%AC%20%EA%B7%9C%EC%A0%95%EC%97%90%20%EB%94%B0%EB%9D%BC%20%ED%9C%B4%EA%B0%80%20%EC%B0%A8%EA%B0%90%EC%9D%B4%20%EC%B2%98%EB%A6%AC%EB%90%A8%EC%97%90%20%EB%8F%99%EC%9D%98%ED%95%A9%EB%8B%88%EB%8B%A4.');
    }
}

if (!function_exists('approval_sign_path_by_email')) {
    function approval_sign_path_by_email($email)
    {
        $prefix = explode('@', (string)$email);
        $name = isset($prefix[0]) ? trim($prefix[0]) : '';
        if ($name === '') {
            return '';
        }
        $exts = array('png', 'PNG', 'jpg', 'JPG', 'jpeg', 'JPEG', 'webp', 'WEBP');
        $baseDirs = array('storage/signatures', 'public/storage/signatures');
        $root = dirname(dirname(dirname(__DIR__)));
        for ($i = 0; $i < count($baseDirs); $i++) {
            for ($j = 0; $j < count($exts); $j++) {
                $rel = $baseDirs[$i] . '/' . $name . '.' . $exts[$j];
                $abs = $root . '/' . $rel;
                if (is_file($abs)) {
                    return $rel;
                }
            }
        }
        return '';
    }
}

if (!function_exists('approval_display_name_only')) {
    function approval_display_name_only($name)
    {
        $name = trim((string)$name);
        if ($name === '') {
            return '';
        }
        $positions = array(
            urldecode('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'),
            urldecode('%EB%B6%80%EC%82%AC%EC%9E%A5'),
            urldecode('%EC%83%81%EB%AC%B4'),
            urldecode('%EC%A0%84%EB%AC%B4'),
            urldecode('%EC%9D%B4%EC%82%AC'),
            urldecode('%EB%B6%80%EC%9E%A5'),
            urldecode('%EC%B0%A8%EC%9E%A5'),
            urldecode('%EA%B3%BC%EC%9E%A5'),
            urldecode('%EB%8C%80%EB%A6%AC'),
            urldecode('%EC%A3%BC%EC%9E%84'),
            urldecode('%EC%82%AC%EC%9B%90')
        );
        for ($i = 0; $i < count($positions); $i++) {
            $name = str_replace($positions[$i], '', $name);
        }
        $name = preg_replace('/\s+/', ' ', trim((string)$name));
        return $name;
    }
}

if (!function_exists('approval_column_exists')) {
    function approval_column_exists($pdo, $table, $column)
    {
        if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') {
            return false;
        }
        try {
            $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column";
            $st = $pdo->prepare($sql);
            $st->execute(array(':table' => (string)$table, ':column' => (string)$column));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_norm_dept')) {
    function approval_norm_dept($d)
    {
        $d = trim((string)$d);
        $d = str_replace(' ', '', $d);
        $construction = urldecode('%EA%B3%B5%EC%82%AC');
        $safety = urldecode('%EC%95%88%EC%A0%84');
        $gongmu = urldecode('%EA%B3%B5%EB%AC%B4');
        $manage = urldecode('%EA%B4%80%EB%A6%AC');
        $quality = urldecode('%ED%92%88%EC%A7%88');
        if ($d === $construction . urldecode('%EB%B6%80') || $d === $construction . urldecode('%ED%8C%80')) {
            return $construction;
        }
        if ($d === $safety . urldecode('%EB%B6%80') || $d === $safety . urldecode('%ED%8C%80')) {
            return $safety;
        }
        if ($d === $gongmu . urldecode('%EB%B6%80') || $d === $gongmu . urldecode('%ED%8C%80')) {
            return $gongmu;
        }
        if ($d === $manage . urldecode('%EB%B6%80') || $d === $manage . urldecode('%ED%8C%80')) {
            return $manage;
        }
        if ($d === $quality . urldecode('%EB%B6%80') || $d === $quality . urldecode('%ED%8C%80')) {
            return $quality;
        }
        return $d;
    }
}
