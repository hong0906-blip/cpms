<?php
/**
 * C:\www\cpms\app\services\CryptoHelper.php
 * - 주민등록번호/계좌번호 같은 민감정보 암호화, 해시, 마스킹 도우미
 * - PHP 5.6 호환
 */

if (!class_exists('CryptoHelper')) {
class CryptoHelper
{
    public static function normalizeDigits($value)
    {
        return preg_replace('/[^0-9]/', '', (string)$value);
    }

    public static function normalizePhoneDigits($value)
    {
        return self::normalizeDigits($value);
    }

    public static function hashSensitive($value)
    {
        $digits = self::normalizeDigits($value);
        if ($digits === '') return null;
        return hash('sha256', $digits);
    }

    public static function encrypt($plainText)
    {
        $plainText = trim((string)$plainText);
        if ($plainText === '') return null;
        if (!function_exists('openssl_encrypt')) {
            return 'plain64:' . base64_encode($plainText);
        }

        $key = self::keyBytes();
        $ivLength = openssl_cipher_iv_length('AES-256-CBC');
        if (!$ivLength || $ivLength <= 0) $ivLength = 16;
        $iv = openssl_random_pseudo_bytes($ivLength);
        $cipher = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) return null;

        return 'aes256cbc:' . base64_encode($iv . $cipher);
    }

    public static function decrypt($encryptedText)
    {
        $encryptedText = trim((string)$encryptedText);
        if ($encryptedText === '') return '';

        if (strpos($encryptedText, 'plain64:') === 0) {
            $decoded = base64_decode(substr($encryptedText, 8), true);
            return ($decoded === false) ? '' : $decoded;
        }

        if (strpos($encryptedText, 'aes256cbc:') !== 0 || !function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($encryptedText, 10), true);
        if ($raw === false || strlen($raw) <= 16) return '';

        $ivLength = openssl_cipher_iv_length('AES-256-CBC');
        if (!$ivLength || $ivLength <= 0) $ivLength = 16;
        $iv = substr($raw, 0, $ivLength);
        $cipher = substr($raw, $ivLength);

        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::keyBytes(), OPENSSL_RAW_DATA, $iv);
        return ($plain === false) ? '' : $plain;
    }

    public static function maskResidentNo($value)
    {
        $digits = self::normalizeDigits($value);
        if ($digits === '') return '';
        if (strlen($digits) >= 7) {
            return substr($digits, 0, 6) . '-' . substr($digits, 6, 1) . '******';
        }
        if (strlen($digits) > 3) {
            return substr($digits, 0, 3) . str_repeat('*', strlen($digits) - 3);
        }
        return str_repeat('*', strlen($digits));
    }

    public static function maskBankAccount($value)
    {
        $digits = self::normalizeDigits($value);
        if ($digits === '') return '';
        if (strlen($digits) <= 6) return '****';
        $last = strlen($digits) > 3 ? substr($digits, -3) : '';
        return substr($digits, 0, 6) . '-****' . $last;
    }

    public static function formatPhone($value)
    {
        $digits = self::normalizePhoneDigits($value);
        if ($digits === '') return trim((string)$value);
        if (strlen($digits) === 11) {
            return substr($digits, 0, 3) . '-' . substr($digits, 3, 4) . '-' . substr($digits, 7);
        }
        if (strlen($digits) === 10) {
            return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        }
        return trim((string)$value);
    }

    private static function keyBytes()
    {
        $material = getenv('CPMS_WORKER_CRYPTO_KEY');
        if (!is_string($material) || trim($material) === '') {
            $material = self::loadOrCreateKeyFile();
        }
        if (!is_string($material) || trim($material) === '') {
            $material = __FILE__;
        }
        return hash('sha256', $material, true);
    }

    private static function loadOrCreateKeyFile()
    {
        $root = dirname(dirname(__DIR__));
        $dir = $root . '/storage/secrets';
        $file = $dir . '/worker_crypto.key';

        if (is_file($file)) {
            $txt = @file_get_contents($file);
            return is_string($txt) ? trim($txt) : '';
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $bytes = openssl_random_pseudo_bytes(32);
        $material = bin2hex($bytes);
        @file_put_contents($file, $material, LOCK_EX);
        return $material;
    }
}
}
