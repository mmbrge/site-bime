// فایل: iran-time.js
// ساعت و تاریخِ ایران بر اساسِ ساعتِ سرور (نه ساعتِ سیستمِ کاربر). اختلافِ ساعتِ مرورگر با سرور یک بار
// اندازه گرفته می‌شود (api/server_time.php، با کم کردنِ زمانِ رفت‌وبرگشت) و هر ۱۰ دقیقه دوباره.
// ایران از ۱۴۰۱ ساعتِ تابستانی ندارد: همیشه UTC+03:30.
//   IrTime.now()          میلی‌ثانیه‌ی دقیقِ اکنون (ساعتِ سرور)
//   IrTime.parts(ms)      {jy, jm, jd, H, M, S, wd, month}  (به وقتِ ایران)
//   IrTime.jdate(ms)      '1405/07/09' (ارقامِ لاتین)      IrTime.hm(ms) => '14:05'
//   IrTime.mountClock(el) ساعتِ زنده‌ی «چهارشنبه ۱۴۰۵/۰۷/۰۹ - ۱۴:۰۵:۲۳»
(function () {
    'use strict';
    if (window.IrTime) return;
    const IR = 12600000;
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const WD = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
    const fa = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const p2 = n => String(n).padStart(2, '0');
    const boot = window.__SRV;   // {s: میلی‌ثانیه‌ی سرور هنگامِ ساختِ صفحه, c: ساعتِ مرورگر همان لحظه}
    let offset = boot && boot.s ? boot.s - boot.c : 0;

    function g2j(gy, gm, gd) {
        const gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        const gy2 = gm > 2 ? gy + 1 : gy;
        let days = 355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + gdm[gm - 1];
        let jy = -1595 + 33 * Math.floor(days / 12053);
        days %= 12053;
        jy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        const jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
        const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
        return [jy, jm, jd];
    }
    const now = () => Date.now() + offset;
    function parts(ms) {
        const d = new Date((ms === undefined || ms === null ? now() : +ms) + IR);
        const [jy, jm, jd] = g2j(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate());
        return {jy, jm, jd, H: d.getUTCHours(), M: d.getUTCMinutes(), S: d.getUTCSeconds(), wd: WD[d.getUTCDay()], month: MONTHS[jm - 1]};
    }
    const jdate = ms => { const p = parts(ms); return p.jy + '/' + p2(p.jm) + '/' + p2(p.jd); };
    const hm = ms => { const p = parts(ms); return p2(p.H) + ':' + p2(p.M); };

    const src = (document.currentScript && document.currentScript.src) || '';
    const api = src ? src.replace(/iran-time\.js(\?.*)?$/, 'api/server_time.php') : '/api/server_time.php';
    let best = Infinity;
    async function sync() {
        try {
            const t0 = Date.now();
            const r = await fetch(api + (api.indexOf('?') < 0 ? '?' : '&') + '_=' + t0, {cache: 'no-store', credentials: 'same-origin'});
            const j = await r.json();
            const t1 = Date.now(), rtt = t1 - t0;
            // اندازه‌گیریِ دقیق‌تر (رفت‌وبرگشتِ کوتاه‌تر) جایگزین می‌شود؛ بعد از ۱۰ دقیقه هر اندازه‌ای پذیرفته است
            if (j && j.ms && (rtt <= best * 1.5 || rtt < 400)) { offset = j.ms + rtt / 2 - t1; best = Math.min(best, rtt); }
        } catch (e) { /* بدونِ اتصال همان اختلافِ قبلی می‌ماند */ }
    }
    sync();
    setInterval(() => { best = Infinity; sync(); }, 600000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(); });

    function mountClock(el, o) {
        if (!el) return;
        o = o || {};
        const draw = () => {
            const p = parts();
            const date = fa(p.jy + '/' + p2(p.jm) + '/' + p2(p.jd)), time = fa(p2(p.H) + ':' + p2(p.M) + ':' + p2(p.S));
            if (o.render) o.render(el, p, date, time);
            else el.textContent = (o.weekday === false ? '' : p.wd + ' ') + date + ' - ' + time;
        };
        const loop = () => { draw(); setTimeout(loop, 1000 - (now() % 1000) + 5); };
        loop();
    }

    window.IrTime = {now, parts, jdate, hm, g2j, fa, sync, mountClock, MONTHS, WD, offset: () => offset};
})();
