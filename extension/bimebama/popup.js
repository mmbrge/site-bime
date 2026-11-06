// ==========================================================
// پنجره‌ی «دستیار بیمه باما»: ورود، اقساطِ پاسارگاد، درگاهِ آتی، اعلان‌ها و بروزرسانی
// هیچ وابستگی‌ای به فایلِ جدا ندارد؛ اگر سرویس‌ورکر خواب یا خراب باشد، پنجره از حافظه‌ی محلی باز می‌شود و گیر نمی‌کند.
// ==========================================================
const $ = id => document.getElementById(id);
const VERSION = chrome.runtime.getManifest().version;
let S = {};

function bg(message, ms) {
  return new Promise(resolve => {
    let done = false; const fin = v => { if (!done) { done = true; resolve(v); } };
    const t = setTimeout(() => fin(null), ms || 12000);
    try { chrome.runtime.sendMessage(message, r => { clearTimeout(t); if (chrome.runtime.lastError) fin(null); else fin(r || null); }); }
    catch (e) { clearTimeout(t); fin(null); }
  });
}
function flash(el, text, err) { el.style.color = err ? "#dc2626" : "#059669"; el.textContent = text; clearTimeout(el._t); el._t = setTimeout(() => { el.textContent = ""; }, 4000); }
function cmpVer(a, b) { const p = String(a).split(".").map(n => parseInt(n, 10) || 0), q = String(b).split(".").map(n => parseInt(n, 10) || 0); for (let i = 0; i < Math.max(p.length, q.length); i++) if ((p[i] || 0) !== (q[i] || 0)) return (p[i] || 0) > (q[i] || 0) ? 1 : -1; return 0; }
function el(tag, props, kids) { const e = document.createElement(tag); Object.entries(props || {}).forEach(([k, v]) => { if (k === "text") e.textContent = v; else if (k === "style") e.style.cssText = v; else if (k === "class") e.className = v; else e[k] = v; }); (kids || []).forEach(c => e.appendChild(c)); return e; }
const show = (node, on) => node.classList.toggle("hide", !on);

async function load() {
  S = await chrome.storage.local.get(["token", "userName", "displayName", "serverConnected", "authError", "modules", "contracts", "defaultContractId", "autoMode", "autoPro", "dateBasisChoice",
    "isBlocked", "sites", "vpnConfig", "atiBlocked", "gatewayEnabled", "bridgeOnline", "tunnelConnected", "notifications", "dismissedNotifIds", "latestUpdate", "justUpdated", "activeTab", "bridgeHosts", "bridgeVersion", "connState", "connAttempt", "connError"]);
}

async function render() {
  await load();
  $("verBadge").textContent = "v" + VERSION;
  $("connDot").className = S.serverConnected ? "dot" : "dot offline";
  if (!S.token || !S.userName) {
    show($("loginView"), true); show($("mainView"), false);
    if (S.authError) flash($("loginMsg"), S.authError, true);
    return;
  }
  show($("loginView"), false); show($("mainView"), true);
  $("lblUser").textContent = S.displayName ? S.displayName + " (" + S.userName + ")" : S.userName;

  // «بروز شد» و «نسخه‌ی تازه»
  const ub = $("updatedBanner");
  if (S.justUpdated) { ub.textContent = "✅ افزونه به نسخه‌ی " + VERSION + " بروز شد. اگر صفحه‌ی پرداختِ پاسارگاد باز است یک بار رفرش کنید."; ub.classList.add("show"); chrome.storage.local.remove("justUpdated"); }
  const rb = $("updateReady"), up = S.latestUpdate;
  if (up && up.version && cmpVer(up.version, VERSION) > 0) {
    rb.replaceChildren(el("b", { text: "🎉 نسخه‌ی " + up.version + " آماده است", style: "color:#1d4ed8" }), el("div", { class: "muted", text: up.changelog || "" }), el("button", { class: "btn pri mini", text: "بروزرسانی", onclick: runUpdate }));
    rb.classList.add("show");
  } else rb.classList.remove("show");

  // اعلان‌ها
  const nb = $("notifs"); nb.replaceChildren();
  (S.notifications || []).forEach(n => {
    const b = el("button", { class: "btn mini", text: "متوجه شدم" });
    b.onclick = async () => {
      const d = Array.isArray(S.dismissedNotifIds) ? S.dismissedNotifIds : [];
      if (!d.includes(n.id)) d.push(n.id);
      await chrome.storage.local.set({ dismissedNotifIds: d, notifications: (S.notifications || []).filter(x => x.id !== n.id) });
      render();
    };
    nb.appendChild(el("div", { class: "notif" }, [el("b", { text: "🔔 " + (n.title || "اعلانِ پنل") }), el("div", { text: n.message || "" }), b]));
  });

  // بخش‌ها
  const mods = S.modules || {};
  const avail = ["inst", "ati"].filter(m => mods[m]);
  show($("noModule"), !avail.length);
  show($("tabs"), avail.length > 1);
  let tab = avail.includes(S.activeTab) ? S.activeTab : avail[0];
  document.querySelectorAll(".tab").forEach(t => { show(t, avail.includes(t.dataset.tab)); t.classList.toggle("on", t.dataset.tab === tab); });
  show($("pane-inst"), tab === "inst"); show($("pane-ati"), tab === "ati");
  if (tab === "inst") renderInst(); else if (tab === "ati") renderAti();
}
document.querySelectorAll(".tab").forEach(t => t.onclick = async () => { await chrome.storage.local.set({ activeTab: t.dataset.tab }); render(); });

// ---------- ورود / خروج ----------
let busyLogin = false;
async function doLogin() {
  if (busyLogin) return;
  const u = $("loginUser").value.trim();
  if (!u) return flash($("loginMsg"), "نام کاربری را وارد کنید.", true);
  busyLogin = true; $("loginBtn").disabled = true; $("loginBtn").textContent = "در حالِ بررسی در پنل...";
  const r = await bg({ action: "login", username: u }, 20000);
  busyLogin = false; $("loginBtn").disabled = false; $("loginBtn").textContent = "ورود";
  if (!r) return flash($("loginMsg"), "افزونه پاسخ نداد؛ دوباره تلاش کنید.", true);
  if (!r.ok) return flash($("loginMsg"), r.message || "ورود انجام نشد.", true);
  render();
}
$("loginBtn").onclick = doLogin;
$("loginUser").onkeydown = e => { if (e.key === "Enter") doLogin(); };
$("logoutBtn").onclick = async () => { if (!confirm("از حسابِ افزونه خارج شوید؟ (درگاهِ آتی هم قطع می‌شود)")) return; await bg({ action: "logout" }, 50000); render(); };

// ---------- اقساط ----------
function describe(c) {
  if (!c) return "هیچ قراردادی برای شما فعال نیست.";
  if (c.isCash) return "واریزِ نقدی" + (c.bankAccount ? " | حساب: " + c.bankAccount : "");
  let due = "همان روزِ صدور";
  if (c.firstDueType === "days") due = c.firstDueValue + " روز بعد از صدور";
  if (c.firstDueType === "months") due = c.firstDueValue + " ماه بعد از صدور";
  return `${c.numInstallments} قسط | قسطِ اول: ${due} | هر ${c.intervalValue} ${c.intervalUnit === "day" ? "روز" : "ماه"}` + (c.bankAccount ? ` | حساب: ${c.bankAccount}` : "");
}
function renderInst() {
  const sel = $("contractSel"), list = Array.isArray(S.contracts) ? S.contracts : [];
  sel.replaceChildren(...list.map(c => el("option", { value: c.id, text: c.name, selected: c.id === S.defaultContractId })));
  const blocked = !!S.isBlocked;
  $("runBtn").disabled = blocked || !list.length;
  const cur = list.find(c => c.id === S.defaultContractId) || list[0];
  $("contractInfo").textContent = blocked ? "⛔ بخشِ اقساط برای شما غیرفعال است." : describe(cur);
  const both = cur && !cur.isCash && cur.dateBasis === "both";
  show($("basisBox"), !!both);
  if (both) $("basisSel").value = (S.dateBasisChoice || {})[cur.id] === "today" ? "today" : "issue";
  $("autoT").checked = !!S.autoMode && !S.autoPro;
  $("autoProT").checked = !!S.autoMode && !!S.autoPro;
  $("modeTxt").textContent = S.autoMode ? (S.autoPro ? "اتوماتیکِ پرو" : "اتوماتیک") : "دستی (انتخابِ قرارداد با خودتان)";
}
$("contractSel").onchange = async () => { await chrome.storage.local.set({ defaultContractId: $("contractSel").value }); render(); flash($("instMsg"), "قراردادِ پیش‌فرض عوض شد."); };
$("basisSel").onchange = async () => { const c = Object.assign({}, S.dateBasisChoice || {}); c[$("contractSel").value] = $("basisSel").value; await chrome.storage.local.set({ dateBasisChoice: c }); flash($("instMsg"), "مبنای تاریخ ذخیره شد."); };
async function paymentOpenAnywhere() {
  const check = async () => {
    let tabs = []; try { tabs = await chrome.tabs.query({ url: "*://*.pasargadinsurance.ir/*" }); } catch (e) { return false; }
    const r = await Promise.all(tabs.map(t => chrome.tabs.sendMessage(t.id, { action: "query_payment_page" }).then(x => !!(x && x.inPayment)).catch(() => false)));
    return r.some(Boolean);
  };
  return Promise.race([check(), new Promise(r => setTimeout(() => r(false), 1500))]);
}
async function onMode(which) {
  const self = which === "pro" ? $("autoProT") : $("autoT");
  const err = $("modeErr");
  if (self.checked) {
    if (await paymentOpenAnywhere()) { self.checked = false; err.textContent = "اول از صفحه‌ی پرداختِ حق بیمه در همه‌ی تب‌ها خارج شوید، بعد حالت را روشن کنید."; err.classList.add("show"); setTimeout(() => err.classList.remove("show"), 7000); return; }
    await chrome.storage.local.set({ autoMode: true, autoPro: which === "pro" });
  } else await chrome.storage.local.set({ autoMode: false, autoPro: false });
  render();
}
$("autoT").onchange = () => onMode("auto");
$("autoProT").onchange = () => onMode("pro");
$("runBtn").onclick = async () => {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab) return;
    await chrome.tabs.sendMessage(tab.id, { action: "manual_trigger", contractId: $("contractSel").value });
    window.close();
  } catch (e) { flash($("instMsg"), "صفحه‌ی پرداختِ پاسارگاد را یک بار رفرش کنید.", true); }
};
$("syncBtn").onclick = async () => { $("syncBtn").textContent = "..."; const r = await bg({ action: "sync_now" }); $("syncBtn").textContent = "بروزرسانیِ قراردادها"; await render(); flash($("instMsg"), r && r.ok ? "بروز شد." : "پنل در دسترس نیست.", !(r && r.ok)); };

// ---------- درگاهِ آتی ----------
// کدهای خطای ویندوز (rasdial) => توضیحِ فارسی
const RAS_MSG = {
  691: "نام‌کاربری یا رمزِ VPN را سرور نپذیرفت (یا حساب در سرور غیرفعال/هم‌زمان در حالِ استفاده است). مقدارها را در «پنل ← افزونه‌ها ← تنظیمات» دوباره وارد کنید.",
  623: "اتصالی با این نام در ویندوزِ این سیستم پیدا نشد؛ «نامِ اتصالِ ویندوز» در تنظیماتِ افزونه باید دقیقاً همان نامِ VPN در ویندوز باشد.",
  868: "نشانیِ سرورِ VPN پیدا نشد (اینترنت یا DNS را بررسی کنید).",
  800: "سرورِ VPN جواب نداد (اینترنت، فیلترشکن یا فایروال را بررسی کنید).", 809: "سرورِ VPN جواب نداد (ممکن است فایروال یا مودم مانعِ اتصال باشد).",
  807: "اتصال به سرورِ VPN قطع شد؛ دوباره امتحان کنید.", 718: "سرورِ VPN به‌موقع جواب نداد؛ دوباره امتحان کنید.",
  720: "پروتکل/تنظیمِ VPN در ویندوز با سرور جور نیست.", 789: "تنظیمِ امنیتیِ L2TP (کلیدِ مشترک) با سرور جور نیست.",
  756: "این اتصال همین حالا در حالِ برقراری است؛ چند ثانیه بعد دوباره بزنید.", 703: "ویندوز اطلاعاتِ بیشتری برای اتصال می‌خواهد (نام‌کاربری/رمز در تنظیماتِ افزونه خالی است).",
  "-1": "rasdial بیش از حد طول کشید و متوقف شد.", "-2": "rasdial.exe اجرا نشد."
};
function connMsg(r) {
  if (!r || r.code !== "connect_failed") return CODE_MSG[(r && r.code) || "bridge_offline"] || "";
  const c = r.rasCode;
  if (c === undefined || c === null) return CODE_MSG.connect_failed + " (برای دیدنِ علتِ دقیق، نصاب را یک بار دوباره اجرا کنید تا برنامه‌ی زمینه بروز شود.)";
  if (c === 0) return "ویندوز اتصال را برقرار کرد ولی آدرسِ آن پیدا نشد؛ «نامِ اتصالِ ویندوز» را در تنظیمات بررسی کنید.";
  return (RAS_MSG[String(c)] || ("ویندوز خطای " + c + " داد.")) + (r.rasText ? " — " + r.rasText : "");
}
const BRIDGE_MIN = "2.5.0";
const CODE_MSG = { connected: "درگاه وصل شد؛ اینترنتِ عادی هم برقرار است.", disconnected: "درگاه قطع شد.", connect_failed: "اتصال برقرار نشد؛ نام‌کاربری/رمزِ اتصال را با مدیر بررسی کنید.", bridge_offline: "برنامه‌ی زمینه‌ی ویندوز در دسترس نیست.", blocked: "این بخش برای شما مسدود است." };
const safeColor = c => /^#[0-9a-fA-F]{6}$/.test(c || "") ? c : "#0284c7";
const safeUrl = u => /^https?:\/\//i.test(u || "") ? u : "";
function renderAti() {
  show($("installerBanner"), !S.bridgeOnline);
  const lb = $("lblBridge");
  lb.style.color = S.bridgeOnline ? "#10b981" : "#ef4444";
  lb.textContent = S.bridgeOnline ? (S.tunnelConnected ? "متصل به تونل 🟢" : "آماده 🟢") + (S.bridgeVersion ? " · v" + S.bridgeVersion : "") : "اجرا نشده 🔴";
  // نسخه‌ی برنامه‌ی زمینه‌ای که با همین نسخه‌ی افزونه آمده
  show($("bridgeOld"), !!(S.bridgeOnline && S.bridgeVersion && cmpVer(S.bridgeVersion, BRIDGE_MIN) < 0));
  show($("startBridge"), !S.bridgeOnline);
  const pb = $("powerBtn"), pt = $("powerTitle"), ps = $("powerSub");
  if (S.atiBlocked) { pb.disabled = true; pb.className = "power"; pt.style.color = "#ef4444"; pt.textContent = "⛔ این بخش برای شما مسدود است"; ps.textContent = ""; $("sites").replaceChildren(); return; }
  pb.disabled = false;
  // سه حالت: در حالِ اتصال (حلقه‌ی چرخان + تلاشِ دوباره هر ۲ ثانیه) / وصل / خاموش — حالت در پس‌زمینه نگه داشته می‌شود
  const connecting = S.gatewayEnabled && !S.tunnelConnected;
  if (connecting) {
    pb.className = "power busy"; pb.title = "برای توقفِ تلاش بزنید";
    pt.style.color = "#d97706";
    pt.textContent = "در حالِ اتصال به درگاهِ بیمه ایران…" + (S.connAttempt > 1 ? " (تلاشِ " + S.connAttempt + ")" : "");
    const e = S.connError ? connMsg(S.connError) : "";
    ps.textContent = (e ? e + " — " : "") + "تا وصل شدن هر ۲ ثانیه دوباره تلاش می‌شود؛ برای توقف دکمه را بزنید.";
  } else {
    pb.className = S.gatewayEnabled ? "power on" : "power"; pb.title = "";
    pt.style.color = S.gatewayEnabled ? "#10b981" : "#ef4444";
    pt.textContent = S.gatewayEnabled ? "درگاهِ بیمه ایران روشن است" : "درگاهِ بیمه ایران خاموش است";
    ps.textContent = S.gatewayEnabled ? "سایت‌های بیمه ایران و اینترنتِ عادی هم‌زمان کار می‌کنند" : "با روشن کردن، اتصال خودکار برقرار می‌شود (فقط سامانه‌های بیمه از تونل می‌روند)";
  }
  const sites = Array.isArray(S.sites) ? S.sites : [];
  const box = $("sites"); box.replaceChildren();
  if (!sites.length) { box.appendChild(el("div", { class: "muted", text: "سامانه‌ای برای شما تعریف نشده." })); return; }
  sites.forEach(s => {
    const url = safeUrl(s.url);
    const b = el("button", { class: "site", style: "background:" + safeColor(s.color) }, [el("span", { text: "🔗 " + (s.title || "") }), el("span", { text: "ورود ⬅", style: "font-size:11px;opacity:.85" })]);
    b.onclick = () => {
      if (!url) return;
      if (!S.gatewayEnabled) { alert("اول کلیدِ درگاه را روشن کنید."); return; }
      bg({ action: "log_portal_open", siteTitle: s.title, siteUrl: url }, 3000);
      chrome.tabs.create({ url });
    };
    box.appendChild(b);
  });
}
$("powerBtn").onclick = async () => {
  // روشن => شروعِ اتصال در پس‌زمینه؛ در حالِ اتصال یا وصل => توقف/قطع
  const target = !S.gatewayEnabled;
  $("powerBtn").disabled = true;
  const r = await bg({ action: "bridge_power", enabled: target }, target ? 15000 : 60000);
  $("powerBtn").disabled = false;
  if (target && r && r.code === "blocked") { $("powerSub").textContent = CODE_MSG.blocked; return; }
  await load(); renderAti();
  if (target && !S.bridgeOnline) startService();
};
// هر تغییرِ حالت (از پس‌زمینه) همان لحظه دیده شود — پنجره بسته و باز شود یا بینِ زبانه‌ها جابه‌جا شوید
chrome.storage.onChanged.addListener(ch => {
  if (["connState", "connAttempt", "connError", "gatewayEnabled", "tunnelConnected", "bridgeOnline", "bridgeVersion"].some(k => k in ch)) {
    clearTimeout(window.__atiT); window.__atiT = setTimeout(async () => { try { await load(); renderAti(); } catch (e) {} }, 120);
  }
});
async function downloadInstaller() {
  try {
    const r = await fetch(chrome.runtime.getURL("Setup-BimeBama.bat"));
    const a = el("a", { href: URL.createObjectURL(new Blob([await r.blob()], { type: "application/octet-stream" })), download: "Setup-BimeBama.bat" });
    document.body.appendChild(a); a.click(); a.remove();
    $("installerMsg").textContent = "دانلود شد؛ با «Run as administrator» اجرا کنید (یا از پوشه‌ی افزونه مستقیم اجرا کنید).";
  } catch (e) { $("installerMsg").textContent = "دانلودِ نصاب ناموفق بود."; }
}
function startService() {
  $("lblBridge").textContent = "در حالِ راه‌اندازی...";
  const f = el("iframe", { style: "display:none", src: "bimebama://start" });
  document.body.appendChild(f);
  setTimeout(async () => { f.remove(); await bg({ action: "check_bridge" }); render(); }, 3000);
}
$("dlInstaller").onclick = downloadInstaller;
$("bridgeUpd").onclick = async () => {
  const m = $("bridgeUpdMsg"); $("bridgeUpd").disabled = true; m.style.color = "#92400e"; m.textContent = "در حالِ بروزرسانی (تا یک دقیقه)...";
  const r = await bg({ action: "bridge_selfupdate" }, 170000);
  $("bridgeUpd").disabled = false;
  if (r && r.ok && (r.code === "bridge_updated" || r.code === "updated")) { m.style.color = "#059669"; m.textContent = "انجام شد؛ برنامه‌ی زمینه چند ثانیه‌ی دیگر با نسخه‌ی تازه اجرا می‌شود. بعد درگاه را یک بار خاموش و روشن کنید."; setTimeout(async () => { await bg({ action: "check_bridge" }); render(); }, 8000); }
  else { m.style.color = "#dc2626"; m.textContent = "خودکار نشد؛ یک بار Setup-BimeBama.bat را از داخلِ پوشه‌ی همین افزونه (با دسترسیِ مدیر) اجرا کنید."; }
};
$("runProto").onclick = startService;
$("startBridge").onclick = startService;
$("checkBridge").onclick = async () => { $("lblBridge").textContent = "در حالِ بررسی..."; await bg({ action: "check_bridge" }); render(); };
$("syncSites").onclick = async () => { $("syncSites").textContent = "..."; await bg({ action: "sync_now" }); $("syncSites").textContent = "بروزرسانیِ فهرست"; render(); };

// ---------- بروزرسانی ----------
async function runUpdate() {
  await load();
  if (S.bridgeOnline) {
    const box = $("updateBox"); show(box, true); box.textContent = "در حالِ دریافت و نصب از پنل (برنامه‌ی زمینه)...";
    const r = await bg({ action: "bridge_update_now" }, 160000);
    if (r && r.ok) { box.textContent = r.code === "updated" ? "نصب شد (روی همان پوشه‌ی افزونه)؛ افزونه چند لحظه‌ی دیگر خودش دوباره اجرا می‌شود..." : "افزونه بروز است."; return; }
    const why = { no_token: "اول وارد شوید.", bad_package: "بسته‌ی دریافتی مالِ این افزونه نبود.", copy_failed: "نوشتنِ فایل‌ها در پوشه‌ی افزونه ممکن نشد (کروم را ببندید و دوباره بزنید).", bridge_offline: "برنامه‌ی زمینه در دسترس نیست." }[(r && r.code) || "bridge_offline"] || "خطا";
    box.textContent = "بروزرسانی از راهِ برنامه‌ی زمینه نشد: " + why + " صفحه‌ی بروزرسانیِ دستی باز می‌شود...";
    await new Promise(z => setTimeout(z, 2500));
  }
  chrome.tabs.create({ url: chrome.runtime.getURL("update.html") });
  window.close();
}
$("checkUpdate").onclick = async () => {
  $("checkUpdate").textContent = "در حالِ بررسی...";
  await bg({ action: "sync_now" });
  $("checkUpdate").textContent = "⬆️ بررسیِ بروزرسانیِ افزونه";
  await render();
  const box = $("updateBox"); show(box, true); box.replaceChildren();
  const up = S.latestUpdate;
  if (!up || !up.version || cmpVer(up.version, VERSION) <= 0) { box.appendChild(el("div", { text: "✅ افزونه بروز است (نسخه‌ی " + VERSION + ")." })); return; }
  box.appendChild(el("b", { text: "🎉 نسخه‌ی " + up.version + " آماده است", style: "color:#059669" }));
  box.appendChild(el("div", { class: "muted", text: up.changelog || "" }));
  box.appendChild(el("button", { class: "btn ok", text: "نصبِ بروزرسانی", onclick: runUpdate }));
};

// شروع: اول از حافظه‌ی محلی (بی‌معطلی)، بعد همگام‌سازی در پس‌زمینه
(async () => {
  try { await render(); } catch (e) { show($("loginView"), true); }
  if (S.token) { const r = await bg({ action: "sync_now" }, 15000); if (r) try { await render(); } catch (e) {} }
})();
