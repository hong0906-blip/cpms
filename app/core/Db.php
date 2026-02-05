<?php
namespace App\Core;

use PDO;
use PDOException;

/**
 * app/core/Db.php
 * - PDO 연결 헬퍼 (PHP 5.6)
 */
class Db
{
    private static $pdo = null;

    /**
     * @return PDO|null
     */
    public static function pdo()
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $cfgFile = __DIR__ . '/../config/database.php';
        if (!file_exists($cfgFile)) return null;

        $cfg = require $cfgFile;
        if (!is_array($cfg)) return null;

        $host = isset($cfg['host']) ? $cfg['host'] : '127.0.0.1';
        $port = isset($cfg['port']) ? (int)$cfg['port'] : 3306;
        $db   = isset($cfg['dbname']) ? $cfg['dbname'] : '';
        $user = isset($cfg['user']) ? $cfg['user'] : '';
        $pass = isset($cfg['pass']) ? $cfg['pass'] : '';
        $ch   = isset($cfg['charset']) ? $cfg['charset'] : 'utf8mb4';

        if ($db === '' || $user === '') {
            return null; // 설정 미완성
        }

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=' . $ch;

        try {
            $pdo = new PDO($dsn, $user, $pass, array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
            self::$pdo = $pdo;
            return self::$pdo;
        } catch (PDOException $e) {
            // 운영에서는 로그로 남기는 게 좋지만, 여기선 기능만 안전하게 실패 처리
            return null;
        }
    }
}