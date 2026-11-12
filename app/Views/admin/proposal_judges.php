<?php
/**
 * Step 2 – the two judges, their private links and where each of them stands.
 *
 * @var array $p
 * @var array $as
 * @var array $links
 * @var array $judges   ['tech' => [...], 'biz' => [...]] directory entries per role
 * @var bool $mailReady
 * @var array $mails
 * @var bool $reviewed
 * @var bool $finalized
 * @var int $id
 */
$roleTone = [
    'tech' => ['bg-blue-50 text-blue-800 border-blue-200', 'bg-blue-600'],
    'biz' => ['bg-amber-50 text-amber-800 border-amber-200', 'bg-amber-500'],
];
$roleHint = [
    'tech' => 'ابعاد «فنی و نوآوری» و «تیم و اجرا»، سطح TRL و ریسک اجرا',
    'biz' => 'ابعاد «بازار و مقیاس‌پذیری» و «مالی و مدل درآمدی» و ریسک بازار',
];
$unsent = 0;
foreach (['tech', 'biz'] as $r) {
    if ($as[$r] && $as[$r]['status'] !== 'submitted' && empty($as[$r]['sent_at']) && !empty($as[$r]['judge_email'])) {
        $unsent++;
    }
}
$base = '/admin/proposals/' . $id;

/** The «choose a judge» form (used for a free role and for changing the judge). */
$assignForm = function (string $role, ?array $current) use ($judges, $base) {
    $list = $judges[$role];
    ob_start(); ?>
    <form method="post" action="<?= e(url($base . '/assign/' . $role)) ?>" class="space-y-3" data-assign>
      <?= csrf_field() ?>
      <?php if ($list): ?>
        <div>
          <label class="label">انتخاب از فهرست داوران</label>
          <select class="input" name="judge_id" required data-judge-select>
            <option value="" disabled selected>یک داور را انتخاب کنید…</option>
            <?php foreach ($list as $j): ?>
              <option value="<?= (int) $j['id'] ?>" <?= $current && (int) $current['judge_id'] === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['name']) ?><?= $j['email'] ? ' — ' . e($j['email']) : ' — (بدون ایمیل)' ?></option>
            <?php endforeach; ?>
            <option value="0">+ داور جدید…</option>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" name="judge_id" value="0">
      <?php endif; ?>
      <div class="space-y-3" data-new-judge <?= $list ? 'hidden' : '' ?>>
        <div>
          <label class="label">نام و نام خانوادگی داور <span class="req-star">*</span></label>
          <input class="input" name="name" value="<?= e((string) old('name', '')) ?>" <?= $list ? '' : 'required' ?>>
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="label">ایمیل</label>
            <input class="input ltr" type="email" name="email" value="<?= e((string) old('email', '')) ?>" placeholder="name@example.ir">
          </div>
          <div>
            <label class="label">شماره همراه</label>
            <input class="input ltr" name="mobile" value="<?= e((string) old('mobile', '')) ?>" inputmode="tel" placeholder="0912…">
          </div>
        </div>
        <label class="check"><input type="checkbox" name="save_judge" value="1" checked><span>در فهرست داوران هم ذخیره شود</span></label>
      </div>
      <button class="btn btn-primary btn-sm" type="submit"><?= $current ? 'تغییر داور' : 'تعیین داور و ساخت پیوند' ?></button>
    </form>
    <?php return ob_get_clean();
};
?>
<section id="judges" class="card mt-6 scroll-mt-6">
  <div class="card-head">
    <h2 class="card-title flex items-center gap-2">
      <span class="w-7 h-7 rounded-lg bg-brand-800 text-white text-sm font-bold inline-flex items-center justify-center">۲</span>
      داوران و پیوند اختصاصی فرم
    </h2>
    <?php if ($reviewed && $unsent > 0 && $mailReady): ?>
      <form method="post" action="<?= e(url($base . '/send')) ?>" class="no-print">
        <?= csrf_field() ?>
        <input type="hidden" name="role" value="all"><input type="hidden" name="kind" value="invite">
        <button class="btn btn-success btn-sm" type="submit"><?= icon('send', 'w-4 h-4') ?> ارسال ایمیل برای <?= $unsent === 2 ? 'هر دو داور' : 'داور' ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$reviewed): ?>
    <div class="card-body">
      <div class="alert alert-info"><span class="shrink-0"><?= icon('lock') ?></span><div>پس از تأیید اطلاعات طرح در بخش بالا، می‌توانید داوران را تعیین کنید و پیوند فرم برای آن‌ها ساخته می‌شود.</div></div>
    </div>
  <?php else: ?>
    <div class="card-body">
      <?php if (!$mailReady): ?>
        <div class="alert alert-warning mb-5 no-print">
          <span class="shrink-0"><?= icon('mail') ?></span>
          <div>ارسال ایمیل پیکربندی نشده است. پیوند هر داور را کپی کنید و خودتان (ایمیل، پیام‌رسان و…) برای او بفرستید، یا <a class="link" href="<?= e(url('/admin/settings', ['tab' => 'mail'])) ?>">صندوق ایمیل را تنظیم کنید</a>.</div>
        </div>
      <?php endif; ?>

      <div class="grid md:grid-cols-2 gap-5">
        <?php foreach (['tech', 'biz'] as $role):
            $a = $as[$role];
            $tone = $roleTone[$role]; ?>
          <div class="rounded-2xl border border-slate-200 overflow-hidden flex flex-col">
            <div class="px-4 py-3 flex items-center justify-between gap-2 border-b <?= e($tone[0]) ?>">
              <div>
                <div class="font-bold"><?= e(role_label($role)) ?></div>
                <div class="text-[11px] opacity-80 mt-0.5"><?= e($roleHint[$role]) ?></div>
              </div>
              <?= assignment_badge($a['status'] ?? null) ?>
            </div>

            <div class="p-4 flex-1 flex flex-col gap-4">
              <?php if (!$a): ?>
                <?php if ($finalized): ?>
                  <p class="text-sm text-slate-500">داوری تعیین نشده است.</p>
                <?php else: ?>
                  <?= $assignForm($role, null) ?>
                <?php endif; ?>
              <?php else:
                  $submitted = $a['status'] === 'submitted';
                  $hasMail = !empty($a['judge_email']);
                  $sent = !empty($a['sent_at']); ?>
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="font-bold text-slate-900 truncate"><?= e($a['judge_name']) ?></div>
                    <?php if ($hasMail): ?>
                      <div class="text-xs text-slate-500 ltr text-end truncate"><?= e($a['judge_email']) ?></div>
                    <?php else: ?>
                      <div class="text-xs text-amber-600">ایمیل ثبت نشده است — پیوند را دستی بفرستید.</div>
                    <?php endif; ?>
                    <?php if (!empty($a['judge_mobile'])): ?><div class="text-xs text-slate-500 ltr text-end num"><?= e($a['judge_mobile']) ?></div><?php endif; ?>
                  </div>
                  <a class="btn btn-ghost btn-sm shrink-0 no-print" href="<?= e(url($base . '/preview/' . $role)) ?>" target="_blank" rel="noopener" title="فرمی که این داور می‌بیند"><?= icon('eye', 'w-4 h-4') ?> پیش‌نمایش فرم</a>
                </div>

                <?php if (!$submitted): ?>
                  <!-- the private link -->
                  <div class="no-print">
                    <label class="label text-xs" for="link-<?= e($role) ?>">پیوند اختصاصی این داور</label>
                    <div class="flex items-stretch gap-2">
                      <input class="input ltr text-xs font-mono" id="link-<?= e($role) ?>" value="<?= e($links[$role]) ?>" readonly onfocus="this.select()">
                      <button type="button" class="btn btn-soft btn-sm shrink-0" data-copy="#link-<?= e($role) ?>"><?= icon('copy', 'w-4 h-4') ?> کپی</button>
                    </div>
                    <p class="hint">این پیوند فقط برای <?= e($a['judge_name']) ?> و فقط برای نقش «<?= e(role_short($role)) ?>» است و نیازی به نام کاربری و رمز ندارد.</p>
                  </div>

                  <div class="flex flex-wrap items-center gap-2 no-print">
                    <?php if ($hasMail && $mailReady): ?>
                      <form method="post" action="<?= e(url($base . '/send')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="role" value="<?= e($role) ?>"><input type="hidden" name="kind" value="invite">
                        <button class="btn <?= $sent ? 'btn-secondary' : 'btn-success' ?> btn-sm" type="submit"><?= icon('mail', 'w-4 h-4') ?> <?= $sent ? 'ارسال دوباره دعوت‌نامه' : 'ایمیل دعوت‌نامه' ?></button>
                      </form>
                      <?php if ($sent): ?>
                        <form method="post" action="<?= e(url($base . '/send')) ?>">
                          <?= csrf_field() ?>
                          <input type="hidden" name="role" value="<?= e($role) ?>"><input type="hidden" name="kind" value="reminder">
                          <button class="btn btn-secondary btn-sm" type="submit"><?= icon('clock', 'w-4 h-4') ?> ایمیل یادآوری</button>
                        </form>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$sent): ?>
                      <form method="post" action="<?= e(url($base . '/mark-sent/' . $role)) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-secondary btn-sm" type="submit" title="اگر پیوند را کپی و خودتان فرستاده‌اید، این‌جا ثبت کنید تا وضعیت طرح به‌روز شود"><?= icon('check', 'w-4 h-4') ?> پیوند را خودم فرستادم</button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <!-- where this judge stands -->
                <ol class="tl">
                  <li class="tl-item on">پیوند ساخته شد <span class="text-slate-400 num">— <?= e(jdate($a['created_at'])) ?></span></li>
                  <li class="tl-item <?= $sent ? 'on' : '' ?>">
                    <?php if ($sent): ?>
                      <?= $a['sent_via'] === 'email' ? 'با ایمیل ارسال شد' : 'دستی ارسال شد' ?> <span class="text-slate-400 num">— <?= e(jdate($a['sent_at'])) ?></span>
                      <?php if ((int) $a['send_count'] > 1): ?><span class="text-slate-400">(<?= fa((string) $a['send_count']) ?> بار)</span><?php endif; ?>
                      <?php if ((int) $a['reminder_count'] > 0): ?><span class="block text-slate-500"><?= fa((string) $a['reminder_count']) ?> یادآوری — آخرین: <span class="num"><?= e(jdate($a['last_reminder_at'])) ?></span></span><?php endif; ?>
                    <?php else: ?>هنوز ارسال نشده است<?php endif; ?>
                  </li>
                  <li class="tl-item <?= !empty($a['first_opened_at']) ? 'on' : '' ?>">
                    <?php if (!empty($a['first_opened_at'])): ?>داور پیوند را باز کرد <span class="text-slate-400 num">— <?= e(jdate($a['first_opened_at'])) ?></span>
                      <?php if ((int) $a['open_count'] > 1): ?><span class="text-slate-400">(<?= fa((string) $a['open_count']) ?> بازدید، آخرین <?= e(ago($a['last_opened_at'])) ?>)</span><?php endif; ?>
                    <?php else: ?>هنوز باز نشده است<?php endif; ?>
                  </li>
                  <li class="tl-item <?= !empty($a['draft_saved_at']) ? 'on' : '' ?>">
                    <?php if (!empty($a['draft_saved_at']) && !$submitted): ?>در حال تکمیل — آخرین ذخیره <span class="num"><?= e(ago($a['draft_saved_at'])) ?></span>
                    <?php elseif (!empty($a['draft_saved_at'])): ?>تکمیل فرم
                    <?php else: ?>تکمیل فرم شروع نشده است<?php endif; ?>
                  </li>
                  <li class="tl-item <?= $submitted ? 'on' : '' ?>">
                    <?php if ($submitted): ?><b class="text-emerald-700">فرم ثبت شد</b> <span class="text-slate-400 num">— <?= e(jdate($a['submitted_at'])) ?></span>
                    <?php elseif (!empty($a['reopened_at'])): ?>فرم برای اصلاح بازگشایی شده است <span class="text-slate-400 num">— <?= e(jdate($a['reopened_at'])) ?></span>
                    <?php else: ?>ثبت نهایی فرم<?php endif; ?>
                  </li>
                </ol>

                <?php if (!$finalized): ?>
                <div class="mt-auto pt-3 border-t border-slate-100 flex flex-wrap items-center gap-2 no-print">
                  <?php if ($submitted): ?>
                    <a class="btn btn-soft btn-sm" href="#form-<?= e($role) ?>"><?= icon('doc', 'w-4 h-4') ?> مشاهده فرم ثبت‌شده</a>
                    <form method="post" action="<?= e(url($base . '/reopen/' . $role)) ?>" data-confirm="فرم این داور برای اصلاح باز می‌شود و نتیجه طرح تا ثبت دوباره فرم محاسبه نخواهد شد. ادامه می‌دهید؟">
                      <?= csrf_field() ?>
                      <button class="btn btn-ghost btn-sm" type="submit"><?= icon('refresh', 'w-4 h-4') ?> بازگشایی برای اصلاح</button>
                    </form>
                  <?php else: ?>
                    <details class="w-full">
                      <summary class="text-xs text-slate-500 hover:text-slate-800 inline-flex items-center gap-1"><?= icon('cog', 'w-4 h-4') ?> تغییر داور، پیوند تازه یا حذف</summary>
                      <div class="mt-3 space-y-4">
                        <div class="rounded-xl bg-slate-50 border border-slate-100 p-3">
                          <div class="text-xs font-semibold text-slate-700 mb-2">سپردن این نقش به داور دیگر</div>
                          <?= $assignForm($role, $a) ?>
                          <p class="hint">با تغییر داور، پیوند قبلی باطل و پاسخ‌های ناتمام داور قبلی حذف می‌شود.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                          <form method="post" action="<?= e(url($base . '/regenerate/' . $role)) ?>" data-confirm="پیوند فعلی باطل می‌شود و باید پیوند تازه را دوباره برای داور بفرستید. ادامه می‌دهید؟">
                            <?= csrf_field() ?>
                            <button class="btn btn-secondary btn-sm" type="submit"><?= icon('link', 'w-4 h-4') ?> ساخت پیوند تازه</button>
                          </form>
                          <form method="post" action="<?= e(url($base . '/unassign/' . $role)) ?>" data-confirm="این داور از طرح حذف و پیوند او باطل می‌شود. ادامه می‌دهید؟">
                            <?= csrf_field() ?>
                            <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash', 'w-4 h-4') ?> حذف داور</button>
                          </form>
                        </div>
                      </div>
                    </details>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($mails): ?>
        <details class="mt-5 text-sm no-print">
          <summary class="text-xs text-slate-500 hover:text-slate-800 inline-flex items-center gap-1"><?= icon('mail', 'w-4 h-4') ?> سابقه ایمیل‌های این طرح (<?= fa((string) count($mails)) ?>)</summary>
          <div class="overflow-x-auto mt-2 rounded-xl border border-slate-200">
            <table class="tbl text-xs">
              <thead><tr><th>زمان</th><th>گیرنده</th><th>موضوع</th><th>نتیجه</th></tr></thead>
              <tbody>
              <?php foreach ($mails as $m): ?>
                <tr>
                  <td class="num whitespace-nowrap"><?= e(jdate($m['created_at'])) ?></td>
                  <td class="ltr text-end"><?= e($m['to_email']) ?></td>
                  <td><?= e(str_limit($m['subject'], 70)) ?></td>
                  <td><?= $m['status'] === 'sent' ? badge('تحویل سرور ایمیل شد', 'emerald') : badge('ناموفق', 'red') ?><?php if ($m['error']): ?><span class="block text-red-600 mt-1"><?= e(str_limit($m['error'], 160)) ?></span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
