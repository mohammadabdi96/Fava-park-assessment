<?php

namespace Fava\Core;

/**
 * Encrypts small secrets (the SMTP password) before they are stored in the database,
 * using the app key from config.php. Falls back to plain storage when OpenSSL is missing.
 */
class Crypto
{
    private const PREFIX = 'enc:v1:';

    public static function seal(string $plain): string
    {
        $key = (string) Config::get('app_key', '');
        if ($plain === '' || $key === '' || !function_exists('openssl_encrypt')) {
            return $plain;
        }
        $encKey = hash('sha256', 'enc|' . $key, true);
        $macKey = hash('sha256', 'mac|' . $key, true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return $plain;
        }
        $mac = hash_hmac('sha256', $iv . $cipher, $macKey, true);
        return self::PREFIX . base64_encode($iv . $mac . $cipher);
    }

    public static function open(string $stored): string
    {
        if (strncmp($stored, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return $stored;
        }
        $key = (string) Config::get('app_key', '');
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 49 || $key === '' || !function_exists('openssl_decrypt')) {
            return '';
        }
        $encKey = hash('sha256', 'enc|' . $key, true);
        $macKey = hash('sha256', 'mac|' . $key, true);
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipher = substr($raw, 48);
        if (!hash_equals(hash_hmac('sha256', $iv . $cipher, $macKey, true), $mac)) {
            return '';
        }
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }
}
