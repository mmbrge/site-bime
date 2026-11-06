// ==========================================================
// سرویس‌ورکرِ «دستیار بیمه باما» (اقساطِ پاسارگاد + درگاهِ آتیِ بیمه ایران) — نسخه 5
// ورود با نام‌کاربری‌ای که مدیر در پنلِ بیمه باما (دسترسی‌های هر کاربر) تعریف کرده؛ هر بخش فقط برای کسی که
// نام‌کاربریِ همان بخش را دارد باز می‌شود. همه‌ی کارها با نامِ کاربر در پنل ثبت می‌شود.
// نکته: هیچ importScripts/فایلِ جدایی ندارد (قبلاً بعد از خوابیدنِ سرویس‌ورکر خواندنِ config.js خطا می‌داد و
// پنل باز نمی‌شد)؛ نشانیِ پنل موقعِ دانلود از خودِ سایت داخلِ همین فایل نوشته می‌شود.
// ==========================================================
const BB_SERVER = "__BB_SERVER__".replace(/\/+$/, "");
const API = BB_SERVER + "/api/ext_api.php";
const BRIDGE_URL = "http://127.0.0.1:8765";

function cmpVer(a, b) {
  const p = String(a).split(".").map(n => parseInt(n, 10) || 0), q = String(b).split(".").map(n => parseInt(n, 10) || 0);
  for (let i = 0; i < Math.max(p.length, q.length); i++) if ((p[i] || 0) !== (q[i] || 0)) return (p[i] || 0) > (q[i] || 0) ? 1 : -1;
  return 0;
}
async function fetchT(url, opt, ms) {
  const c = new AbortController(); const t = setTimeout(() => c.abort(), ms || 12000);
  try { return await fetch(url, Object.assign({}, opt || {}, { signal: c.signal })); } finally { clearTimeout(t); }
}
async function api(action, body, ms) {
  try {
    const r = await fetchT(API + "?action=" + action, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body || {}) }, ms);
    return await r.json();
  } catch (e) { return null; }
}
async function deviceId() {
  const s = await chrome.storage.local.get(["deviceId"]);
  if (s.deviceId) return s.deviceId;
  const id = "dev_" + Date.now().toString(36) + "_" + Math.random().toString(36).slice(2, 10);
  await chrome.storage.local.set({ deviceId: id });
  return id;
}

// ---------- پلِ ویندوز (درگاهِ آتی) ----------
async function bridgeCall(action, method, payload, ms) {
  try {
    const opt = { method };
    if (method === "POST") { opt.headers = { "Content-Type": "application/json" }; opt.body = JSON.stringify(payload || {}); }
    const r = await fetchT(`${BRIDGE_URL}/?action=${action}`, opt, ms || 6000);
    return await r.json();
  } catch (e) { return null; }
}
async function checkBridge() {
  const d = await bridgeCall("status", "GET", null, 6000);
  const st = d && d.ok ? { bridgeOnline: true, tunnelConnected: !!d.connected, vpnIp: d.ip || "", bridgeHosts: Array.isArray(d.hosts) ? d.hosts : (d.hosts ? [d.hosts] : []), bridgeVersion: d.bridgeVersion || "" } : { bridgeOnline: false, tunnelConnected: false, vpnIp: "" };
  await chrome.storage.local.set(st);
  return st;
}
// نامِ میزبانِ سامانه‌ها (از فهرستِ پنل): برنامه‌ی زمینه بعد از اتصال آن‌ها را با DNSِ VPN پیدا و فقط همان آدرس‌ها را از تونل می‌برد
let SITE_HOSTS = [];
function hostsOf(sites) { const h = []; (sites || []).forEach(x => { try { const n = new URL(x.url).hostname.toLowerCase(); if (n && !h.includes(n)) h.push(n); } catch (e) {} }); return h; }
chrome.storage.local.get(["sites"]).then(s => { SITE_HOSTS = hostsOf(s.sites); }).catch(() => {});
chrome.storage.onChanged.addListener(ch => { if (ch.sites) SITE_HOSTS = hostsOf(ch.sites.newValue); });
function vpnPayload(v) { v = v || {}; return { connectionName: v.connectionName || "Auto_Internet", username: v.username || "", password: v.password || "", routes: Array.isArray(v.routes) ? v.routes : [], hosts: SITE_HOSTS }; }

// ---------- اتصالِ درگاه در پس‌زمینه (مستقل از باز/بسته بودنِ پنجره‌ی افزونه) ----------
// connState: idle | connecting | connected ؛ اگر نشد هر ۲ ثانیه دوباره تا وصل شود؛ زدنِ دوباره‌ی دکمه متوقفش می‌کند
let CONNECT_RUN = 0, LOOP_ACTIVE = false;
const sleep = ms => new Promise(z => setTimeout(z, ms));
async function connectLoop() {
  const run = ++CONNECT_RUN; LOOP_ACTIVE = true;
  let attempt = 0;
  await chrome.storage.local.set({ gatewayEnabled: true, connState: "connecting", connAttempt: 0, connError: null });
  await setBadge();
  try {
    while (run === CONNECT_RUN) {
      const st = await chrome.storage.local.get(["vpnConfig", "atiBlocked"]);
      if (st.atiBlocked) { await chrome.storage.local.set({ gatewayEnabled: false, connState: "idle", connError: { code: "blocked" } }); return; }
      attempt++; await chrome.storage.local.set({ connAttempt: attempt });
      let r = await bridgeCall("connect", "POST", vpnPayload(st.vpnConfig), 90000);
      if (run !== CONNECT_RUN) { if (r && r.connected) await bridgeCall("disconnect", "POST", vpnPayload(st.vpnConfig), 45000); return; }   // در همین حین متوقف شد
      if (!r) { const b = await checkBridge(); r = b.bridgeOnline ? { connected: !!b.tunnelConnected, ip: b.vpnIp || "", code: b.tunnelConnected ? "connected" : "connect_failed" } : { connected: false, code: "bridge_offline" }; }
      if (r.connected) {
        await chrome.storage.local.set({ connState: "connected", tunnelConnected: true, vpnIp: r.ip || "", connError: null });
        await setBadge();
        logEvent("ati", "اتصالِ درگاه", { ip: r.ip || "", tries: attempt });
        return;
      }
      const err = { code: r.code || "connect_failed", rasCode: r.rasCode, rasText: r.rasText || "" };
      await chrome.storage.local.set({ connError: err, tunnelConnected: false });
      if (attempt === 1 || attempt % 15 === 0) logEvent("ati", "اتصالِ ناموفقِ درگاه", Object.assign({ tries: attempt }, err));
      await sleep(2000);
    }
  } finally { if (run === CONNECT_RUN) LOOP_ACTIVE = false; }
}
async function stopGateway() {
  CONNECT_RUN++; LOOP_ACTIVE = false;
  const st = await chrome.storage.local.get(["vpnConfig", "connState", "tunnelConnected"]);
  await chrome.storage.local.set({ gatewayEnabled: false, connState: "idle", connError: null });
  await setBadge();
  const r = await bridgeCall("disconnect", "POST", vpnPayload(st.vpnConfig), 45000);
  await chrome.storage.local.set({ tunnelConnected: false, vpnIp: "" });
  if (st.tunnelConnected || st.connState === "connected") logEvent("ati", "قطعِ درگاه", {});
  return r;
}

// ---------- پیام از صفحه‌ی پنل (فقط همین سایت) ----------
chrome.runtime.onMessageExternal.addListener((msg, sender, sendResponse) => {
  let ok = false; try { ok = new URL(sender.url || sender.origin || "").origin === new URL(BB_SERVER).origin; } catch (e) {}
  if (!ok || !msg || typeof msg !== "object") { sendResponse({ ok: false, error: "forbidden" }); return false; }
  if (msg.action === "ping") { sendResponse({ ok: true, version: chrome.runtime.getManifest().version }); return false; }
  if (msg.action === "resolve") {
    (async () => {
      const hosts = (Array.isArray(msg.hosts) ? msg.hosts : []).map(h => String(h).toLowerCase().trim()).filter(h => /^[a-z0-9.-]{3,120}$/.test(h)).slice(0, 10);
      const st = await chrome.storage.local.get(["vpnConfig"]);
      const r = await bridgeCall("resolve", "POST", Object.assign(vpnPayload(st.vpnConfig), { resolve: hosts }), 30000);
      if (!r) return { ok: false, error: "bridge_offline" };
      if (r.code === "unknown_action") return { ok: false, error: "bridge_old" };
      return { ok: true, resolved: r.resolved || [], viaVpn: !!r.viaVpn };
    })().then(sendResponse).catch(e => sendResponse({ ok: false, error: String(e && e.message || e) }));
    return true;
  }
  sendResponse({ ok: false, error: "unknown" }); return false;
});

// ---------- نشان ----------
async function setBadge() {
  const s = await chrome.storage.local.get(["notifications", "latestUpdate", "gatewayEnabled", "connState"]);
  const n = (s.notifications || []).length;
  if (n) { chrome.action.setBadgeText({ text: String(n) }); chrome.action.setBadgeBackgroundColor({ color: "#ef4444" }); return; }
  if (s.latestUpdate && s.latestUpdate.version && cmpVer(s.latestUpdate.version, chrome.runtime.getManifest().version) > 0) { chrome.action.setBadgeText({ text: "↑" }); chrome.action.setBadgeBackgroundColor({ color: "#0284c7" }); return; }
  if (s.gatewayEnabled && s.connState === "connecting") { chrome.action.setBadgeText({ text: "…" }); chrome.action.setBadgeBackgroundColor({ color: "#f59e0b" }); return; }
  chrome.action.setBadgeText({ text: s.gatewayEnabled ? "ON" : "" }); chrome.action.setBadgeBackgroundColor({ color: "#10b981" });
}

// ---------- ورود / همگام‌سازی ----------
async function applyPayload(r) {
  const s = await chrome.storage.local.get(["defaultContractId", "dismissedNotifIds", "gatewayEnabled"]);
  const contracts = Array.isArray(r.contracts) ? r.contracts : [];
  let def = s.defaultContractId;
  if (!contracts.some(c => c.id === def)) def = r.default_contract && contracts.some(c => c.id === r.default_contract) ? r.default_contract : (contracts[0] ? contracts[0].id : "");
  const dismissed = Array.isArray(s.dismissedNotifIds) ? s.dismissedNotifIds : [];
  const mods = r.modules || {};
  const inst = mods.inst || {}, ati = mods.ati || {};
  await chrome.storage.local.set({
    serverConnected: true, authError: "", userName: r.user && r.user.name || "", displayName: r.user && r.user.display || "",
    modules: { inst: !!inst.allowed, ati: !!ati.allowed }, instUser: inst.username || "", atiUser: ati.username || "",
    contracts, defaultContractId: def, isBlocked: !inst.allowed || !!inst.blocked,
    sites: Array.isArray(r.sites) ? r.sites : [], vpnConfig: r.vpnConfig || {}, atiBlocked: !ati.allowed || !!ati.blocked,
    notifications: (r.notifications || []).filter(n => !dismissed.includes(n.id)), latestUpdate: r.latestUpdate || null, lastSyncAt: Date.now(),
    gatewayEnabled: ati.allowed && !ati.blocked ? !!s.gatewayEnabled : false
  });
  await setBadge();
}
async function logoutLocal(msg) {
  CONNECT_RUN++; LOOP_ACTIVE = false;
  await chrome.storage.local.set({ token: "", userName: "", authError: msg || "", modules: { inst: false, ati: false }, contracts: [], sites: [], vpnConfig: {}, isBlocked: true, atiBlocked: true, notifications: [],
    gatewayEnabled: false, connState: "idle", connError: null });
  await setBadge();
}
async function login(username) {
  const name = String(username || "").trim();
  if (!name) return { ok: false, message: "نام کاربری را وارد کنید." };
  const r = await api("login", { username: name, deviceId: await deviceId(), version: chrome.runtime.getManifest().version }, 15000);
  if (!r) { await chrome.storage.local.set({ serverConnected: false }); return { ok: false, message: "اتصال به پنلِ بیمه باما (" + BB_SERVER + ") برقرار نشد." }; }
  if (!r.ok) return { ok: false, message: r.message || "ورود انجام نشد." };
  await chrome.storage.local.set({ token: r.token });
  await applyPayload(r);
  return { ok: true };
}
async function sync() {
  const s = await chrome.storage.local.get(["token", "gatewayEnabled", "lastUpdateTry"]);
  const bridge = await checkBridge();
  if (!s.token) return { ok: false, needsLogin: true };
  const version = chrome.runtime.getManifest().version;
  const bx = await chrome.storage.local.get(["bridgeHosts", "bridgeVersion"]);
  const r = await api("sync", { token: s.token, version, bridgeOnline: bridge.bridgeOnline, tunnelConnected: bridge.tunnelConnected, vpnIp: bridge.vpnIp,
    bridgeVersion: bx.bridgeVersion || "", siteRoutes: bridge.tunnelConnected && Array.isArray(bx.bridgeHosts) ? bx.bridgeHosts : undefined });
  if (!r) { await chrome.storage.local.set({ serverConnected: false }); return { ok: false }; }
  if (!r.ok) {
    if (r.needsLogin) {
      // دسترسی برداشته شد: درگاه هم قطع می‌شود
      if (bridge.tunnelConnected) await bridgeCall("disconnect", "POST", vpnPayload((await chrome.storage.local.get(["vpnConfig"])).vpnConfig), 45000);
      await logoutLocal(r.message || "دسترسیِ شما به افزونه برداشته شده است.");
      return { ok: false, needsLogin: true, message: r.message };
    }
    await chrome.storage.local.set({ serverConnected: false });
    return { ok: false };
  }
  await applyPayload(r);
  const st = await chrome.storage.local.get(["gatewayEnabled", "atiBlocked", "vpnConfig"]);
  // درگاه: اگر مسدود شد قطع؛ اگر روشن است ولی افتاده، دوباره وصل
  if (st.atiBlocked && bridge.tunnelConnected) {
    CONNECT_RUN++; LOOP_ACTIVE = false;
    await bridgeCall("disconnect", "POST", vpnPayload(st.vpnConfig), 45000);
    await chrome.storage.local.set({ tunnelConnected: false, gatewayEnabled: false, connState: "idle" });
  } else if (st.gatewayEnabled && bridge.bridgeOnline && !bridge.tunnelConnected && !LOOP_ACTIVE) {
    connectLoop();   // افتاده بود (یا پس‌زمینه از نو اجرا شده): دوباره تا وصل شود
  } else if (st.gatewayEnabled && bridge.tunnelConnected) {
    const cs = await chrome.storage.local.get(["connState"]);
    if (cs.connState !== "connected") await chrome.storage.local.set({ connState: "connected", connError: null });
  }
  // بروزرسانیِ خودکار از راهِ پلِ ویندوز (اگر نصب است)
  const upd = r.latestUpdate;
  if (upd && upd.version && cmpVer(upd.version, version) > 0 && bridge.bridgeOnline) {
    const last = s.lastUpdateTry || {};
    if (!(last.v === upd.version && Date.now() - (last.t || 0) < 3600 * 1000)) {
      await chrome.storage.local.set({ lastUpdateTry: { v: upd.version, t: Date.now() } });
      const u = await bridgeCall("update", "POST", { token: s.token }, 150000);
      if (u && u.ok && u.code === "updated") setTimeout(() => chrome.runtime.reload(), 800);
    }
  }
  return { ok: true };
}
async function logEvent(module, event, detail) {
  const s = await chrome.storage.local.get(["token"]);
  if (!s.token) return;
  const r = await api("log", { token: s.token, module, event, detail: detail || {}, version: chrome.runtime.getManifest().version });
  if (r && r.needsLogin) await logoutLocal(r.message);
}

// ---------- چرخه‌ی عمر ----------
function ensureAlarm() { chrome.alarms.get("bb_heartbeat").then(a => { if (!a) chrome.alarms.create("bb_heartbeat", { periodInMinutes: 1 }); }).catch(() => chrome.alarms.create("bb_heartbeat", { periodInMinutes: 1 })); }
async function injectIntoOpenTabs() {
  try {
    for (const tab of await chrome.tabs.query({ url: "*://*.pasargadinsurance.ir/*" })) {
      if (!tab.id || tab.discarded) continue;
      try { await chrome.scripting.executeScript({ target: { tabId: tab.id, allFrames: true }, files: ["content.js"] }); } catch (e) {}
    }
  } catch (e) {}
}
async function checkJustUpdated() {
  try {
    const cur = chrome.runtime.getManifest().version;
    const st = await chrome.storage.local.get(["lastRunVersion"]);
    if (st.lastRunVersion && st.lastRunVersion !== cur) {
      await chrome.storage.local.set({ justUpdated: { from: st.lastRunVersion, to: cur, at: Date.now() }, lastRunVersion: cur });
      try { chrome.notifications.create("bb_updated", { type: "basic", iconUrl: "icons/icon128.png", title: "بروزرسانی انجام شد", message: "دستیارِ بیمه باما به نسخه‌ی " + cur + " بروز شد." }); } catch (e) {}
    } else if (!st.lastRunVersion) await chrome.storage.local.set({ lastRunVersion: cur });
  } catch (e) {}
}
// تنظیمِ پراکسیِ نسخه‌های خیلی قدیمیِ آتی پاک می‌شود (این افزونه پراکسیِ مرورگر را دست نمی‌زند)
function clearLegacyProxy() { try { chrome.proxy.settings.clear({ scope: "regular" }); } catch (e) {} }

chrome.runtime.onInstalled.addListener(async () => {
  clearLegacyProxy(); ensureAlarm(); injectIntoOpenTabs();
  const d = await chrome.storage.local.get(["autoMode"]);
  if (typeof d.autoMode === "undefined") await chrome.storage.local.set({ autoMode: false });
  sync();
});
chrome.runtime.onStartup.addListener(() => { clearLegacyProxy(); ensureAlarm(); sync(); });
chrome.alarms.onAlarm.addListener(a => { if (a.name === "bb_heartbeat") sync(); });
ensureAlarm();
checkJustUpdated();

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (sender.id !== chrome.runtime.id) return false;
  const reply = p => { Promise.resolve(p).then(r => sendResponse(r)).catch(e => sendResponse({ ok: false, error: String(e && e.message || e) })); return true; };
  switch (msg.action) {
    case "login": return reply(login(msg.username));
    case "logout": return reply((async () => { const s = await chrome.storage.local.get(["token", "vpnConfig", "tunnelConnected"]); if (s.tunnelConnected) await bridgeCall("disconnect", "POST", vpnPayload(s.vpnConfig), 45000); if (s.token) await api("logout", { token: s.token }); await logoutLocal(""); return { ok: true }; })());
    case "sync_now": case "register": return reply(sync());
    case "check_bridge": return reply(checkBridge());
    case "bridge_power": return reply((async () => {
      const st = await chrome.storage.local.get(["atiBlocked", "connState", "gatewayEnabled"]);
      if (msg.enabled) {
        if (st.atiBlocked) return { ok: false, code: "blocked" };
        if (!(LOOP_ACTIVE && st.connState === "connecting")) connectLoop();   // در پس‌زمینه ادامه دارد؛ پنجره منتظر نمی‌ماند
        return { ok: true, code: "connecting" };
      }
      await stopGateway();
      return { ok: true, code: "disconnected" };
    })());
    // برنامه‌ی زمینه از روی نصابِ داخلِ پوشه‌ی همین افزونه بروز می‌شود؛ پلِ قدیمی (بدونِ selfupdate) از راهِ بروزرسانیِ کاملِ افزونه
    case "bridge_selfupdate": return reply((async () => {
      const s = await chrome.storage.local.get(["token"]);
      let r = await bridgeCall("selfupdate", "POST", {}, 20000);
      if (!r || r.code === "unknown_action" || r.code === "bridge_up_to_date") {
        const u = await bridgeCall("update", "POST", { token: s.token || "" }, 150000);
        if (u && u.ok && u.code === "updated") { setTimeout(() => chrome.runtime.reload(), 1500); return u; }
        return u || r || { ok: false, code: "bridge_offline" };
      }
      return r;
    })());
    case "bridge_update_now": return reply((async () => {
      const s = await chrome.storage.local.get(["token"]);
      const r = await bridgeCall("update", "POST", { token: s.token || "" }, 150000);
      if (r && r.ok && r.code === "updated") setTimeout(() => chrome.runtime.reload(), 800);
      return r || { ok: false, code: "bridge_offline" };
    })());
    case "log_portal_open": logEvent("ati", "باز کردنِ سامانه", { title: msg.siteTitle || "", url: msg.siteUrl || "" }); sendResponse({ ok: true }); return false;
    // از صفحه‌ی پرداختِ پاسارگاد (content.js): ثبتِ نقدی/اقساط
    case "log_usage": return reply(logEvent("inst", "ثبتِ " + ((msg.payload && msg.payload.paymentType) || "پرداخت"), msg.payload || {}).then(() => ({ ok: true })));
    // قراردادِ ردیفِ سایت برای همین پیش‌نویس (اگر پیش‌نویس به ردیفِ درخواستی در پنل وصل است)
    case "contract_for": return reply((async () => { const s = await chrome.storage.local.get(["token"]); if (!s.token) return { ok: false }; return (await api("contract_for", { token: s.token, no: String(msg.no || "") }, 8000)) || { ok: false }; })());
  }
  return false;
});
