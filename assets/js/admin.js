/*!
 * Fava assessment – behaviour of the admin panel. Plain JavaScript, no dependencies.
 * Everything is optional sugar: every form still works when this file fails to load.
 */
(function () {
  'use strict';

  var FA = '۰۱۲۳۴۵۶۷۸۹';
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function toFa(s) { return String(s).replace(/\d/g, function (d) { return FA.charAt(+d); }); }
  function toLatin(s) {
    return String(s).replace(/[۰-۹]/g, function (c) { return String(FA.indexOf(c)); })
      .replace(/[٠-٩]/g, function (c) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(c)); });
  }
  /**
   * Same rule as Fa::number() on the server: the dot (or «٫», or «۲/۵») is the decimal
   * mark, commas may only group thousands, anything else is not a number.
   */
  function num(value) {
    var s = toLatin(String(value === null || value === undefined ? '' : value)).replace(/[\s\u00A0\u200C\u200E\u200F]/g, '')
      .replace(/٫/g, '.').replace(/[٬،]/g, ',');
    if (/^-?\d+\/\d+$/.test(s)) { s = s.replace('/', '.'); }
    if (s.indexOf(',') !== -1) {
      if (!/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(s)) { return null; }
      s = s.replace(/,/g, '');
    }
    return /^-?(\d+\.?\d*|\.\d+)$/.test(s) ? parseFloat(s) : null;
  }
  function trimNum(n, decimals) {
    return String(parseFloat(n.toFixed(decimals === undefined ? 2 : decimals)));
  }
  function fmt(n, decimals) {
    var parts = trimNum(n, decimals).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
    return toFa(parts.join('.'));
  }
  /** amount in billion rials → “… میلیارد تومان / میلیون تومان” */
  function toman(billionRial) {
    var m = billionRial * 100;
    return m >= 1000 ? fmt(m / 1000, 3) + ' میلیارد تومان' : fmt(m, 1) + ' میلیون تومان';
  }

  // ------------------------------------------------------------------ toast
  var toastTimer = null;
  function toast(message) {
    var el = $('#toast');
    if (!el) { return; }
    el.textContent = message;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove('show'); }, 2600);
  }
  window.favaToast = toast;

  // ------------------------------------------------------------ show / hide
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-toggle]');
    if (!btn) { return; }
    var target = $(btn.getAttribute('data-toggle'));
    if (!target) { return; }
    e.preventDefault();
    // the sidebar is hidden with a class (it has responsive variants), other boxes with the attribute
    if (target.classList.contains('hidden') || target.getAttribute('data-toggle-mode') === 'class') {
      target.setAttribute('data-toggle-mode', 'class');
      target.classList.toggle('hidden');
    } else {
      target.hidden = !target.hidden;
      if (!target.hidden) {
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        var focusable = $('[data-dropzone], input:not([type="hidden"]), textarea, select', target);
        if (focusable) { focusable.focus({ preventScroll: true }); }
      }
    }
  });

  // ------------------------------------------------------- confirm dialogs
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute && form.hasAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
      return;
    }
    // guard against double submission
    if (form.method && form.method.toLowerCase() === 'post' && !form.hasAttribute('data-dropzone')) {
      var buttons = $all('button[type="submit"], button:not([type])', form);
      setTimeout(function () {
        if (e.defaultPrevented) { return; }
        buttons.forEach(function (b) { b.setAttribute('aria-disabled', 'true'); b.classList.add('pointer-events-none', 'opacity-60'); });
      }, 0);
    }
  }, true);
  // a page restored from the back/forward cache must not keep its buttons locked
  window.addEventListener('pageshow', function () {
    $all('button[aria-disabled="true"]').forEach(function (b) {
      b.removeAttribute('aria-disabled');
      b.classList.remove('pointer-events-none', 'opacity-60');
    });
    $all('form[data-dropzone]').forEach(function (form) {
      var idle = $('[data-idle]', form), busy = $('[data-busy]', form);
      if (idle && busy) { idle.hidden = false; busy.hidden = true; }
      form.removeAttribute('data-sending');
    });
  });

  // ----------------------------------------------------------- copy a link
  function copyText(text, input) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      try {
        var el = input;
        if (!el) {
          el = document.createElement('textarea');
          el.value = text;
          el.style.position = 'fixed';
          el.style.opacity = '0';
          document.body.appendChild(el);
        }
        el.focus();
        el.select();
        var ok = document.execCommand('copy');
        if (!input) { document.body.removeChild(el); }
        if (ok) { resolve(); } else { reject(new Error('copy')); }
      } catch (err) { reject(err); }
    });
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) { return; }
    var input = $(btn.getAttribute('data-copy'));
    if (!input) { return; }
    copyText(input.value, input).then(function () {
      toast('پیوند کپی شد');
    }, function () {
      input.focus();
      input.select();
      toast('پیوند انتخاب شد؛ با Ctrl+C کپی کنید');
    });
  });

  // -------------------------------------------- fill a field with a suggestion
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-fill]');
    if (!btn) { return; }
    var input = $(btn.getAttribute('data-fill'));
    if (!input || input.readOnly || input.disabled) { return; }
    input.value = btn.getAttribute('data-value') || '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.focus();
  });

  // --------------------------------------- billion rials → tomans, under a field
  $all('input[data-toman-hint]').forEach(function (input) {
    var hint = $(input.getAttribute('data-toman-hint'));
    if (!hint) { return; }
    var initial = hint.innerHTML;
    var limit = null;
    var form = input.closest('form[data-final]');
    if (form) { limit = num(form.getAttribute('data-base')); }
    function update(first) {
      var v = num(input.value);
      if (input.value.trim() === '') { hint.innerHTML = first ? initial : '&nbsp;'; hint.classList.remove('text-red-600'); return; }
      if (v === null) { hint.textContent = 'فقط عدد وارد کنید (به میلیارد ریال).'; hint.classList.add('text-red-600'); return; }
      hint.classList.remove('text-red-600');
      var text = v > 0 ? 'برابر با ' + toman(v) : 'بدون پرداخت';
      if (limit !== null && v > limit + 0.0005) { text += ' — بیشتر از مبنای محاسبه (' + fmt(limit, 3) + ' میلیارد ریال) است.'; }
      hint.textContent = text;
    }
    input.addEventListener('input', function () { update(false); });
    update(true);
  });

  // ------------------------------------------------------------- upload box
  $all('form[data-dropzone]').forEach(function (form) {
    var input = $('input[type="file"]', form);
    var idle = $('[data-idle]', form), busy = $('[data-busy]', form), names = $('[data-names]', form);
    var max = parseInt(form.getAttribute('data-max') || '0', 10);
    var depth = 0;
    if (!input) { return; }

    function send() {
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.length || form.hasAttribute('data-sending')) { return; }
      var bad = files.filter(function (f) { return !/\.docx$/i.test(f.name); });
      if (bad.length) {
        toast('فقط فایل Word با پسوند ‎.docx پذیرفته می‌شود');
        window.alert('این فایل‌ها با قالب docx نیستند و بارگذاری نمی‌شوند:\n' + bad.map(function (f) { return f.name; }).join('\n') +
          '\n\nاگر فایل شما با قالب قدیمی ‎.doc است، آن را در Word باز کنید و با «Save As» به ‎.docx تبدیل کنید.');
        input.value = '';
        return;
      }
      var big = max > 0 ? files.filter(function (f) { return f.size > max; }) : [];
      if (big.length) {
        window.alert('حجم این فایل‌ها بیشتر از حد مجاز است:\n' + big.map(function (f) { return f.name; }).join('\n'));
        input.value = '';
        return;
      }
      form.setAttribute('data-sending', '1');
      if (names) { names.textContent = files.map(function (f) { return f.name; }).join('، '); }
      if (idle && busy) { idle.hidden = true; busy.hidden = false; }
      form.submit();
    }

    form.addEventListener('click', function (e) {
      if (e.target === input || form.hasAttribute('data-sending')) { return; }
      input.click();
    });
    form.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ' ') && e.target === form) { e.preventDefault(); input.click(); }
    });
    input.addEventListener('change', send);
    form.addEventListener('dragenter', function (e) { e.preventDefault(); depth++; form.classList.add('over'); });
    form.addEventListener('dragover', function (e) { e.preventDefault(); if (e.dataTransfer) { e.dataTransfer.dropEffect = 'copy'; } });
    form.addEventListener('dragleave', function () { depth = Math.max(0, depth - 1); if (!depth) { form.classList.remove('over'); } });
    form.addEventListener('drop', function (e) {
      e.preventDefault();
      depth = 0;
      form.classList.remove('over');
      if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) { return; }
      try { input.files = e.dataTransfer.files; } catch (err) { /* very old browsers: fall back to the file dialog */ }
      if (input.files && input.files.length) { send(); } else { input.click(); }
    });
  });
  if ($('form[data-dropzone]')) {
    // a file dropped beside the box must not replace the page
    ['dragover', 'drop'].forEach(function (type) {
      window.addEventListener(type, function (e) {
        if (!e.target.closest || !e.target.closest('form[data-dropzone]')) { e.preventDefault(); }
      });
    });
  }

  // ------------------------------------------------- choosing a judge
  $all('form[data-assign]').forEach(function (form) {
    var select = $('[data-judge-select]', form), box = $('[data-new-judge]', form);
    if (!select || !box) { return; }
    var name = $('input[name="name"]', box);
    function sync() {
      var isNew = select.value === '0';
      box.hidden = !isNew;
      if (name) { name.required = isNew; if (isNew) { name.focus(); } }
    }
    select.addEventListener('change', sync);
    if (select.value === '0') { box.hidden = false; if (name) { name.required = true; } }
  });

  // ------------------------------------------------- final decision
  $all('form[data-final]').forEach(function (form) {
    var sysDecision = form.getAttribute('data-system-decision');
    var sysAmount = num(form.getAttribute('data-system-amount')) || 0;
    var amount = $('input[name="amount"]', form), note = $('textarea[name="note"]', form), hint = $('[data-note-hint]', form);
    var lastAmount = amount ? amount.value : '';
    function decision() { var r = $('input[name="decision"]:checked', form); return r ? r.value : ''; }
    function sync(fromRadio) {
      if (!amount) { return; }
      var d = decision();
      if (d === 'reject') {
        if (fromRadio && amount.value !== '0') { lastAmount = amount.value; }
        amount.value = '0';
        amount.readOnly = true;
      } else {
        if (amount.readOnly) {
          amount.readOnly = false;
          amount.value = (num(lastAmount) || 0) > 0 ? lastAmount : (sysAmount > 0 ? trimNum(sysAmount, 3) : '');
        }
      }
      if (fromRadio) { amount.dispatchEvent(new Event('input', { bubbles: true })); }
      var v = num(amount.value);
      var changed = d !== sysDecision || v === null || Math.abs(v - sysAmount) > 0.0005;
      if (note) { note.required = changed; }
      if (hint) {
        hint.textContent = changed ? '(الزامی — با پیشنهاد سامانه تفاوت دارد)' : '(اختیاری)';
        hint.classList.toggle('text-red-600', changed);
      }
    }
    $all('input[name="decision"]', form).forEach(function (r) { r.addEventListener('change', function () { sync(true); }); });
    if (amount) { amount.addEventListener('input', function () { sync(false); }); }
    sync(false);
  });

  // ------------------------------------------------- settings: scoring weights
  (function () {
    var inputs = $all('input[data-weight]'), out = $('[data-weight-sum]');
    if (!inputs.length || !out) { return; }
    function sync() {
      var sum = 0, ok = true;
      inputs.forEach(function (i) { var v = num(i.value); if (v === null) { ok = false; } else { sum += v; } });
      var good = ok && Math.abs(sum - 100) < 0.001;
      out.textContent = ok ? 'مجموع فعلی: ' + fmt(sum) + (good ? ' (درست است)' : ' — باید ۱۰۰ شود') : 'همه وزن‌ها را به عدد وارد کنید.';
      out.className = 'font-semibold ' + (good ? 'text-emerald-700' : 'text-red-600');
    }
    inputs.forEach(function (i) { i.addEventListener('input', sync); });
    sync();
  })();

  // ------------------------------------------------- settings: mail
  (function () {
    var transport = $('select[data-transport]');
    if (transport) {
      var blocks = $all('[data-when-transport]');
      var sync = function () {
        blocks.forEach(function (b) {
          b.hidden = b.getAttribute('data-when-transport').split(' ').indexOf(transport.value) === -1;
        });
      };
      transport.addEventListener('change', sync);
      sync();
    }
    var enc = $('select[data-encryption]'), port = $('#port');
    if (enc && port) {
      enc.addEventListener('change', function () {
        var std = ['', '25', '465', '587', '2525'];
        if (std.indexOf(port.value.trim()) !== -1) {
          port.value = enc.options[enc.selectedIndex].getAttribute('data-port') || port.value;
        }
      });
    }
  })();

  // ------------------------------------------------- small conveniences
  // open the <details> a link points to (e.g. «مشاهده فرم ثبت‌شده», #info after saving)
  function openHash() {
    if (!location.hash || location.hash.length < 2) { return; }
    var target;
    try { target = $(location.hash); } catch (err) { return; }
    if (!target) { return; }
    var parent = target.closest('details');
    if (parent) { parent.open = true; }                      // the target itself, or the box it sits in
    var first = target.children.length ? target.children[0] : null;
    if (first && first.tagName === 'DETAILS') { first.open = true; } // a section that is one collapsible box
    setTimeout(function () { target.scrollIntoView({ block: 'start' }); }, 30);
  }
  window.addEventListener('hashchange', openHash);
  openHash();
})();
