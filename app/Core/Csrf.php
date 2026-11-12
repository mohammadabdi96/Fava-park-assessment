<?php

namespace Fava\Core;

class Csrf
{
    public static function token(): string
    {
        Session::start();
        $token = Session::get('_csrf');
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf', $token);
        }
        return $token;
    }

    public static function check(): bool
    {
        $sent = $_POST['_csrf'] ?? Request::header('X-CSRF-Token');
        $real = Session::get('_csrf');
        return is_string($sent) && is_string($real) && $real !== '' && hash_equals($real, $sent);
    }

    public static function verifyOrFail(): void
    {
        if (!self::check()) {
            throw new HttpException(419, 'نشست شما منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.');
        }
    }
}
