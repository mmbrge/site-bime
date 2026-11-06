// ==========================================================
// بروزرسانی خودکار افزونه: دانلود ZIP از سرور، نوشتن فایل‌ها در پوشه افزونه و Reload
// (دسترسی به پوشه افزونه یک‌بار توسط کاربر داده می‌شود و در مرورگر ذخیره می‌ماند)
// ==========================================================
const $ = (id) => document.getElementById(id);
const DB_NAME = "mamoot_fs";
const STORE = "handles";
const KEEP_EXISTING = []; // (نشانیِ پنل داخلِ خودِ بسته است؛ فایلِ تنظیماتِ جدا ندارد)
const BB_SERVER = "__BB_SERVER__".replace(/\/+$/, "");

function idbOpen() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(STORE);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
async function idbGet(key) {
  const db = await idbOpen();
  return new Promise((resolve, reject) => {
    const r = db.transaction(STORE).objectStore(STORE).get(key);
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
}
async function idbSet(key, val) {
  const db = await idbOpen();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, "readwrite");
    if (val === null) tx.objectStore(STORE).delete(key); else tx.objectStore(STORE).put(val, key);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

function setStep(text, pct) {
  $("step").textContent = text;
  if (typeof pct === "number") $("fill").style.width = pct + "%";
}
function showError(msg) {
  $("err").textContent = msg;
  $("err").style.display = "block";
  $("startBtn").disabled = false;
  $("startBtn").textContent = "تلاش دوباره";
}
function cmpVer(a, b) {
  const p = String(a).split(".").map((n) => parseInt(n, 10) || 0);
  const q = String(b).split(".").map((n) => parseInt(n, 10) || 0);
  for (let i = 0; i < Math.max(p.length, q.length); i++) {
    if ((p[i] || 0) !== (q[i] || 0)) return (p[i] || 0) > (q[i] || 0) ? 1 : -1;
  }
  return 0;
}

// گرفتن دسترسی نوشتن روی پوشه افزونه (فقط با کلیک کاربر)
async function getExtensionDir() {
  const current = chrome.runtime.getManifest();
  let handle = await idbGet("extDir");
  if (handle) {
    let perm = await handle.queryPermission({ mode: "readwrite" });
    if (perm !== "granted") perm = await handle.requestPermission({ mode: "readwrite" });
    if (perm !== "granted") handle = null;
  }
  if (!handle) {
    setStep("لطفاً پوشه نصب‌شده افزونه را انتخاب کنید...", 5);
    handle = await window.showDirectoryPicker({ mode: "readwrite" });
  }
  // اطمینان از اینکه همین پوشه افزونه است
  let manifestOnDisk;
  try {
    const mf = await handle.getFileHandle("manifest.json");
    manifestOnDisk = JSON.parse(await (await mf.getFile()).text());
  } catch (e) {
    await idbSet("extDir", null);
    throw new Error("در پوشه انتخاب‌شده فایل manifest.json پیدا نشد. پوشه‌ای را انتخاب کنید که manifest.json افزونه داخل آن است.");
  }
  if (manifestOnDisk.name !== current.name || manifestOnDisk.version !== current.version) {
    await idbSet("extDir", null);
    throw new Error("پوشه انتخاب‌شده، پوشه همین افزونه (نسخه در حال اجرا) نیست. پوشه‌ی درست را انتخاب کنید.");
  }
  await idbSet("extDir", handle);
  return handle;
}

async function downloadZip(url) {
  const res = await fetch(url, { cache: "no-store" });
  if (!res.ok) throw new Error("دانلود فایل بروزرسانی ناموفق بود (کد " + res.status + ").");
  const total = parseInt(res.headers.get("content-length"), 10) || 0;
  const reader = res.body.getReader();
  const chunks = [];
  let got = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    chunks.push(value);
    got += value.length;
    setStep("در حال دانلود بسته بروزرسانی...", 15 + (total ? Math.round((got / total) * 40) : 20));
  }
  return new Blob(chunks);
}

async function writeFile(rootHandle, relPath, data) {
  const parts = relPath.split("/").filter(Boolean);
  let dir = rootHandle;
  for (let i = 0; i < parts.length - 1; i++) {
    dir = await dir.getDirectoryHandle(parts[i], { create: true });
  }
  const fh = await dir.getFileHandle(parts[parts.length - 1], { create: true });
  const w = await fh.createWritable();
  await w.write(data);
  await w.close();
}

async function fileExists(rootHandle, relPath) {
  try {
    const parts = relPath.split("/").filter(Boolean);
    let dir = rootHandle;
    for (let i = 0; i < parts.length - 1; i++) dir = await dir.getDirectoryHandle(parts[i]);
    await dir.getFileHandle(parts[parts.length - 1]);
    return true;
  } catch (e) {
    return false;
  }
}

async function runUpdate() {
  $("err").style.display = "none";
  $("startBtn").disabled = true;
  const store = await chrome.storage.local.get(["latestUpdate", "token"]);
  const upd = store.latestUpdate;
  const currentVer = chrome.runtime.getManifest().version;
  if (!upd || !upd.version) return showError("اطلاعات نسخه جدید در دسترس نیست؛ ابتدا در افزونه «بررسی بروزرسانی» را بزنید.");
  if (!store.token) return showError("اول در افزونه وارد شوید (بسته‌ی بروزرسانی فقط برای کاربرانِ مجاز دانلود می‌شود).");

  try {
    const dir = await getExtensionDir();

    const blob = await downloadZip(`${BB_SERVER}/api/ext_api.php?action=package&token=${encodeURIComponent(store.token)}`);

    setStep("در حال بازکردن بسته...", 58);
    const zip = await JSZip.loadAsync(blob);
    const names = Object.keys(zip.files).filter((n) => !zip.files[n].dir);

    // تشخیص پوشه ریشه داخل zip (اگر manifest.json داخل یک پوشه باشد)
    const mfName = names.find((n) => /(^|\/)manifest\.json$/.test(n) && n.split("/").length <= 2);
    if (!mfName) throw new Error("فایل manifest.json داخل بسته بروزرسانی پیدا نشد.");
    const prefix = mfName.slice(0, mfName.length - "manifest.json".length);
    const newManifest = JSON.parse(await zip.files[mfName].async("string"));
    // شناسه ثابت افزونه (key) باید همان باشد، وگرنه کروم افزونه را «جدید و جدا» می‌شمارد
    const curKey = chrome.runtime.getManifest().key;
    if (curKey && newManifest.key !== curKey) {
      throw new Error("کلید (key) داخل manifest.json بسته جدید با نسخه نصب‌شده یکی نیست؛ بسته را از پوشه صحیح افزونه بسازید. بروزرسانی لغو شد.");
    }
    if (cmpVer(newManifest.version, currentVer) <= 0) {
      throw new Error("نسخه داخل بسته (" + newManifest.version + ") جدیدتر از نسخه فعلی (" + currentVer + ") نیست.");
    }

    const files = names.filter((n) => n.startsWith(prefix)).map((n) => ({ zipName: n, rel: n.slice(prefix.length) }))
      .filter((f) => f.rel && f.rel !== "manifest.json");

    let i = 0;
    for (const f of files) {
      i++;
      setStep(`در حال نصب فایل‌ها (${i} از ${files.length + 1})...`, 60 + Math.round((i / (files.length + 1)) * 35));
      if (KEEP_EXISTING.includes(f.rel) && (await fileExists(dir, f.rel))) continue;
      await writeFile(dir, f.rel, await zip.files[f.zipName].async("uint8array"));
    }
    // manifest آخر نوشته می‌شود تا در صورت خطا در میانه کار، نسخه ناقص اعلام نشود
    await writeFile(dir, "manifest.json", await zip.files[mfName].async("uint8array"));

    setStep("اتمام نصب. در حال راه‌اندازی مجدد افزونه...", 100);
    $("workView").style.display = "none";
    $("doneInfo").textContent = `نسخه ${currentVer} ← ${newManifest.version} | افزونه چند ثانیه دیگر خودکار اجرا می‌شود. صفحه پرداخت باز را یک‌بار رفرش کنید.`;
    $("doneView").classList.add("show");
    setTimeout(() => chrome.runtime.reload(), 2200);
  } catch (e) {
    if (e && e.name === "AbortError") return showError("انتخاب پوشه لغو شد.");
    showError(e && e.message ? e.message : String(e));
  }
}

(async function init() {
  const store = await chrome.storage.local.get(["latestUpdate"]);
  const upd = store.latestUpdate;
  const cur = chrome.runtime.getManifest().version;
  if (!upd || !upd.version) {
    $("verInfo").textContent = "نسخه جدیدی در سرور پیدا نشد.";
    $("startBtn").disabled = true;
  } else if (cmpVer(upd.version, cur) <= 0) {
    $("verInfo").textContent = "افزونه شما بروز است (نسخه " + cur + ").";
    $("startBtn").disabled = true;
  } else {
    $("verInfo").textContent = `نسخه جدید ${upd.version} آماده نصب است (نسخه فعلی: ${cur}). ${upd.changelog || ""}`;
  }
  $("startBtn").addEventListener("click", runUpdate);

  // تنظیم اولیه (یک‌بار بعد از نصب): دسترسی به پوشه افزونه تا بروزرسانی‌های بعدی بدون هیچ سؤالی انجام شود
  $("setupBtn").addEventListener("click", async () => {
    $("setupMsg").style.color = "#cbd5e1";
    try {
      await getExtensionDir();
      $("setupMsg").style.color = "#34d399";
      $("setupMsg").textContent = "✅ اتصال برقرار شد. از این به بعد بروزرسانی‌ها با یک کلیک (یا خودکار) انجام می‌شود.";
    } catch (e) {
      $("setupMsg").style.color = "#f87171";
      $("setupMsg").textContent = e && e.name === "AbortError" ? "انتخاب پوشه لغو شد." : (e && e.message ? e.message : String(e));
    }
  });

  // اگر قبلاً دسترسی داده شده و نسخه جدید هست، بدون هیچ کلیکی شروع می‌شود
  try {
    const h = await idbGet("extDir");
    const newer = upd && upd.version && cmpVer(upd.version, cur) > 0;
    if (h && newer && (await h.queryPermission({ mode: "readwrite" })) === "granted") {
      $("startBtn").disabled = true;
      runUpdate();
    }
  } catch (e) {}
})();
