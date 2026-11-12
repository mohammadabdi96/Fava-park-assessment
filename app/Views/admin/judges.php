<?php
/**
 * @var array $judges
 * @var array|null $edit
 */
use Fava\Domain\Judges;

$f = function (string $key, $default = '') use ($edit) {
    $old = old($key, null);
    if ($old !== null) {
        return $old;
    }
    return $edit ? ($edit[$key] ?? $default) : $default;
};
?>
<div class="flex items-end justify-between gap-4 flex-wrap mb-5">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">داوران</h1>
    <p class="text-sm text-slate-500 mt-1">فهرست داورانی که هنگام تعیین داور هر طرح از میان آن‌ها انتخاب می‌کنید</p>
  </div>
</div>

<div class="grid lg:grid-cols-3 gap-6 items-start">
  <section class="card lg:col-span-2 overflow-hidden">
    <?php if (!$judges): ?>
      <div class="p-10 text-center text-sm text-slate-500">هنوز داوری ثبت نشده است. از فرم کنار صفحه نخستین داور را اضافه کنید.</div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="tbl">
          <thead><tr><th>نام</th><th>تخصص</th><th>تماس</th><th>فرم‌ها</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($judges as $j): ?>
            <tr class="<?= $j['is_active'] ? '' : 'opacity-60' ?>">
              <td>
                <span class="font-semibold text-slate-900"><?= e($j['name']) ?></span>
                <?php if (!$j['is_active']): ?><span class="badge badge-gray ms-1">غیرفعال</span><?php endif; ?>
                <?php if ($j['organization']): ?><span class="block text-xs text-slate-500"><?= e($j['organization']) ?></span><?php endif; ?>
              </td>
              <td><?= badge(Judges::EXPERTISE[$j['expertise']] ?? $j['expertise'], $j['expertise'] === 'tech' ? 'blue' : ($j['expertise'] === 'biz' ? 'amber' : 'violet')) ?></td>
              <td class="text-xs">
                <?php if ($j['email']): ?><span class="ltr block"><?= e($j['email']) ?></span><?php else: ?><span class="text-amber-600">بدون ایمیل</span><?php endif; ?>
                <?php if ($j['mobile']): ?><span class="ltr block text-slate-500 num"><?= e($j['mobile']) ?></span><?php endif; ?>
              </td>
              <td class="text-xs whitespace-nowrap">
                <span class="text-emerald-700 font-semibold num"><?= fa((string) $j['submitted']) ?></span> ثبت‌شده
                <span class="text-slate-300 mx-1">|</span>
                <span class="text-amber-700 font-semibold num"><?= fa((string) $j['open']) ?></span> باز
              </td>
              <td class="whitespace-nowrap text-end">
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/judges', ['edit' => $j['id']])) ?>" title="ویرایش"><?= icon('pencil', 'w-4 h-4') ?></a>
                <form method="post" action="<?= e(url('/admin/judges/' . $j['id'] . '/toggle')) ?>" class="inline">
                  <?= csrf_field() ?>
                  <button class="btn btn-ghost btn-sm" type="submit" title="<?= $j['is_active'] ? 'غیرفعال کردن' : 'فعال کردن' ?>"><?= icon($j['is_active'] ? 'eye' : 'refresh', 'w-4 h-4') ?></button>
                </form>
                <form method="post" action="<?= e(url('/admin/judges/' . $j['id'] . '/delete')) ?>" class="inline" data-confirm="داور «<?= e($j['name']) ?>» حذف شود؟ اگر سابقه داوری داشته باشد فقط غیرفعال می‌شود.">
                  <?= csrf_field() ?>
                  <button class="btn btn-ghost btn-sm text-red-600" type="submit" title="حذف"><?= icon('trash', 'w-4 h-4') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" id="judge-form">
    <div class="card-head">
      <h2 class="card-title"><?= $edit ? 'ویرایش داور' : 'افزودن داور' ?></h2>
      <?php if ($edit): ?><a class="link text-sm" href="<?= e(url('/admin/judges')) ?>">انصراف</a><?php endif; ?>
    </div>
    <form method="post" action="<?= e(url('/admin/judges/save')) ?>" class="card-body space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
      <div>
        <label class="label" for="j-name">نام و نام خانوادگی <span class="req-star">*</span></label>
        <input class="input" id="j-name" name="name" value="<?= e($f('name')) ?>" required>
      </div>
      <div>
        <label class="label" for="j-exp">تخصص</label>
        <select class="input" id="j-exp" name="expertise">
          <?php foreach (Judges::EXPERTISE as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $f('expertise', 'both') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint">داور «فنی» فقط در فهرست داوران فنی و داور «کسب‌وکاری» فقط در فهرست داوران کسب‌وکاری نمایش داده می‌شود.</p>
      </div>
      <div>
        <label class="label" for="j-email">ایمیل</label>
        <input class="input ltr" id="j-email" name="email" type="email" value="<?= e($f('email')) ?>" placeholder="name@example.ir">
        <p class="hint">برای ارسال خودکار پیوند فرم لازم است.</p>
      </div>
      <div>
        <label class="label" for="j-mobile">شماره همراه</label>
        <input class="input ltr" id="j-mobile" name="mobile" value="<?= e($f('mobile')) ?>" placeholder="0912…" inputmode="tel">
      </div>
      <div>
        <label class="label" for="j-org">سازمان / سمت</label>
        <input class="input" id="j-org" name="organization" value="<?= e($f('organization')) ?>">
      </div>
      <div>
        <label class="label" for="j-notes">یادداشت</label>
        <textarea class="input" id="j-notes" name="notes" rows="2"><?= e($f('notes')) ?></textarea>
      </div>
      <button class="btn btn-primary w-full" type="submit"><?= $edit ? 'ذخیره تغییرات' : 'افزودن به فهرست' ?></button>
    </form>
  </section>
</div>
