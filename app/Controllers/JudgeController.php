<?php

namespace Fava\Controllers;

use Fava\Core\DB;
use Fava\Core\HttpException;
use Fava\Core\Request;
use Fava\Core\Session;
use Fava\Domain\Activity;
use Fava\Domain\Judges;

class JudgeController extends Controller
{
    public function index(array $params): void
    {
        $editId = (int) Request::query('edit', 0);
        $this->admin('admin/judges', [
            'pageTitle' => 'داوران',
            'judges' => Judges::all(),
            'edit' => $editId > 0 ? Judges::find($editId) : null,
        ], 'judges');
    }

    public function save(array $params): void
    {
        $id = Request::int('id');
        list($data, $errors) = Judges::validate($_POST);
        if (!$errors && $data['email'] !== null) {
            $other = Judges::findByEmail($data['email']);
            if ($other && (int) $other['id'] !== $id) {
                $errors['email'] = 'داور دیگری با همین ایمیل ثبت شده است (' . $other['name'] . ').';
            }
        }
        if ($errors) {
            foreach ($errors as $m) {
                flash('error', $m);
            }
            Session::keepInput($_POST);
            redirect('/admin/judges', $id > 0 ? ['edit' => $id] : []);
        }
        if ($id > 0) {
            if (!Judges::find($id)) {
                throw new HttpException(404, 'داور پیدا نشد.');
            }
            Judges::update($id, $data);
            // keep the contact details of forms that have not been sent in yet in step
            DB::get()->update('assignments', [
                'judge_name' => $data['name'],
                'judge_email' => $data['email'],
                'judge_mobile' => $data['mobile'],
            ], "judge_id = ? AND status <> 'submitted'", [$id]);
            Activity::log('judge', 'مشخصات داور «' . $data['name'] . '» ویرایش شد.');
            flash('success', 'مشخصات داور ذخیره شد.');
        } else {
            Judges::create($data);
            Activity::log('judge', 'داور «' . $data['name'] . '» به فهرست داوران اضافه شد.');
            flash('success', 'داور اضافه شد.');
        }
        redirect('/admin/judges');
    }

    public function toggle(array $params): void
    {
        $j = Judges::find((int) $params['id']);
        if ($j) {
            Judges::update((int) $j['id'], ['is_active' => $j['is_active'] ? 0 : 1]);
            flash('success', $j['is_active'] ? 'داور غیرفعال شد و دیگر در فهرست انتخاب نمایش داده نمی‌شود.' : 'داور دوباره فعال شد.');
        }
        redirect('/admin/judges');
    }

    public function delete(array $params): void
    {
        $j = Judges::find((int) $params['id']);
        if ($j) {
            $what = Judges::deleteOrDeactivate((int) $j['id']);
            Activity::log('judge', 'داور «' . $j['name'] . '» ' . ($what === 'deleted' ? 'حذف شد.' : 'غیرفعال شد (به‌دلیل داشتن سابقه داوری حذف نشد).'));
            flash('success', $what === 'deleted' ? 'داور حذف شد.' : 'این داور سابقه داوری دارد؛ به‌جای حذف، غیرفعال شد.');
        }
        redirect('/admin/judges');
    }
}
