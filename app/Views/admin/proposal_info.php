<?php
/**
 * Step 1 – what was read from the uploaded worksheet, for the admin to check.
 *
 * @var array $p
 * @var array $fields
 * @var array $warnings
 * @var string $docHtml
 * @var bool $reviewed
 * @var bool $finalized
 * @var int $id
 * @var string $status
 */
use Fava\Core\Jalali;
use Fava\Domain\Labels;

$v = function (string $key, $current) {
    $old = old($key, null);
    return $old !== null ? $old : $current;
};
$dueJalali = !empty($p['due_at']) ? Jalali::format('Y/m/d', (int) strtotime($p['due_at'] . ' UTC')) : '';
$amount = $p['requested_amount'] !== null ? rtrim(rtrim(number_format((float) $p['requested_amount'], 3, '.', ''), '0'), '.') : '';
$suggestions = $fields['amount_suggestions'] ?? [];
$facts = $fields['company_facts'] ?? [];
$team = $fields['team'] ?? ['headers' => [], 'rows' => []];
$extract = Labels::EXTRACT[$p['extract_status']] ?? ['—', 'gray'];
?>
<section id="info" class="card scroll-mt-6">
  <details <?= $reviewed ? '' : 'open' ?> class="group">
    <summary class="card-head">
      <h2 class="card-title flex items-center gap-2">
        <span class="w-7 h-7 rounded-lg bg-brand-800 text-white text-sm font-bold inline-flex items-center justify-center">۱</span>
        اطلاعات طرح
        <?php if ($reviewed): ?><span class="badge badge-emerald"><?= icon('check', 'w-3 h-3') ?> تأیید شده</span><?php else: ?><span class="badge badge-amber">در انتظار تأیید شما</span><?php endif; ?>
      </h2>
      <span class="text-xs text-slate-500 flex items-center gap-1 no-print"><span class="group-open:hidden">نمایش و ویرایش</span><span class="hidden group-open:inline">بستن</span><?= icon('chevron', 'w-4 h-4 rot transition') ?></span>
    </summary>

    <div class="card-body">
      <?php if ($warnings): ?>
        <div class="alert alert-warning mb-5">
          <span class="shrink-0"><?= icon('warn') ?></span>
          <div>
            <b>هنگام خواندن فایل به این موارد توجه کنید:</b>
            <ul class="list-disc ps-5 mt-1 space-y-0.5">
              <?php foreach ($warnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>

      <div class="grid lg:grid-cols-5 gap-6">
        <!-- editable summary that goes on top of the judges' form -->
        <form method="post" action="<?= e(url('/admin/proposals/' . $id . '/update')) ?>" class="lg:col-span-3 space-y-4">
          <?= csrf_field() ?>
          <p class="text-xs text-slate-500 leading-6 -mt-1">این سه مورد بالای فرم داوران نمایش داده می‌شود. سامانه آن‌ها را از فایل استخراج کرده است؛ در صورت نیاز اصلاح کنید.</p>
          <div>
            <label class="label" for="f-title">عنوان کامل طرح <span class="req-star">*</span></label>
            <textarea class="input" id="f-title" name="title" rows="2" required><?= e($v('title', $p['title'])) ?></textarea>
            <?php if (!empty($p['title_alt']) && $p['title_alt'] !== $p['title']): ?>
              <p class="hint">عنوان دیگری هم در فایل نوشته شده است:
                <button type="button" class="link" data-fill="#f-title" data-value="<?= e($p['title_alt']) ?>">«<?= e(str_limit($p['title_alt'], 90)) ?>»</button></p>
            <?php endif; ?>
          </div>
          <div>
            <label class="label" for="f-company">نام شرکت / تیم <span class="req-star">*</span></label>
            <input class="input" id="f-company" name="company" value="<?= e($v('company', $p['company'])) ?>">
            <?php if (!empty($fields['company_alt']) && $fields['company_alt'] !== $p['company']): ?>
              <p class="hint">نام دیگری که در فایل دیده شد:
                <button type="button" class="link" data-fill="#f-company" data-value="<?= e($fields['company_alt']) ?>">«<?= e($fields['company_alt']) ?>»</button></p>
            <?php endif; ?>
          </div>
          <div>
            <label class="label" for="f-output">خروجی اصلی مورد انتظار</label>
            <textarea class="input" id="f-output" name="expected_output" rows="3"><?= e($v('expected_output', $p['expected_output'])) ?></textarea>
          </div>

          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="label" for="f-amount">مبلغ درخواستی شرکت <span class="font-normal text-slate-500">(میلیارد ریال)</span></label>
              <input class="input ltr num" id="f-amount" name="requested_amount" value="<?= e($v('requested_amount', $amount)) ?>" inputmode="decimal" placeholder="مثال: 25" data-toman-hint="#f-amount-toman" <?= $finalized ? 'readonly' : '' ?>>
              <p class="hint" id="f-amount-toman"><?= $amount !== '' ? 'برابر با ' . e(toman_hint($amount)) : 'برای ۵ میلیارد تومان عدد ۵۰ را وارد کنید.' ?></p>
            </div>
            <div>
              <label class="label" for="f-due">مهلت داوری <span class="font-normal text-slate-500">(اختیاری)</span></label>
              <input class="input ltr num" id="f-due" name="due_date" value="<?= e($v('due_date', $dueJalali)) ?>" placeholder="۱۴۰۵/۰۹/۱۵" autocomplete="off" data-jdate>
              <p class="hint">در ایمیل دعوت و بالای فرم داور نمایش داده می‌شود.</p>
            </div>
          </div>

          <?php if ($suggestions && !$finalized): ?>
            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-xs text-slate-600 leading-6">
              <div class="font-semibold text-slate-700 mb-1">کاربرگ فیلد «مبلغ درخواستی» ندارد.</div>
              جمع جدول هزینه‌ها در فایل «<span class="num ltr"><?= e(fa((string) ($fields['cost_total_raw'] ?? ''))) ?></span>» نوشته شده است<?= !empty($fields['cost_unit']) ? ' (سرستون: ' . e($fields['cost_unit']) . ')' : '' ?>. اگر مبلغ درخواستی همین است، یکی را انتخاب کنید:
              <div class="flex flex-wrap gap-2 mt-2">
                <?php foreach ($suggestions as $s): ?>
                  <button type="button" class="btn btn-secondary btn-sm" data-fill="#f-amount" data-value="<?= e((string) $s['value']) ?>"><?= e(money($s['value'])) ?> <span class="font-normal text-slate-500">— <?= e($s['label']) ?></span></button>
                <?php endforeach; ?>
              </div>
              <div class="mt-2 text-slate-500">اگر مبلغ را خالی بگذارید، هر داور آن را در فرم خود وارد می‌کند و در صورت تفاوت، شما رقم درست را مشخص می‌کنید.</div>
            </div>
          <?php elseif (!$finalized && $amount === ''): ?>
            <p class="hint -mt-2">اگر مبلغ را خالی بگذارید، هر داور آن را در فرم خود وارد می‌کند و در صورت تفاوت، شما رقم درست را مشخص می‌کنید.</p>
          <?php endif; ?>

          <div>
            <label class="label" for="f-notes">یادداشت داخلی <span class="font-normal text-slate-500">(داوران نمی‌بینند)</span></label>
            <textarea class="input" id="f-notes" name="admin_notes" rows="2"><?= e($v('admin_notes', $p['admin_notes'])) ?></textarea>
          </div>

          <div class="flex flex-col gap-2">
            <label class="check"><input type="checkbox" name="share_doc" value="1" <?= $p['share_doc'] ? 'checked' : '' ?>><span>متن کاربرگ در صفحه فرم برای داوران نمایش داده شود</span></label>
            <label class="check"><input type="checkbox" name="share_file" value="1" <?= $p['share_file'] ? 'checked' : '' ?>><span>داوران بتوانند فایل Word اصلی را دانلود کنند</span></label>
          </div>

          <div class="flex items-center gap-2 pt-2 no-print">
            <?php if (!$reviewed): ?>
              <button class="btn btn-success" type="submit" name="action" value="confirm"><?= icon('check', 'w-4 h-4') ?> تأیید اطلاعات و ادامه</button>
              <button class="btn btn-secondary" type="submit" name="action" value="save">فقط ذخیره</button>
            <?php else: ?>
              <button class="btn btn-primary" type="submit" name="action" value="save">ذخیره تغییرات</button>
            <?php endif; ?>
          </div>
        </form>

        <!-- what else was found in the file -->
        <aside class="lg:col-span-2 space-y-4">
          <div class="rounded-xl border border-slate-200 p-4">
            <div class="flex items-center justify-between gap-2 mb-2">
              <h3 class="text-sm font-bold text-slate-800">فایل بارگذاری‌شده</h3>
              <?= badge($extract[0], $extract[1]) ?>
            </div>
            <table class="kv text-xs">
              <tr><th>نام فایل</th><td class="break-all" dir="auto"><?= e($p['original_name']) ?></td></tr>
              <tr><th>حجم</th><td><?= e(filesize_h($p['file_size'])) ?></td></tr>
              <tr><th>بارگذاری</th><td class="num"><?= e(jdate($p['created_at'])) ?></td></tr>
              <?php if (!empty($fields['filled_date'])): ?><tr><th>تاریخ تکمیل کاربرگ</th><td class="num"><?= e(fa($fields['filled_date'])) ?></td></tr><?php endif; ?>
              <?php if (!empty($p['doc_type'])): ?><tr><th>نوع سند</th><td><?= e(str_limit($p['doc_type'], 70)) ?></td></tr><?php endif; ?>
            </table>
            <div class="flex items-center gap-2 mt-3 no-print">
              <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/proposals/' . $id . '/file')) ?>"><?= icon('download', 'w-4 h-4') ?> دانلود</a>
              <form method="post" action="<?= e(url('/admin/proposals/' . $id . '/reextract')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-ghost btn-sm" type="submit" title="فایل دوباره خوانده و اطلاعات استخراج‌شده به‌روز می‌شود"><?= icon('refresh', 'w-4 h-4') ?> خواندن دوباره</button>
              </form>
            </div>
          </div>

          <?php if ($facts || !empty($fields['trl_declared']) || !empty($fields['request_type'])): ?>
          <div class="rounded-xl border border-slate-200 p-4">
            <h3 class="text-sm font-bold text-slate-800 mb-2">مشخصات شرکت و طرح <span class="font-normal text-xs text-slate-400">(از کاربرگ)</span></h3>
            <table class="kv text-xs">
              <?php foreach ($facts as $fact): ?>
                <tr><th><?= e($fact['label']) ?></th><td dir="auto"><?= e(fa((string) $fact['value'])) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!empty($fields['trl_declared'])): ?>
                <tr><th>TRL اعلامی شرکت</th><td><span class="badge badge-blue">سطح <?= fa((string) $fields['trl_declared']) ?></span></td></tr>
              <?php endif; ?>
              <?php if (!empty($fields['request_type'])): ?>
                <tr><th>نوع درخواست</th><td><?= e(implode('، ', (array) $fields['request_type'])) ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($fields['team_count'])): ?>
                <tr><th>اعضای تیم پروژه</th><td><?= fa((string) $fields['team_count']) ?> نفر</td></tr>
              <?php endif; ?>
              <?php if (!empty($fields['cost_total_raw'])): ?>
                <tr><th>جمع جدول هزینه‌ها</th><td class="num ltr text-end"><?= e(fa((string) $fields['cost_total_raw'])) ?></td></tr>
              <?php endif; ?>
            </table>
          </div>
          <?php endif; ?>
        </aside>
      </div>

      <?php if (!empty($fields['idea_summary']) || !empty($fields['outputs']) || !empty($team['rows'])): ?>
      <div class="grid md:grid-cols-2 gap-4 mt-6">
        <?php if (!empty($fields['idea_summary'])): ?>
          <div class="rounded-xl bg-slate-50 border border-slate-100 p-4">
            <h3 class="text-xs font-bold text-slate-500 mb-1">خلاصه ایده و اقدامات انجام‌شده</h3>
            <div class="text-sm leading-7 text-slate-800" dir="auto"><?= nl2p($fields['idea_summary']) ?></div>
          </div>
        <?php endif; ?>
        <?php if (!empty($fields['outputs'])): ?>
          <div class="rounded-xl bg-slate-50 border border-slate-100 p-4">
            <h3 class="text-xs font-bold text-slate-500 mb-1">خروجی‌های مورد انتظار</h3>
            <ul class="list-disc ps-5 text-sm leading-7 text-slate-800">
              <?php foreach ((array) $fields['outputs'] as $o): ?><li dir="auto"><?= e($o) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <details class="mt-6 rounded-xl border border-slate-200 group/doc">
        <summary class="px-4 py-3 flex items-center justify-between gap-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 rounded-xl">
          <span class="flex items-center gap-2"><?= icon('doc', 'w-5 h-5 text-brand-700') ?> متن کامل کاربرگ (آن‌گونه که داوران می‌بینند)</span>
          <?= icon('chevron', 'w-4 h-4 rot transition') ?>
        </summary>
        <div class="p-4 border-t border-slate-200 max-h-[70vh] overflow-y-auto"><?= $docHtml ?></div>
      </details>
    </div>
  </details>
</section>
