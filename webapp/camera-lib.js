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
(function () {
    const BAD_LENS = /ultra|wide|0[.,]5|macro|depth|tele|zoom|عریض|واید|ماکرو|تله|عمق/i;
    const BACK = /back|rear|environment|پشت/i;
    const MAIN_IOS = /^(back camera|دوربین پشت(ی)?)$/i;
    // وضوحِ بالا (4K)؛ اگر دوربین پشتیبانی نکند مرورگر خودش نزدیک‌ترین را انتخاب می‌کند.
    // نسبتِ ۱۶:۹ عمداً انتخاب شده چون بیشترِ گوشی‌ها بالاترین وضوحِ ویدیو را در همین نسبت می‌دهند
    // (در آیفون که ImageCapture ندارد، همین فریمِ ویدیو عکسِ نهایی است).
    const QUALITY = { width: { ideal: 3840 }, height: { ideal: 2160 }, frameRate: { ideal: 30 } };

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

    window.CameraLib = { openMain, still, lensScore };
})();
