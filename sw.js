// فایل: sw.js
// Service Workerِ بسیار کوچک فقط برای «صفحه‌ی قطعیِ اینترنت»: اگر صفحه‌ای از سایت در حالِ قطعی باز یا تازه شود،
// به‌جای صفحه‌ی پیش‌فرضِ مرورگر، errors/offline.html (که از قبل ذخیره شده) نشان داده می‌شود.
// هیچ صفحه، API یا فایلِ دیگری کش نمی‌شود؛ همه‌چیز مثلِ قبل مستقیم از سرور می‌آید.
const CACHE = 'bime-offline-v1';
const BASE = new URL('./', self.location).href;
const OFFLINE = BASE + 'errors/offline.html';
const FONTS = ['Font/Vazir-Regular.woff2', 'Font/Vazir-Bold.woff2', 'Font/Vazir-Black.woff2'].map(f => BASE + f);

self.addEventListener('install', e => {
    e.waitUntil(caches.open(CACHE).then(c => c.addAll([OFFLINE].concat(FONTS)).catch(() => c.add(OFFLINE))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
    e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.indexOf('bime-offline-') === 0 && k !== CACHE).map(k => caches.delete(k))))
        .then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
    const req = e.request;
    if (req.method !== 'GET') return;
    let url;
    try { url = new URL(req.url); } catch (err) { return; }
    if (url.origin !== self.location.origin) return;
    // قلم‌های صفحه‌ی قطعی: اول از سرور، اگر نشد از ذخیره
    if (FONTS.indexOf(url.href) !== -1) { e.respondWith(fetch(req).catch(() => caches.match(url.href))); return; }
    if (req.mode !== 'navigate') return;
    // دانلودها و APIها دست نمی‌خورند
    if (/\/api\/|\/Archive\/|\.(pdf|zip|xlsx?|docx?|csv|jpe?g|png|webp|mp3|webm|ogg)$/i.test(url.pathname)) return;
    e.respondWith(fetch(req).catch(() => caches.match(OFFLINE).then(r => r || Response.error())));
});
