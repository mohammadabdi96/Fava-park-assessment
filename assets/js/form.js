/*!
 * Fava assessment – the judge's form.
 *
 * Live calculation (through FavaScoring, the browser copy of the server rules), progress,
 * the bullet list of reasons, automatic saving of the draft and the final submission.
 * The server checks and recalculates everything again when the form is sent.
 */
(function () {
  'use strict';

  var bootEl = document.getElementById('form-boot');
  if (!bootEl || !window.FavaScoring) { return; }
  var boot = JSON.parse(bootEl.textContent || '{}');
  var cfg = boot.cfg, S = window.FavaScoring, J = window.FavaJalali;
  var role = cfg.role, isTech = role === 'tech';
  var preview = !!boot.preview || !boot.urls;
  var fixedAmount = (boot.fixedAmount === null || boot.fixedAmount === undefined) ? null : Number(boot.fixedAmount);

  var FA = '۰۱۲۳۴۵۶۷۸۹';
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function byId(id) { return document.getElementById(id); }
  function toFa(s) { return String(s).replace(/\d/g, function (d) { return FA.charAt(+d); }); }
  function toLatin(s) {
    return String(s).replace(/[۰-۹]/g, function (c) { return String(FA.indexOf(c)); })
      .replace(/[٠-٩]/g, function (c) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(c)); });
  }
  /** fixed number of decimals, Persian digits (same rounding as the admin panel) */
  function fx(n, decimals) { return toFa(S.round(n, decimals).toFixed(decimals)); }
  /** up to two decimals, no trailing zeros */
  function short(n) { return toFa(String(parseFloat(Number(n).toFixed(3)))); }
  /** Same rule as Fa::number() on the server; an amount must be above zero. */
  function parseAmount(value) {
    var s = toLatin(String(value || '')).replace(/[\s\u00A0\u200C\u200E\u200F]/g, '').replace(/٫/g, '.').replace(/[٬،]/g, ',');
    if (/^\d+\/\d+$/.test(s)) { s = s.replace('/', '.'); }
    if (s.indexOf(',') !== -1) {
      if (!/^\d{1,3}(,\d{3})+(\.\d+)?$/.test(s)) { return null; }
      s = s.replace(/,/g, '');
    }
    if (!/^(\d+\.?\d*|\.\d+)$/.test(s)) { return null; }
    var n = parseFloat(s);
    return (n > 0 && n <= 1000000) ? S.round(n, 3) : null;
  }
  function setText(id, text) { var el = byId(id); if (el) { el.textContent = text; } }

  var el = {
    amount: byId('requested-amount'), tierNotes: byId('d-tier'), scoreNotes: byId('d-score'),
    milestone: byId('milestone-2-def'), commitment: byId('commitment'),
    name: byId('assessor-name'), date: byId('assessor-date'),
    reasons: byId('reasons-list'), submit: byId('submit-btn'), status: byId('save-status')
  };
  var shareMax = Number(boot.shareMax) || 50;
  var CHECK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="14" height="14" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>';
  var decisions = cfg.decisions || {};

  // ---------------------------------------------------------------- reading the form
  function radio(name) { var r = $('input[name="' + name + '"]:checked'); return r ? r.value : null; }
  function reasons() {
    return $all('textarea', el.reasons).map(function (t) { return t.value.trim(); }).filter(Boolean);
  }
  function scores() {
    var out = {};
    $all('.crit-row[data-crit]').forEach(function (row) {
      var sel = $('.score-btn.selected', row);
      if (sel) { out[row.getAttribute('data-crit')] = parseInt(sel.getAttribute('data-v'), 10); }
    });
    return out;
  }
  function collect() {
    var a = {
      scalability: radio('scalability'),
      priority: radio('priority'),
      tier_notes: el.tierNotes ? el.tierNotes.value : '',
      scores: scores(),
      score_notes: el.scoreNotes ? el.scoreNotes.value : '',
      reasons: reasons(),
      commitment: !!(el.commitment && el.commitment.checked),
      name: el.name ? el.name.value.trim() : '',
      date: el.date ? toLatin(el.date.value.trim()) : '',
      requested_amount: el.amount ? parseAmount(el.amount.value) : null,
      q: {}
    };
    $all('textarea[data-q]').forEach(function (t) { a.q[t.getAttribute('data-q')] = t.value; });
    if (isTech) {
      a.trl = radio('trl') ? parseInt(radio('trl'), 10) : null;
      a.risk_exec = radio('risk_exec') ? parseInt(radio('risk_exec'), 10) : null;
      a.milestone2 = el.milestone ? el.milestone.value : '';
    } else {
      a.risk_market = radio('risk_market') ? parseInt(radio('risk_market'), 10) : null;
    }
    return a;
  }

  // ---------------------------------------------------------------- writing the form
  function setRadio(name, value) {
    $all('input[name="' + name + '"]').forEach(function (r) { r.checked = value !== null && value !== undefined && String(r.value) === String(value); });
  }
  function setScore(row, value) {
    var label = $('[data-val]', row);
    $all('.score-btn', row).forEach(function (b) {
      var on = value && parseInt(b.getAttribute('data-v'), 10) === value;
      b.classList.toggle('selected', !!on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (label) { label.textContent = value ? FA.charAt(value) : '—'; }
  }
  function apply(a) {
    if (!a || typeof a !== 'object') { return; }
    setRadio('scalability', a.scalability);
    setRadio('priority', a.priority);
    if (el.tierNotes) { el.tierNotes.value = a.tier_notes || ''; }
    if (el.scoreNotes) { el.scoreNotes.value = a.score_notes || ''; }
    $all('.crit-row[data-crit]').forEach(function (row) {
      var v = a.scores ? parseInt(a.scores[row.getAttribute('data-crit')], 10) : 0;
      setScore(row, v >= 1 && v <= 5 ? v : 0);
    });
    if (el.commitment) { el.commitment.checked = !!a.commitment; }
    if (el.name && a.name) { el.name.value = a.name; }
    if (el.amount) { el.amount.value = (a.requested_amount !== null && a.requested_amount !== undefined) ? String(a.requested_amount) : ''; }
    $all('textarea[data-q]').forEach(function (t) { t.value = (a.q && a.q[t.getAttribute('data-q')]) || ''; });
    if (isTech) {
      setRadio('trl', a.trl);
      setRadio('risk_exec', a.risk_exec);
      if (el.milestone) { el.milestone.value = a.milestone2 || ''; }
    } else {
      setRadio('risk_market', a.risk_market);
    }
    el.reasons.innerHTML = '';
    (a.reasons && a.reasons.length ? a.reasons : ['']).forEach(function (r) { addReason(r, false); });
  }

  // ---------------------------------------------------------------- bullet list of reasons
  function autosize(t) {
    t.style.height = 'auto';
    t.style.height = Math.max(44, t.scrollHeight + 2) + 'px';
  }
  function addReason(value, focus) {
    var row = document.createElement('div');
    row.className = 'reason-row';
    var bullet = document.createElement('span');
    bullet.className = 'bullet';
    bullet.textContent = '•';
    var t = document.createElement('textarea');
    t.className = 'flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm';
    t.rows = 1;
    t.maxLength = 2000;
    t.placeholder = 'دلیل/شرط را بنویسید... (Enter = بولت بعدی)';
    t.setAttribute('aria-label', 'دلیل یا شرط');
    t.value = value || '';
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'remove-btn';
    remove.title = 'حذف';
    remove.setAttribute('aria-label', 'حذف این بولت');
    remove.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>';
    row.appendChild(bullet);
    row.appendChild(t);
    row.appendChild(remove);
    el.reasons.appendChild(row);

    t.addEventListener('input', function () { autosize(t); });
    t.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
        ev.preventDefault();
        if (!t.value.trim()) { return; }
        var next = row.nextElementSibling;
        if (next && !$('textarea', next).value.trim()) { $('textarea', next).focus(); } else { addReason('', true); changed(); }
      } else if (ev.key === 'Backspace' && t.value === '' && $all('.reason-row', el.reasons).length > 1) {
        ev.preventDefault();
        var prev = row.previousElementSibling;
        row.parentNode.removeChild(row);
        if (prev) { $('textarea', prev).focus(); }
        changed();
      }
    });
    remove.addEventListener('click', function () {
      if ($all('.reason-row', el.reasons).length === 1) { t.value = ''; autosize(t); } else { row.parentNode.removeChild(row); }
      changed();
    });
    autosize(t);
    if (focus) { t.focus(); }
  }

  // ---------------------------------------------------------------- what is still missing
  var showMarks = false;

  /** Required items in the order they appear on the page: {key, done, node, focus} */
  function requiredItems(a, m) {
    var items = [];
    if (fixedAmount === null) {
      items.push({ key: 'requested_amount', done: a.requested_amount !== null, node: el.amount, kind: 'field' });
    }
    items.push({ key: 'scalability', done: !!a.scalability, node: $('[data-field="scalability"]'), kind: 'group' });
    items.push({ key: 'priority', done: !!a.priority, node: $('[data-field="priority"]'), kind: 'group' });
    $all('.crit-row[data-crit]').forEach(function (row) {
      items.push({ key: 'scores', done: !!a.scores[row.getAttribute('data-crit')], node: row, kind: 'crit' });
    });
    if (isTech) {
      items.push({ key: 'trl', done: a.trl !== null, node: $('[data-field="trl"]'), kind: 'group' });
      items.push({ key: 'risk_exec', done: a.risk_exec !== null, node: $('[data-field="risk_exec"]'), kind: 'group' });
      if (m.estimated_decision !== 'reject') {
        items.push({ key: 'milestone2', done: a.milestone2.trim() !== '', node: el.milestone, kind: 'field' });
      }
    } else {
      items.push({ key: 'risk_market', done: a.risk_market !== null, node: $('[data-field="risk_market"]'), kind: 'group' });
    }
    items.push({ key: 'reasons', done: a.reasons.length > 0, node: $('[data-field="reasons"]'), kind: 'reasons' });
    items.push({ key: 'commitment', done: a.commitment, node: $('[data-field="commitment"]'), kind: 'group' });
    items.push({ key: 'name', done: a.name !== '', node: el.name, kind: 'field' });
    items.push({ key: 'date', done: !!(J && J.parse(a.date)), node: el.date, kind: 'field' });
    return items;
  }

  function mark(items) {
    items.forEach(function (it) {
      if (!it.node) { return; }
      var bad = showMarks && !it.done;
      if (it.kind === 'crit') { it.node.classList.toggle('crit-missing', bad); }
      else if (it.kind === 'field') { it.node.classList.toggle('field-missing', bad); }
      else { it.node.classList.toggle('group-missing', bad); }
      if (it.kind === 'reasons') { byId('reasons-error').classList.toggle('hidden', !bad); }
    });
  }

  function focusFirstMissing(items) {
    var first = null;
    for (var i = 0; i < items.length; i++) { if (!items[i].done && items[i].node) { first = items[i]; break; } }
    if (!first) { return; }
    first.node.scrollIntoView({ behavior: 'smooth', block: 'center' });
    var target = first.kind === 'field' ? first.node : $('input, button, textarea', first.node);
    if (target) { setTimeout(function () { target.focus({ preventScroll: true }); }, 350); }
  }

  // ---------------------------------------------------------------- live calculation
  var last = { answers: null, metrics: null, items: [] };

  function recalc() {
    var a = collect();
    var m = S.judge(cfg, a, fixedAmount);

    /* layer 1 – tier */
    if (m.tier) {
      var t = cfg.tiers[m.tier];
      byId('tier-result').classList.remove('hidden');
      setText('tier-label', t.label);
      setText('tier-ceiling', short(t.ceiling) + ' میلیارد ریال');
      setText('sum-tier', t.short || m.tier);
    } else {
      byId('tier-result').classList.add('hidden');
      setText('sum-tier', '—');
    }

    /* layer 2 – averages and this judge's share */
    Object.keys(m.dims).forEach(function (dim) {
      var badge = $('[data-dim-avg="' + dim + '"]');
      if (badge) { badge.textContent = Object.keys(a.scores).length ? fx(m.dims[dim].avg, 2) : '—'; }
    });
    var share = m.complete ? m.share : null;
    setText('share-value', share !== null ? fx(share, 1) : '—');
    setText('sum-share', share !== null ? fx(share, 1) + ' / ' + short(shareMax) : '—');

    var d = m.estimated_decision ? decisions[m.estimated_decision] : null;
    var shareBox = byId('share-box');
    if (d) {
      setText('share-interp', d.title + ' (تخمینی)');
      setText('share-decision', 'پیشنهاد تخمینی: ' + d.label + ' | تصمیم نهایی با ادمین');
      var cmap = { green: 'bg-green-50 border-green-300', amber: 'bg-amber-50 border-amber-300', red: 'bg-red-50 border-red-300' };
      shareBox.className = 'rounded-xl p-3 border text-center ' + (cmap[d.color] || 'bg-gray-50 border-gray-200');
    } else {
      setText('share-interp', '—');
      setText('share-decision', 'تصمیم نهایی پس از تجمیع دو داور');
      shareBox.className = 'bg-gray-50 rounded-xl p-3 border border-gray-200 text-center';
    }

    /* layer 3 – risk */
    $all('[data-trl-display]').forEach(function (n) { n.textContent = m.trl ? 'سطح ' + toFa(m.trl) : '—'; });
    $all('[data-trl-score]').forEach(function (n) { n.textContent = m.trl_risk !== null ? toFa(m.trl_risk) : '—'; });
    $all('[data-risk-val]').forEach(function (n) {
      var v = m[n.getAttribute('data-risk-val')];
      n.textContent = (v === null || v === undefined) ? '—' : toFa(v);
    });
    setText('r-avg', m.risk_avg !== null ? fx(m.risk_avg, 2) : '—');
    setText('coeff', m.coeff !== null ? fx(m.coeff, 3) : '—');

    /* layer 4 – first estimate */
    var card = byId('estimate-card');
    if (m.estimate !== null) {
      setText('calc-estimate', fx(m.estimate, 2) + ' میلیارد ریال');
      setText('estimate-subtext', 'بر اساس امتیاز تخمینی ' + fx(m.estimated_total, 1) + ' و ضریب ' + fx(m.coeff, 3) + ' — نهایی نیست');
      setText('estimate-share', fx(m.share, 1) + ' / ' + short(shareMax));
      card.className = 'rounded-xl p-4 border-2 ' + (m.estimated_decision === 'approve' ? 'share-card-supportive'
        : (m.estimated_decision === 'conditional' ? 'share-card-caution' : 'share-card-negative'));
    } else {
      setText('calc-estimate', '—');
      setText('estimate-subtext', 'در انتظار تکمیل داده‌ها...');
      setText('estimate-share', '—');
      card.className = 'rounded-xl p-4 border-2 bg-gray-50 border-gray-200';
    }
    var tomanHint = byId('amount-toman');
    if (tomanHint) {
      var req = a.requested_amount, typed = el.amount ? el.amount.value.replace(/\s/g, '') : '';
      if (req) {
        tomanHint.className = 'font-semibold text-blue-800';
        tomanHint.textContent = ' مبلغ واردشده برابر است با ' + (req * 100 >= 1000 ? short(req / 10) + ' میلیارد تومان' : short(req * 100) + ' میلیون تومان') + '.';
      } else if (typed !== '') {
        // typed, but not a usable amount: say so instead of silently counting the field as empty
        tomanHint.className = 'font-semibold text-red-600 block mt-1';
        tomanHint.textContent = 'این مقدار به‌عنوان مبلغ خوانده نشد. فقط عددِ بزرگ‌تر از صفر وارد کنید و اعشار را با نقطه بنویسید (مثلاً 18.5).';
      } else {
        tomanHint.textContent = '';
      }
    }

    /* milestone – technical judge only, not needed when the estimate is «reject» */
    if (isTech && el.milestone) {
      var rejected = m.estimated_decision === 'reject';
      byId('milestone-req-star').style.display = rejected ? 'none' : '';
      setText('milestone-hint', rejected ? 'امتیاز تخمینی در بازه رد است؛ تکمیل این کادر الزامی نیست.' : 'برای طرح‌های تأییدشده یا مشروط، الزامی است.');
      if (rejected) { el.milestone.classList.remove('field-missing'); }
    }

    /* guide above the bullets */
    var guide = byId('reasons-guide');
    if (m.estimated_decision === 'reject') {
      guide.innerHTML = 'امتیاز تخمینی در بازه <b class="text-red-700">رد</b> است. لطفاً <b>دلایل قطعی رد</b> را ذکر کنید.';
    } else if (m.estimated_decision === 'conditional') {
      guide.innerHTML = 'امتیاز تخمینی در بازه <b class="text-amber-700">تأیید مشروط</b> است. لطفاً <b>شروط تأیید</b> را ذکر کنید.';
    } else if (m.estimated_decision === 'approve') {
      guide.innerHTML = 'امتیاز تخمینی در بازه <b class="text-green-700">تأیید</b> است. لطفاً <b>دلایل تأیید</b> را ذکر کنید.';
    } else {
      guide.textContent = 'دلایل قطعی و مشخص خود را به‌صورت بولت‌پوینت ذکر کنید. حداقل یک بولت با متن الزامی است.';
    }

    /* stepper */
    var states = [!!m.tier, !!m.complete, m.coeff !== null, a.reasons.length > 0];
    var firstOpen = states.indexOf(false);
    $all('#stepper [data-step]').forEach(function (stepEl, idx) {
      var dot = $('.fstep-dot', stepEl);
      dot.className = 'fstep-dot ' + (states[idx] ? 'done' : (idx === firstOpen ? 'active' : 'todo'));
      if (states[idx]) { dot.innerHTML = CHECK; } else { dot.textContent = toFa(idx + 1); }
    });

    /* progress and the submit button */
    var items = requiredItems(a, m);
    var total = items.length, done = items.filter(function (it) { return it.done; }).length;
    var pct = Math.min(100, Math.round(done / total * 100));
    var fill = byId('progress-fill');
    fill.style.width = pct + '%';
    fill.style.backgroundColor = pct === 100 ? '#10b981' : (pct < 50 ? '#f87171' : '#f59e0b');
    setText('progress-text', toFa(pct) + '٪');
    var hint = byId('progress-hint'), showBtn = byId('show-missing');
    if (done >= total) {
      hint.textContent = preview ? 'فرم کامل است (پیش‌نمایش).' : 'فرم کامل است؛ می‌توانید ثبت نهایی کنید.';
      hint.className = 'text-xs text-green-600 font-semibold';
      showBtn.classList.add('hidden');
      el.submit.disabled = preview || sending;
      showMarks = false;
    } else {
      hint.textContent = toFa(total - done) + ' فیلد باقی مانده.';
      hint.className = 'text-xs text-gray-400';
      showBtn.classList.remove('hidden');
      el.submit.disabled = true;
    }
    mark(items);
    last = { answers: a, metrics: m, items: items };
    return last;
  }

  // ---------------------------------------------------------------- saving the draft
  var storage = null;
  try { storage = window.localStorage; storage.getItem('fava-test'); } catch (err) { storage = null; }
  var key = boot.storageKey || '';
  var rev = boot.rev || '';       // which server draft the local copy is based on
  var dirty = false, saving = false, sending = false, saveTimer = null, retryTimer = null, dead = false;

  function setStatus(text, tone) {
    if (!el.status) { return; }
    el.status.textContent = text;
    el.status.title = text; // the whole message, should the narrow bar of a phone cut it
    var tones = ['text-red-600', 'font-semibold', 'text-emerald-600', 'text-gray-400'];
    tones.forEach(function (c) { el.status.classList.remove(c); });
    (tone === 'bad' ? ['text-red-600', 'font-semibold'] : (tone === 'ok' ? ['text-emerald-600'] : ['text-gray-400']))
      .forEach(function (c) { el.status.classList.add(c); });
  }
  function localSave(synced) {
    if (!storage || !key || preview) { return; }
    try { storage.setItem(key, JSON.stringify({ v: 1, synced: !!synced, rev: rev, answers: collect() })); } catch (err) { /* storage full or blocked */ }
  }
  function localLoad() {
    if (!storage || !key || preview) { return null; }
    try {
      var data = JSON.parse(storage.getItem(key) || 'null');
      return (data && data.v === 1 && data.answers) ? data : null;
    } catch (err) { return null; }
  }
  function localClear() {
    if (storage && key) { try { storage.removeItem(key); } catch (err) { /* ignore */ } }
  }

  function post(url, body, keepalive) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'same-origin',
      cache: 'no-store',
      keepalive: !!keepalive
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) { return { status: res.status, data: data || {} }; });
    });
  }

  function linkDead(message) {
    dead = true;
    clearTimeout(saveTimer);
    clearTimeout(retryTimer);
    setStatus('پیوند نامعتبر', 'bad');
    el.submit.disabled = true;
    var box = byId('submit-error');
    box.textContent = message || 'این پیوند دیگر معتبر نیست. از دبیرخانه بخواهید پیوند تازه را برای شما بفرستد. پاسخ‌های شما در این مرورگر نگه داشته شده است.';
    box.classList.remove('hidden');
    window.alert(box.textContent);
  }

  function save(keepalive) {
    if (preview || dead || sending || saving || !dirty) { return; }
    saving = true;
    dirty = false;
    clearTimeout(retryTimer);
    setStatus('در حال ذخیره…');
    post(boot.urls.draft, { answers: collect() }, keepalive).then(function (r) {
      saving = false;
      if (r.status === 200 && r.data.ok) {
        rev = r.data.rev || rev;
        if (dirty) { localSave(false); scheduleSave(); } else { localSave(true); }
        setStatus('ذخیره شد ' + (r.data.saved_at || ''), 'ok');
      } else if (r.status === 409) {
        localClear();
        window.location.reload(); // already sent (e.g. from another tab)
      } else if (r.status === 404) {
        linkDead(r.data.error);
      } else {
        throw new Error('HTTP ' + r.status);
      }
    }).catch(function () {
      saving = false;
      dirty = true;
      localSave(false);
      setStatus('ذخیره روی سرور انجام نشد؛ پاسخ‌ها در همین مرورگر نگه‌داری می‌شود', 'bad');
      retryTimer = setTimeout(function () { save(false); }, 15000);
    });
  }
  function scheduleSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () { save(false); }, 1200);
  }
  /** something in the form changed */
  function changed() {
    recalc();
    if (preview || dead) { return; }
    dirty = true;
    localSave(false);
    scheduleSave();
  }

  // ---------------------------------------------------------------- final submission
  var modal = byId('confirm-modal');
  function closeModal() { modal.classList.remove('open'); }
  function openModal() {
    var m = last.metrics, d = m.estimated_decision ? decisions[m.estimated_decision] : null;
    setText('cf-tier', m.tier ? cfg.tiers[m.tier].label : '—');
    setText('cf-share', fx(m.share, 1) + ' از ' + short(shareMax));
    setText('cf-decision', d ? d.title + ' — ' + d.label : '—');
    modal.classList.add('open');
    byId('confirm-cancel').focus();
  }

  function showSubmitError(message) {
    var box = byId('submit-error');
    box.textContent = message;
    box.classList.remove('hidden');
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function submit() {
    if (preview || dead || sending) { return; }
    sending = true;
    clearTimeout(saveTimer);
    clearTimeout(retryTimer);
    byId('submit-error').classList.add('hidden');
    el.submit.disabled = true;
    var label = byId('submit-btn-text'), labelText = label.textContent;
    label.textContent = 'در حال ثبت…';
    localSave(false);
    post(boot.urls.submit, { answers: collect() }, false).then(function (r) {
      if (r.status === 200 && r.data.ok && r.data.redirect) {
        localClear();
        window.location.replace(r.data.redirect);
        return;
      }
      sending = false;
      label.textContent = labelText;
      if (r.status === 422) {
        showMarks = true;
        var state = recalc();
        // anything the server found that this page did not (should not happen, but the server decides)
        Object.keys(r.data.errors || {}).forEach(function (k) {
          var node = $('[data-field="' + k + '"]');
          if (node && !state.items.some(function (it) { return it.key === k && !it.done; })) { node.classList.add('group-missing'); }
        });
        var list = Object.keys(r.data.errors || {}).map(function (k) { return r.data.errors[k]; });
        showSubmitError((r.data.error || 'فرم کامل نیست.') + (list.length ? ' ' + list.join(' ') : ''));
        focusFirstMissing(state.items);
      } else if (r.status === 404) {
        linkDead(r.data.error);
      } else {
        recalc();
        showSubmitError(r.data.error || 'ثبت فرم انجام نشد. چند لحظه دیگر دوباره تلاش کنید.');
      }
    }).catch(function () {
      sending = false;
      label.textContent = labelText;
      dirty = true;
      recalc();
      showSubmitError('ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی و دوباره «ثبت نهایی» را بزنید. پاسخ‌های شما در همین مرورگر نگه داشته شده است.');
    });
  }

  // ---------------------------------------------------------------- events
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.score-btn');
    if (btn) {
      var row = btn.closest('.crit-row');
      var v = parseInt(btn.getAttribute('data-v'), 10);
      setScore(row, btn.classList.contains('selected') ? 0 : v); // a second click clears the score
      changed();
      return;
    }
    if (e.target.closest('[data-drawer-open]')) { drawer(true); return; }
    if (e.target.closest('[data-drawer-close]')) { drawer(false); return; }
  });
  document.addEventListener('input', function (e) {
    if (e.target.matches && e.target.matches('#form-container input, #form-container textarea')) {
      e.target.classList.remove('field-missing');
      changed();
    }
  });
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('#form-container input[type="radio"], #form-container input[type="checkbox"]')) { changed(); }
  });
  byId('add-reason').addEventListener('click', function () { addReason('', true); changed(); });
  byId('show-missing').addEventListener('click', function () {
    showMarks = true;
    focusFirstMissing(recalc().items);
  });
  el.submit.addEventListener('click', function () {
    var state = recalc();
    if (el.submit.disabled) { return; }
    if (state.items.some(function (it) { return !it.done; })) { showMarks = true; focusFirstMissing(recalc().items); return; }
    openModal();
  });
  byId('confirm-cancel').addEventListener('click', closeModal);
  byId('confirm-ok').addEventListener('click', function () { closeModal(); submit(); });
  modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeModal(); drawer(false); }
  });

  /* the worksheet beside the form */
  var drawerEl = byId('doc-drawer'), backdrop = byId('doc-backdrop');
  function drawer(open) {
    if (!drawerEl) { return; }
    drawerEl.classList.toggle('open', open);
    drawerEl.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (backdrop) { backdrop.classList.toggle('open', open); }
    document.body.classList.toggle('doc-open', open);
  }

  /* leaving the page: push the last changes */
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { clearTimeout(saveTimer); save(true); }
  });
  window.addEventListener('pagehide', function () { clearTimeout(saveTimer); save(true); });
  window.addEventListener('online', function () { if (dirty) { save(false); } });
  window.addEventListener('pageshow', function (e) { if (e.persisted) { window.location.reload(); } });

  // ---------------------------------------------------------------- start
  el.reasons.innerHTML = '';
  var initial = boot.answers || {};
  (initial.reasons && initial.reasons.length ? initial.reasons : ['']).forEach(function (r) { addReason(r, false); });

  var local = localLoad();
  if (local && !local.synced && local.rev === rev) {
    // changes made in this browser that never reached the server (connection lost, tab closed)
    var keepDate = el.date ? el.date.value : '';
    apply(local.answers);
    if (el.date) { el.date.value = keepDate; } // the completion date always starts as today
    dirty = true;
    setStatus('پاسخ‌های ذخیره‌نشده از همین مرورگر بازیابی شد');
  } else if (local) {
    localClear();
  }
  recalc();

  if (!preview) {
    post(boot.urls.draft, { open: true }, false).then(function (r) {
      if (r.status === 409) { window.location.reload(); }
      else if (r.status === 404) { linkDead(r.data.error); }
      else if (dirty) { save(false); }
    }).catch(function () { /* offline: the form still works, saving is retried later */ });
  }
})();
