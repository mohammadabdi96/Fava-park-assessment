<?php
/**
 * HTML e-mail (inline styles only – mail clients ignore stylesheets).
 * @var string $org
 * @var string $heading
 * @var string $body        plain text
 * @var array|null $button  ['label' => , 'url' => ]
 * @var array $facts        label => value
 * @var string $footnote
 * @var string $footlink
 */
$font = "font-family:Tahoma,'Segoe UI',Arial,sans-serif;";
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($heading) ?></title></head>
<body style="margin:0;padding:0;background:#f1f5f9;<?= $font ?>">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;" dir="rtl">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;" dir="rtl">
      <tr><td style="background:#1e40af;padding:20px 28px;text-align:right;<?= $font ?>">
        <div style="color:#bfdbfe;font-size:12px;"><?= e($org) ?></div>
        <div style="color:#ffffff;font-size:18px;font-weight:bold;margin-top:4px;"><?= e($heading) ?></div>
      </td></tr>
      <tr><td style="padding:26px 28px 8px;text-align:right;color:#1e293b;font-size:14px;line-height:2;<?= $font ?>">
        <?= nl2br(e($body)) ?>
      </td></tr>
      <?php if ($facts): ?>
      <tr><td style="padding:8px 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;" dir="rtl">
          <?php foreach ($facts as $label => $value): ?>
          <tr>
            <td style="padding:8px 14px;color:#64748b;font-size:12px;white-space:nowrap;text-align:right;vertical-align:top;<?= $font ?>" width="90"><?= e($label) ?></td>
            <td style="padding:8px 14px;color:#0f172a;font-size:13px;font-weight:bold;text-align:right;line-height:1.8;<?= $font ?>"><?= e($value) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </td></tr>
      <?php endif; ?>
      <?php if (!empty($button)): ?>
      <tr><td align="center" style="padding:20px 28px 10px;">
        <a href="<?= e($button['url']) ?>" style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:13px 30px;border-radius:10px;<?= $font ?>"><?= e($button['label']) ?></a>
      </td></tr>
      <?php endif; ?>
      <?php if ($footlink !== ''): ?>
      <tr><td style="padding:10px 28px 4px;text-align:right;color:#64748b;font-size:12px;line-height:1.9;<?= $font ?>">
        <?= e($footnote) ?><br>
        <a href="<?= e($footlink) ?>" style="color:#1d4ed8;word-break:break-all;direction:ltr;unicode-bidi:embed;font-size:12px;"><?= e($footlink) ?></a>
      </td></tr>
      <?php endif; ?>
      <tr><td style="padding:18px 28px 22px;text-align:right;color:#94a3b8;font-size:11px;line-height:1.8;border-top:1px solid #f1f5f9;<?= $font ?>">
        این پیام به‌صورت خودکار از سامانه ارزش‌گذاری طرح‌های <?= e($org) ?> فرستاده شده است.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
