// فایل: webapp/camera-lib.js
// دوربینِ مشترکِ صفحه‌های بازدید (بازدید سلامت: bazdid، بازدید مدارک: visit/index_docs).
//
// دو مشکلی که حل می‌کند:
//  ۱) لنزِ اشتباه: facingMode:'environment' روی خیلی از گوشی‌ها لنزِ واید (۰.۵x) را باز
//     می‌کند که تصویرِ کشیده و کم‌جزئیات می‌دهد. این‌جا دوربین‌ها بر اساس نام رتبه‌بندی
//     می‌شوند (لنزِ اصلی: «Back Camera» در آیفون، کم‌شماره‌ترین «camera2 N, facing back»
//     در اندروید؛ واید/تله/ماکرو/عمق آخرِ صف) و *یکی‌یکی* امتحان می‌شوند - هیچ‌وقت دو
//     دوربین هم‌زمان باز نمی‌شود (خیلی از گوشی‌های اندرویدی این را پشتیبانی نمی‌کنند و
//     روشِ قبلی به همین دلیل بی‌صدا شکست می‌خورد).
//  ۲) کیفیتِ پایینِ عکس: به‌جای برداشتنِ یک فریم از ویدیو (که نویزگیری و وضوحِ ویدیویی
//     دارد)، هرجا مرورگر پشتیبانی کند (ImageCapture - کروم اندروید) یک عکسِ واقعی با
//     حداکثر وضوحِ سنسور و پردازشِ عکاسیِ خودِ گوشی گرفته می‌شود.
//  ۳) آیفون: سافاری (و وب‌ویوی بله روی iOS) نه ImageCapture دارد و نه انتخابِ لنزِ درست را
//     تضمین می‌کند؛ پس روی آیفون اصلاً دوربینِ داخلِ صفحه باز نمی‌شود و مستقیم «دوربینِ خودِ
//     آیفون» (اپِ Camera سیستم) باز می‌شود - با همان کیفیت و فوکوس و پردازشِ عکاسیِ خودِ گوشی.
//     isIOS() / nativeCapture() / shrink()
(function () {
    const BAD_LENS = /ultra|wide|0[.,]5|macro|depth|tele|zoom|عریض|واید|ماکرو|تله|عمق/i;
    const BACK = /back|rear|environment|پشت/i;
    const MAIN_IOS = /^(back camera|دوربین پشت(ی)?)$/i;
    // وضوحِ بالا (4K)؛ اگر دوربین پشتیبانی نکند مرورگر خودش نزدیک‌ترین را انتخاب می‌کند.
    // نسبتِ ۱۶:۹ عمداً انتخاب شده چون بیشترِ گوشی‌ها بالاترین وضوحِ ویدیو را در همین نسبت می‌دهند
    // (در آیفون که ImageCapture ندارد، همین فریمِ ویدیو عکسِ نهایی است).
    const QUALITY = { width: { ideal: 3840 }, height: { ideal: 2160 }, frameRate: { ideal: 30 } };
    // سقفِ حجمِ هر عکسِ ارسالی. پیش‌فرضِ PHP (و خیلی از هاست‌ها) upload_max_filesize=2M است؛
    // فایلِ بزرگ‌تر اصلاً به سرور نمی‌رسد، پس همه‌ی عکس‌ها زیرِ این سقف نگه داشته می‌شوند.
    const UPLOAD_MAX = Math.round(1.9 * 1024 * 1024);

    function lensScore(label) {
        const l = (label || '').trim();
        let score = 0;
        if (MAIN_IOS.test(l)) score -= 100;                  // آیفون: «Back Camera» همان لنز اصلی است
        if (BAD_LENS.test(l) && !/dual wide|triple/i.test(l)) score += 100;
        const m = l.match(/camera\s*2?\s*(\d+)/i);            // اندروید: «camera2 0, facing back»
        score += m ? Number(m[1]) : 50;
        return score;
    }

    async function getUM(video) {
        return navigator.mediaDevices.getUserMedia({ video, audio: false });
    }

    async function tuneTrack(stream) {
        try {
            const track = stream.getVideoTracks()[0];
            const caps = track.getCapabilities ? track.getCapabilities() : null;
            if (caps && caps.zoom) {
                const target = Math.min(Math.max(1, caps.zoom.min), caps.zoom.max);
                for (const c of [{ advanced: [{ zoom: target }] }, { zoom: target }]) {
                    try { await track.applyConstraints(c); break; } catch (e) {}
                }
            }
            if (caps && caps.focusMode && caps.focusMode.includes('continuous')) {
                try { await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }); } catch (e) {}
            }
        } catch (e) {}
    }

    // باز کردنِ لنزِ اصلیِ دوربینِ پشت با بالاترین وضوح
    async function openMain() {
        // مجوز یک‌بار گرفته می‌شود تا نامِ دوربین‌ها خوانا شود، و بلافاصله بسته می‌شود
        let probe = null;
        try { probe = await getUM({ facingMode: 'environment' }); } catch (e) {}
        let cams = [];
        try { cams = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput'); } catch (e) {}
        if (probe) probe.getTracks().forEach(t => t.stop());

        let back = cams.filter(c => BACK.test(c.label));
        if (!back.length) back = cams.filter(c => !/front|user|selfie|جلو/i.test(c.label));
        back.sort((a, b) => lensScore(a.label) - lensScore(b.label));

        let stream = null;
        for (const cam of back) {                        // یکی‌یکی، نه هم‌زمان
            try { stream = await getUM(Object.assign({ deviceId: { exact: cam.deviceId } }, QUALITY)); break; }
            catch (e) { stream = null; }
        }
        if (!stream) stream = await getUM(Object.assign({ facingMode: { ideal: 'environment' } }, QUALITY));
        await tuneTrack(stream);
        return stream;
    }

    // عکسِ واقعی با حداکثر وضوح (اگر مرورگر پشتیبانی کند)؛ وگرنه null => همان فریمِ ویدیو استفاده شود.
    // حداکثر ۴ ثانیه منتظر می‌ماند تا اگر گوشی کند بود، کاربر معطل نشود.
    async function still(stream) {
        try {
            if (!stream || typeof ImageCapture === 'undefined') return null;
            const track = stream.getVideoTracks()[0];
            if (!track || track.readyState !== 'live') return null;
            const ic = new ImageCapture(track);
            let settings = {};
            try {
                const pc = await ic.getPhotoCapabilities();
                if (pc && pc.imageWidth && pc.imageWidth.max) settings = { imageWidth: pc.imageWidth.max, imageHeight: pc.imageHeight.max };
            } catch (e) {}
            const timeout = new Promise(res => setTimeout(() => res(null), 4000));
            const blob = await Promise.race([ic.takePhoto(settings).catch(() => ic.takePhoto().catch(() => null)), timeout]);
            if (!blob || !blob.size) return null;
            return await createImageBitmap(blob);           // جهتِ EXIF خودکار اعمال می‌شود
        } catch (e) { return null; }
    }

    // آیفون / آیپد (آیپدِ جدید خودش را «Mac» معرفی می‌کند؛ با صفحه‌ی لمسی تشخیص داده می‌شود)
    function isIOS() {
        const ua = navigator.userAgent || '';
        return /iPhone|iPad|iPod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    // باز کردنِ مستقیمِ دوربینِ خودِ گوشی (input[capture]). iOS فقط وقتی اجازه می‌دهد که این
    // تابع *بی‌درنگ و هم‌زمان* با لمسِ کاربر صدا زده شود (نه بعد از await).
    function nativeCapture(onFile) {
        let input = document.getElementById('cl-native-input');
        if (!input) {
            input = document.createElement('input');
            input.type = 'file';
            input.id = 'cl-native-input';
            input.accept = 'image/*';
            input.setAttribute('capture', 'environment');
            input.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;';
            document.body.appendChild(input);
        }
        input.onchange = () => {
            const f = input.files && input.files[0];
            input.value = '';
            if (f) onFile(f);
        };
        input.click();
    }

    // عکسِ دوربینِ آیفون ۱۲ مگاپیکسل و چند مگابایت است؛ پیش از ارسال به اندازه‌ی مناسب کوچک
    // می‌شود (ضلعِ بزرگ حداکثر maxSide پیکسل، حجم حداکثر maxBytes). جهتِ EXIF را خودِ سافاری
    // هنگامِ کشیدنِ <img> اعمال می‌کند، پس عکس کج ذخیره نمی‌شود. اگر هر مرحله‌ای شکست بخورد،
    // همان فایلِ اصلی برگردانده می‌شود.
    async function shrink(file, maxSide, maxBytes) {
        maxSide = maxSide || 3200;
        maxBytes = maxBytes || UPLOAD_MAX;
        if (!file || !/^image\//i.test(file.type || 'image/')) return file;
        let url = null;
        try {
            url = URL.createObjectURL(file);
            const img = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = url; });
            const w0 = img.naturalWidth, h0 = img.naturalHeight;
            if (!w0 || !h0) return file;
            const sc = Math.max(w0, h0) > maxSide ? maxSide / Math.max(w0, h0) : 1;
            if (sc === 1 && file.size <= maxBytes && /jpe?g/i.test(file.type)) return file;
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(w0 * sc); canvas.height = Math.round(h0 * sc);
            const ctx = canvas.getContext('2d');
            ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            const enc = q => new Promise(res => canvas.toBlob(b => res(b), 'image/jpeg', q));
            let blob = await enc(0.92);
            if (blob && blob.size > maxBytes) {
                const q2 = Math.max(0.62, Math.min(0.9, 0.92 * Math.sqrt(maxBytes / blob.size)));
                blob = (await enc(q2)) || blob;
            }
            // هنوز بزرگ است (مثلاً عکسِ پرجزئیات): ابعاد کمی کوچک‌تر می‌شود تا حتماً زیرِ سقف برود
            for (let i = 0; i < 3 && blob && blob.size > maxBytes; i++) {
                const f = Math.max(0.5, Math.sqrt(maxBytes / blob.size) * 0.92);
                const c2 = document.createElement('canvas');
                c2.width = Math.round(canvas.width * f); c2.height = Math.round(canvas.height * f);
                const x2 = c2.getContext('2d');
                x2.imageSmoothingEnabled = true; x2.imageSmoothingQuality = 'high';
                x2.drawImage(canvas, 0, 0, c2.width, c2.height);
                canvas.width = c2.width; canvas.height = c2.height;
                ctx.drawImage(c2, 0, 0);
                blob = (await enc(0.85)) || blob;
            }
            return blob || file;
        } catch (e) {
            return file;
        } finally {
            if (url) URL.revokeObjectURL(url);
        }
    }

    // اگر فایل (عکس) از سقفِ ارسال بزرگ‌تر است کوچکش کن؛ PDF و فایل‌های کوچک دست نمی‌خورند
    async function fitForUpload(file, maxSide) {
        if (!file || file.size <= UPLOAD_MAX || !/^image\//i.test(file.type || '')) return file;
        return shrink(file, maxSide || 3200, UPLOAD_MAX);
    }

    window.CameraLib = { openMain, still, lensScore, isIOS, nativeCapture, shrink, fitForUpload, UPLOAD_MAX };
})();
