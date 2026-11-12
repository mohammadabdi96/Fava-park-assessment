<?php

namespace Fava\Controllers;

use Fava\Core\Auth;
use Fava\Core\Request;
use Fava\Core\Session;
use Fava\Domain\Activity;

class AuthController extends Controller
{
    public function form(array $params): void
    {
        if (Auth::check()) {
            redirect('/admin');
        }
        $reset = Auth::applyResetFile();
        $this->page('auth/login', [
            'pageTitle' => 'ورود مدیر',
            'notice' => $reset,
            'username' => (string) old('username', ''),
        ]);
    }

    public function login(array $params): void
    {
        $username = Request::str('username');
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if (Auth::isLocked(Request::ip())) {
            flash('error', 'به‌دلیل چند تلاش ناموفق، ورود از این نشانی اینترنتی تا ۱۵ دقیقه دیگر ممکن نیست.');
            Session::keepInput(['username' => $username]);
            redirect('/admin/login');
        }
        if ($username === '' || $password === '' || !Auth::attempt($username, $password)) {
            flash('error', 'نام کاربری یا رمز عبور نادرست است.');
            Session::keepInput(['username' => $username]);
            redirect('/admin/login');
        }
        Activity::log('login', 'ورود به پنل مدیریت.');
        $intended = Session::get('_intended');
        Session::forget('_intended');
        if (is_string($intended) && strncmp($intended, '/admin', 6) === 0 && $intended !== '/admin/login') {
            redirect($intended);
        }
        redirect('/admin');
    }

    public function logout(array $params): void
    {
        Auth::logout();
        redirect('/admin/login');
    }
}
