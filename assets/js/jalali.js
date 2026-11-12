/*!
 * Fava assessment – Jalali (Persian) calendar helpers and a small date picker.
 * No dependencies. The conversion follows the algorithm of jalaali-js (MIT),
 * the same one implemented on the server in app/Core/Jalali.php.
 *
 * Usage: <input data-jdate> gets a picker automatically; value format is YYYY/MM/DD.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) { module.exports = factory(); }
  else { root.FavaJalali = factory(); }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
  var WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج']; // Saturday first
  var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';
  var BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

  function div(a, b) { return ~~(a / b); }
  function mod(a, b) { return a - ~~(a / b) * b; }

  function toFa(s) { return String(s).replace(/\d/g, function (d) { return FA.charAt(+d); }); }
  function toLatin(s) {
    return String(s).replace(/[۰-۹٠-٩]/g, function (c) {
      var i = FA.indexOf(c);
      return String(i >= 0 ? i : AR.indexOf(c));
    });
  }

  function jalCal(jy) {
    var bl = BREAKS.length, gy = jy + 621, leapJ = -14, jp = BREAKS[0], jm, jump = 0, leap, leapG, march, n, i;
    if (jy < jp || jy >= BREAKS[bl - 1]) { throw new Error('Invalid Jalali year ' + jy); }
    for (i = 1; i < bl; i += 1) {
      jm = BREAKS[i];
      jump = jm - jp;
      if (jy < jm) { break; }
      leapJ = leapJ + div(jump, 33) * 8 + div(mod(jump, 33), 4);
      jp = jm;
    }
    n = jy - jp;
    leapJ = leapJ + div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
    if (mod(jump, 33) === 4 && jump - n === 4) { leapJ += 1; }
    leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
    march = 20 + leapJ - leapG;
    if (jump - n < 6) { n = n - jump + div(jump + 4, 33) * 33; }
    leap = mod(mod(n + 1, 33) - 1, 4);
    if (leap === -1) { leap = 4; }
    return { leap: leap, gy: gy, march: march };
  }

  function g2d(gy, gm, gd) {
    var d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
    return d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
  }

  function d2g(jdn) {
    var j = 4 * jdn + 139361631, i, gd, gm, gy;
    j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
    i = div(mod(j, 1461), 4) * 5 + 308;
    gd = div(mod(i, 153), 5) + 1;
    gm = mod(div(i, 153), 12) + 1;
    gy = div(j, 1461) - 100100 + div(8 - gm, 6);
    return [gy, gm, gd];
  }

  function j2d(jy, jm, jd) {
    var r = jalCal(jy);
    return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
  }

  function d2j(jdn) {
    var gy = d2g(jdn)[0], jy = gy - 621, r = jalCal(jy), k = jdn - g2d(gy, 3, r.march), jm, jd;
    if (k >= 0) {
      if (k <= 185) { return [jy, 1 + div(k, 31), mod(k, 31) + 1]; }
      k -= 186;
    } else {
      jy -= 1;
      k += 179;
      if (r.leap === 1) { k += 1; }
    }
    jm = 7 + div(k, 30);
    jd = mod(k, 30) + 1;
    return [jy, jm, jd];
  }

  function toJalali(gy, gm, gd) { return d2j(g2d(gy, gm, gd)); }
  function toGregorian(jy, jm, jd) { return d2g(j2d(jy, jm, jd)); }
  function isLeap(jy) { return jalCal(jy).leap === 0; }
  function monthLength(jy, jm) { return jm <= 6 ? 31 : (jm <= 11 ? 30 : (isLeap(jy) ? 30 : 29)); }
  /** 0 = Saturday … 6 = Friday */
  function weekday(jy, jm, jd) { return mod(j2d(jy, jm, jd) + 2, 7); }

  /** Today's Jalali date in Iran (UTC+03:30), whatever the clock zone of the device. */
  function today() {
    var t = new Date(Date.now() + 3.5 * 3600 * 1000);
    return toJalali(t.getUTCFullYear(), t.getUTCMonth() + 1, t.getUTCDate());
  }

  function valid(jy, jm, jd) {
    return jy >= 1300 && jy <= 1500 && jm >= 1 && jm <= 12 && jd >= 1 && jd <= monthLength(jy, jm);
  }

  /** "1405/8/20", "۱۴۰۵-۰۸-۲۰" … → [1405, 8, 20] or null */
  function parse(value) {
    var m = /^\s*(\d{4})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*$/.exec(toLatin(value || ''));
    if (!m) { return null; }
    var y = +m[1], mo = +m[2], d = +m[3];
    return valid(y, mo, d) ? [y, mo, d] : null;
  }

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function format(jy, jm, jd, persianDigits) {
    var s = jy + '/' + pad(jm) + '/' + pad(jd);
    return persianDigits === false ? s : toFa(s);
  }

  // ---------------------------------------------------------------- date picker

  var open = null; // {input, el, y, m}

  function close() {
    if (!open) { return; }
    if (open.el.parentNode) { open.el.parentNode.removeChild(open.el); }
    open.input.removeAttribute('aria-expanded');
    open = null;
  }

  function place() {
    if (!open) { return; }
    var r = open.input.getBoundingClientRect(), el = open.el;
    var w = el.offsetWidth, h = el.offsetHeight, vw = document.documentElement.clientWidth, vh = window.innerHeight;
    var left = r.right - w;                       // align with the right edge (RTL)
    if (left < 8) { left = Math.min(r.left, vw - w - 8); }
    if (left < 8) { left = 8; }
    var top = r.bottom + 6;
    if (top + h > vh - 8 && r.top - h - 6 > 8) { top = r.top - h - 6; } // not enough room below → above
    el.style.left = (left + window.pageXOffset) + 'px';
    el.style.top = (top + window.pageYOffset) + 'px';
  }

  function chevron(dir) {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="' +
      (dir === 'right' ? 'M9 5l7 7-7 7' : 'M15 19l-7-7 7-7') + '"/></svg>';
  }

  function render() {
    if (!open) { return; }
    var y = open.y, m = open.m, sel = parse(open.input.value), now = today();
    var html = '<div class="jdp-head">' +
      '<button type="button" class="jdp-nav" data-nav="-1" aria-label="ماه قبل">' + chevron('right') + '</button>' +
      '<span><select data-month aria-label="ماه" style="border:0;background:transparent;font:inherit;font-weight:700;color:inherit;cursor:pointer">';
    for (var i = 1; i <= 12; i++) { html += '<option value="' + i + '"' + (i === m ? ' selected' : '') + '>' + MONTHS[i - 1] + '</option>'; }
    html += '</select> <select data-year aria-label="سال" style="border:0;background:transparent;font:inherit;font-weight:700;color:inherit;cursor:pointer">';
    var from = Math.min(y, now[0]) - 10, to = Math.max(y, now[0]) + 10;
    for (var yy = from; yy <= to; yy++) { html += '<option value="' + yy + '"' + (yy === y ? ' selected' : '') + '>' + toFa(yy) + '</option>'; }
    html += '</select></span>' +
      '<button type="button" class="jdp-nav" data-nav="1" aria-label="ماه بعد">' + chevron('left') + '</button></div><div class="jdp-grid">';
    for (var w = 0; w < 7; w++) { html += '<span class="jdp-wd">' + WEEKDAYS[w] + '</span>'; }
    var first = weekday(y, m, 1), len = monthLength(y, m);
    for (var b = 0; b < first; b++) { html += '<span></span>'; }
    for (var d = 1; d <= len; d++) {
      var cls = 'jdp-day';
      if ((first + d - 1) % 7 === 6) { cls += ' fri'; }
      if (now[0] === y && now[1] === m && now[2] === d) { cls += ' today'; }
      if (sel && sel[0] === y && sel[1] === m && sel[2] === d) { cls += ' sel'; }
      html += '<button type="button" class="' + cls + '" data-day="' + d + '">' + toFa(d) + '</button>';
    }
    html += '</div><div class="jdp-foot"><button type="button" data-today>امروز</button><button type="button" data-clear>پاک کردن</button></div>';
    open.el.innerHTML = html;
    place();
  }

  function setValue(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function show(input) {
    if (open && open.input === input) { return; }
    close();
    if (input.readOnly || input.disabled) { return; }
    var cur = parse(input.value) || today();
    var el = document.createElement('div');
    el.className = 'jdp';
    el.setAttribute('dir', 'rtl');
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'انتخاب تاریخ');
    document.body.appendChild(el);
    open = { input: input, el: el, y: cur[0], m: cur[1] };
    input.setAttribute('aria-expanded', 'true');

    el.addEventListener('mousedown', function (e) {
      // keep the focus in the input, except for the two <select>s which need it
      if (e.target.tagName !== 'SELECT' && e.target.tagName !== 'OPTION') { e.preventDefault(); }
    });
    el.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('button') : null;
      if (!t || !open) { return; }
      if (t.hasAttribute('data-nav')) {
        open.m += +t.getAttribute('data-nav');
        if (open.m < 1) { open.m = 12; open.y -= 1; }
        if (open.m > 12) { open.m = 1; open.y += 1; }
        render();
      } else if (t.hasAttribute('data-day')) {
        setValue(input, format(open.y, open.m, +t.getAttribute('data-day')));
        close();
      } else if (t.hasAttribute('data-today')) {
        var n = today();
        setValue(input, format(n[0], n[1], n[2]));
        close();
      } else if (t.hasAttribute('data-clear')) {
        setValue(input, '');
        close();
      }
    });
    el.addEventListener('change', function (e) {
      if (!open) { return; }
      if (e.target.hasAttribute('data-month')) { open.m = +e.target.value; }
      if (e.target.hasAttribute('data-year')) { open.y = +e.target.value; }
      render();
    });
    render();
  }

  function attach(input) {
    if (!input || input.__jdp) { return; }
    input.__jdp = true;
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('focus', function () { show(input); });
    input.addEventListener('click', function () { show(input); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' || e.key === 'Tab' || e.key === 'Enter') { close(); }
    });
    input.addEventListener('input', function () {
      var p = parse(input.value);
      if (open && open.input === input && p) { open.y = p[0]; open.m = p[1]; render(); }
    });
    // tidy up what was typed: 1405/8/5 → ۱۴۰۵/۰۸/۰۵
    input.addEventListener('blur', function () {
      var p = parse(input.value);
      if (p) {
        var v = format(p[0], p[1], p[2]);
        if (v !== input.value) { setValue(input, v); }
      }
    });
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('mousedown', function (e) {
      if (open && e.target !== open.input && !open.el.contains(e.target)) { close(); }
    });
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    var init = function () {
      var list = document.querySelectorAll('input[data-jdate]');
      for (var i = 0; i < list.length; i++) { attach(list[i]); }
    };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
  }

  return {
    toJalali: toJalali, toGregorian: toGregorian, isLeap: isLeap, monthLength: monthLength, weekday: weekday,
    today: today, parse: parse, format: format, toFa: toFa, toLatin: toLatin, attach: attach, close: close, MONTHS: MONTHS
  };
});
