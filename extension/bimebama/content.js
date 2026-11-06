// ==========================================================
// ماموت / فناپ - اسکریپت هوشمند صفحه پرداخت بیمه پاسارگاد (نسخه 3.4.2)
// Created By: MMBehzadi
// ==========================================================

(function () {
// جلوگیری از اجرای دوباره روی همان فریم (تزریق مجدد توسط background)
try {
  if (window.__mamootLoadedFor === chrome.runtime.id) return;
  window.__mamootLoadedFor = chrome.runtime.id;
} catch (e) { return; }

console.log("[Mamoot] اسکریپت روی این فریم فعال شد:", window.location.href);

let isRunningAutomation = false;
let hasTriggeredForCurrentZeroState = false;
let userDismissedForCurrentZeroState = false;
let paymentPollingInterval = null;
let outsideClickListener = null;
let isEvaluating = false;
let nonZeroErrorShown = false;
let unregisteredToastShown = false;
let toastHideTimer = null;
let scheduledEvaluateTimer = null;

// اگر مانده صفر نبود و اتوماتیک روشن بود، تا زمانی که مانده دوباره صفر نشود (یا خود افزونه ردیف‌ها را ثبت کرده باشد) خطا نمایش داده نمی‌شود
const SUPPRESS_ERROR_KEY = "mamoot_suppress_nonzero_error";
const SUPPRESS_ERROR_TTL_MS = 90000;

function markAutomationJustRan() {
  try { sessionStorage.setItem(SUPPRESS_ERROR_KEY, String(Date.now())); } catch (e) {}
  nonZeroErrorShown = true;
}

function isErrorSuppressed() {
  try {
    const t = parseInt(sessionStorage.getItem(SUPPRESS_ERROR_KEY), 10);
    return !!t && Date.now() - t < SUPPRESS_ERROR_TTL_MS;
  } catch (e) {
    return false;
  }
}
const NONZERO_ERROR_TEXT = "مانده اقساط ۰ نیست؛ لطفاً ردیف‌های موجود را کامل پاک کنید.";

const KNOWN_BANK_ACCOUNTS = {
  "5366595106": "72947350",
  "1702-314-722318-1": "484",
  "225-8100-20159-5": "41",
  "225-8100-20159-4": "1"
};

// قراردادهای پیش‌فرض پشتیبان (در صورتی که هنوز با سرور همگام‌سازی نشده باشد)
const FALLBACK_CONTRACTS = [
  {
    id: "contract_cash_1",
    name: "پرداخت نقدی",
    isCash: true,
    numInstallments: 1,
    firstDueType: "same_day",
    firstDueValue: 0,
    intervalUnit: "month",
    intervalValue: 1,
    bankAccount: "5366595106"
  },
  {
    id: "contract_inst_6m",
    name: "اقساط ۶ ماهه ماموت (شروع از ۱ ماه بعد)",
    isCash: false,
    numInstallments: 6,
    firstDueType: "months",
    firstDueValue: 1,
    intervalUnit: "month",
    intervalValue: 1,
    bankAccount: "5366595106"
  }
];

// ---------- ۱. توابع کمکی اعداد و فرمول اقساط ----------
function formatNumber(n) {
  try {
    return Number(n).toLocaleString("en-US");
  } catch (e) {
    return String(n);
  }
}

function toLatinDigits(str) {
  if (!str) return "";
  const persian = "۰۱۲۳۴۵۶۷۸۹";
  const arabic = "٠١٢٣٤٥٦٧٨٩";
  return String(str)
    .replace(/[۰-۹]/g, (d) => String(persian.indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String(arabic.indexOf(d)));
}

function normalizePersianText(str) {
  return toLatinDigits(str || "")
    .replace(/ي/g, "ی")
    .replace(/ك/g, "ک")
    .replace(/\s+/g, " ")
    .trim();
}

function parseNumber(text) {
  if (text === null || typeof text === "undefined") return null;
  const cleaned = toLatinDigits(String(text)).replace(/,/g, "").trim();
  if (!/^-?\d+$/.test(cleaned)) return null;
  const n = parseInt(cleaned, 10);
  return Number.isNaN(n) ? null : n;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function pythonRound(x) {
  const floor = Math.floor(x);
  const diff = x - floor;
  const EPS = 1e-9;
  if (diff < 0.5 - EPS) return floor;
  if (diff > 0.5 + EPS) return floor + 1;
  return floor % 2 === 0 ? floor : floor + 1;
}

function calculateInstallments(totalPrice, numInstallments) {
  if (numInstallments <= 1) {
    return { firstPayment: totalPrice, eachInstallment: 0 };
  }
  const baseInstallment = totalPrice / numInstallments;
  const baseInstallmentInt = Math.trunc(baseInstallment);
  const baseInstallmentFloor = Math.floor(baseInstallmentInt / 1000) * 1000;

  const remainderTotal = totalPrice - baseInstallmentFloor * numInstallments;

  let firstPayment = baseInstallmentFloor + remainderTotal;
  firstPayment = pythonRound(firstPayment / 1000) * 1000;

  const remainingTotal = totalPrice - firstPayment;
  let eachInstallment = remainingTotal / (numInstallments - 1);
  eachInstallment = pythonRound(eachInstallment / 1000) * 1000;

  return { firstPayment, eachInstallment };
}

// ---------- ۲. تقویم شمسی دقیق (۳۱، ۳۰ و ۲۹ روزه) ----------
function isJalaliLeapYear(jy) {
  const breaks = [
    -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181,
    1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178
  ];
  let jp = breaks[0];
  let jump = 0;
  for (let i = 1; i < breaks.length; i++) {
    const jm = breaks[i];
    jump = jm - jp;
    if (jy < jm) break;
    jp = jm;
  }
  let n = jy - jp;
  if (jump - n < 6) {
    n = n - jump + Math.floor((jump + 4) / 33) * 33;
  }
  let leap = (((n + 1) % 33) - 1) % 4;
  if (leap === -1) leap = 4;
  return leap === 0;
}

function getJalaliMonthDays(year, month) {
  if (month >= 1 && month <= 6) return 31;
  if (month >= 7 && month <= 11) return 30;
  if (month === 12) return isJalaliLeapYear(year) ? 30 : 29;
  return 30;
}

function addDaysJalali(year, month, day, daysToAdd) {
  let y = year;
  let m = month;
  let d = day;
  let remaining = parseInt(daysToAdd, 10) || 0;

  while (remaining > 0) {
    const maxDays = getJalaliMonthDays(y, m);
    if (d + remaining <= maxDays) {
      d += remaining;
      remaining = 0;
    } else {
      remaining -= (maxDays - d + 1);
      d = 1;
      m += 1;
      if (m > 12) {
        m = 1;
        y += 1;
      }
    }
  }
  return { y, m, d };
}

function addMonthsJalali(year, month, day, monthsToAdd) {
  const addM = parseInt(monthsToAdd, 10) || 0;
  const totalMonths = (year * 12 + (month - 1)) + addM;
  const newY = Math.floor(totalMonths / 12);
  const newM = (totalMonths % 12) + 1;
  const maxDays = getJalaliMonthDays(newY, newM);
  const newD = Math.min(day, maxDays);
  return { y: newY, m: newM, d: newD };
}

function formatJalali(dateObj) {
  const y = String(dateObj.y);
  const m = String(dateObj.m).padStart(2, "0");
  const d = String(dateObj.d).padStart(2, "0");
  return `${y}/${m}/${d}`;
}

function getTodayJalali() {
  try {
    const parts = new Intl.DateTimeFormat("fa-IR-u-ca-persian-nu-latn", {
      year: "numeric",
      month: "2-digit",
      day: "2-digit"
    }).formatToParts(new Date());
    return {
      y: parseInt(parts.find((p) => p.type === "year").value, 10),
      m: parseInt(parts.find((p) => p.type === "month").value, 10),
      d: parseInt(parts.find((p) => p.type === "day").value, 10)
    };
  } catch (e) {
    return { y: 1405, m: 1, d: 1 };
  }
}

// استخراج تاریخ صدور از بالای صفحه
// شماره‌ی پیش‌نویسِ همین صفحه‌ی «دریافت حق بیمه» (برای قراردادِ ردیفِ سایت)
function extractDraftNoFromPage() {
  const rx = /(\d+\/\d+\/1[34]\d{2}\/\d+)/;
  for (const el of Array.from(document.querySelectorAll("td, th, span, label, div"))) {
    if (el.children.length > 3) continue;
    const txt = normalizePersianText(el.textContent).replace(/[:：]/g, "").trim();
    if (txt === "شماره پیش نویس" || txt === "شماره پیشنویس" || txt === "شماره پیش‌نویس") {
      for (const c of [el.nextElementSibling, el.parentElement ? el.parentElement.nextElementSibling : null, el.parentElement]) {
        if (!c) continue;
        const m = normalizePersianText(c.textContent).match(rx);
        if (m) return m[1];
      }
    }
  }
  return "";
}
// قراردادِ ردیفِ درخواستِ پنل برای این پیش‌نویس (یک بار در هر صفحه)
let siteContractCache = null;
async function siteContractForPage() {
  const no = extractDraftNoFromPage();
  if (!no) return null;
  if (siteContractCache && siteContractCache.no === no) return siteContractCache.id;
  const r = await new Promise((res) => {
    try { chrome.runtime.sendMessage({ action: "contract_for", no }, (x) => { if (chrome.runtime.lastError) res(null); else res(x); }); } catch (e) { res(null); }
    setTimeout(() => res(null), 6000);
  });
  siteContractCache = { no, id: r && r.ok && r.contract_id ? r.contract_id : null, name: r && r.contract_name };
  return siteContractCache.id;
}

function extractIssueDateFromPage() {
  const dateRegex = /(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/;
  const allEls = Array.from(document.querySelectorAll("td, th, span, label, div"));

  for (const el of allEls) {
    if (el.children.length > 3) continue;
    const txt = normalizePersianText(el.textContent);
    if (txt === "تاریخ صدور" || txt === "تاریخ صدور:") {
      const candidates = [
        el.nextElementSibling,
        el.previousElementSibling,
        el.parentElement ? el.parentElement.nextElementSibling : null,
        el.parentElement
      ];
      for (const c of candidates) {
        if (!c) continue;
        const m = normalizePersianText(c.textContent).match(dateRegex);
        if (m) {
          return {
            y: parseInt(m[1], 10),
            m: parseInt(m[2], 10),
            d: parseInt(m[3], 10)
          };
        }
      }
    }
  }

  const bodyTxt = normalizePersianText(document.body.innerText);
  const direct = bodyTxt.match(/تاریخ صدور\s*[:\s]*(\d{4})\/(\d{1,2})\/(\d{1,2})/);
  if (direct) {
    return {
      y: parseInt(direct[1], 10),
      m: parseInt(direct[2], 10),
      d: parseInt(direct[3], 10)
    };
  }
  return getTodayJalali();
}

// تشخیص عدد «جمع ريالي مستندات جديد و حواله هاي موجود»
// روش‌ها به ترتیب: ۱) اعداد داخل همان سلول  ۲) نزدیک‌ترین عدد هم‌خط با عنوان (بر اساس مختصات صفحه)
// ۳) متن صفحه  ۴) جدول اسناد مالی
const DOCS_LABEL = "جمع ریالی مستندات جدید";
const DOCS_OTHER_LABELS = ["مبلغ ریالی مانده", "مستندات انتخاب", "مانده ریالی اعتبار"];
let lastDocsTotalLog = null;

function logDocsTotal(value, method) {
  const key = `${value}|${method}`;
  if (key !== lastDocsTotalLog) {
    lastDocsTotalLog = key;
    console.log(`[Mamoot] جمع ریالی مستندات جدید و حواله های موجود = ${value} (روش: ${method})`);
  }
  return value;
}

function cellTextWithValues(el) {
  let txt = el.textContent || "";
  el.querySelectorAll("input").forEach((i) => {
    if (i.type !== "hidden" && i.value) txt += " " + i.value;
  });
  if (el.tagName === "INPUT" && el.value) txt += " " + el.value;
  return normalizePersianText(txt);
}

function getNewDocumentsTotalAmount() {
  if (!document.body) return 0;

  // پیدا کردن کوچک‌ترین المنت‌هایی که عنوان را کامل دارند
  const labelEls = [];
  document.body.querySelectorAll("*").forEach((el) => {
    const tag = el.tagName;
    if (tag === "SCRIPT" || tag === "STYLE" || tag === "NOSCRIPT") return;
    if (!normalizePersianText(el.textContent).includes(DOCS_LABEL)) return;
    const childHas = Array.from(el.children).some((ch) =>
      normalizePersianText(ch.textContent).includes(DOCS_LABEL)
    );
    if (!childHas) labelEls.push(el);
  });

  // روش ۱: بالا رفتن از المنت عنوان تا اولین ظرفی که مبلغ‌های دیگر را ندارد و عدد دارد
  for (const labelEl of labelEls) {
    let node = labelEl;
    for (let depth = 0; depth < 6 && node && node !== document.body; depth++) {
      const txt = cellTextWithValues(node);
      if (DOCS_OTHER_LABELS.some((o) => txt.includes(o))) break;
      const nums = txt.match(/\d[\d,]*/g);
      if (nums && nums.length > 0) {
        const val = parseNumber(nums[0]);
        if (val !== null) return logDocsTotal(val, "cell");
      }
      node = node.parentElement;
    }
  }

  // روش ۲: نزدیک‌ترین عدد هم‌خط با عنوان بر اساس موقعیت روی صفحه
  for (const labelEl of labelEls) {
    const rects = Array.from(labelEl.getClientRects()).filter((r) => r.width > 0 && r.height > 0);
    if (!rects.length) continue;
    const top = Math.min(...rects.map((r) => r.top));
    const bottom = Math.max(...rects.map((r) => r.bottom));
    const left = Math.min(...rects.map((r) => r.left));
    const right = Math.max(...rects.map((r) => r.right));

    let best = null;
    document.body.querySelectorAll("*").forEach((el) => {
      if (el === labelEl || labelEl.contains(el)) {
        if (el === labelEl) return;
      }
      if (el.children.length > 0 && el.tagName !== "INPUT") return;
      const txt = cellTextWithValues(el);
      if (!/^\d[\d,]*$/.test(txt)) return;
      const r = el.getBoundingClientRect();
      if (r.width <= 0 || r.height <= 0) return;
      const cy = (r.top + r.bottom) / 2;
      if (cy < top - 6 || cy > bottom + 6) return;
      const gap = r.right < left ? left - r.right : r.left > right ? r.left - right : 0;
      if (gap > 300) return;
      if (!best || gap < best.gap) best = { gap, txt };
    });
    if (best) {
      const val = parseNumber(best.txt);
      if (val !== null) return logDocsTotal(val, "position");
    }
  }

  // روش ۳: بررسی متن صفحه
  const rawPage = normalizePersianText(document.body.innerText);
  const mBefore = rawPage.match(/(?:^|\s)([\d,]+)\s+جمع ریالی مستندات جدید/);
  if (mBefore) {
    const v = parseNumber(mBefore[1]);
    if (v !== null) return logDocsTotal(v, "text-before");
  }
  const mMid = rawPage.match(/جمع ریالی مستندات جدید\s*و?\s*([\d,]+)/);
  if (mMid) {
    const v = parseNumber(mMid[1]);
    if (v !== null) return logDocsTotal(v, "text-after");
  }
  const mAfter = rawPage.match(/حواله های موجود\s*[:\s]*([\d,]+)/);
  if (mAfter) {
    const v = parseNumber(mAfter[1]);
    if (v !== null) return logDocsTotal(v, "text-after-2");
  }

  // روش ۴: جدول «لیست اسناد مالی» (اگر خالی است یعنی جمع مستندات جدید صفر است)
  const scenarioTbody = document.getElementById("editform:AsnadMaaliScenario:tbody_element");
  if (scenarioTbody) {
    const rowText = normalizePersianText(scenarioTbody.textContent);
    if (scenarioTbody.children.length === 0 || rowText === "") {
      return logDocsTotal(0, "table-empty");
    }
    return logDocsTotal(1, "table-rows");
  }

  return logDocsTotal(0, "default");
}

// ساخت جدول زمان‌بندی اقساط
// مبنای تاریخ سررسیدها: "issue" (تاریخ صدور) یا "today" (تاریخ روز شمسی)
function resolveDateBasis(contract, choices) {
  if (!contract || contract.isCash) return "issue";
  if (contract.dateBasis === "today") return "today";
  if (contract.dateBasis === "both") {
    return choices && choices[contract.id] === "today" ? "today" : "issue";
  }
  return "issue";
}

function buildSchedule(totalAmount, realIssueDate, contract, basis) {
  const useToday = basis === "today" && !contract.isCash;
  const issueDate = useToday ? getTodayJalali() : realIssueDate;

  if (contract.isCash || contract.numInstallments <= 0) {
    return {
      isCash: true,
      totalAmount,
      issueDate: realIssueDate,
      baseDate: realIssueDate,
      basis: "issue",
      firstPayment: totalAmount,
      eachInstallment: 0,
      items: [{ index: 1, title: "واریز نقدی", amount: totalAmount, dueDate: realIssueDate }]
    };
  }

  const count = Math.max(1, parseInt(contract.numInstallments, 10) || 1);
  const { firstPayment, eachInstallment } = calculateInstallments(totalAmount, count);

  let firstDue = { ...issueDate };
  if (contract.firstDueType === "days") {
    firstDue = addDaysJalali(issueDate.y, issueDate.m, issueDate.d, contract.firstDueValue || 0);
  } else if (contract.firstDueType === "months") {
    firstDue = addMonthsJalali(issueDate.y, issueDate.m, issueDate.d, contract.firstDueValue || 0);
  }

  const items = [];
  for (let i = 0; i < count; i++) {
    let currentDue;
    if (i === 0) {
      currentDue = { ...firstDue };
    } else if (contract.intervalUnit === "day") {
      currentDue = addDaysJalali(firstDue.y, firstDue.m, firstDue.d, i * (contract.intervalValue || 30));
    } else {
      currentDue = addMonthsJalali(firstDue.y, firstDue.m, firstDue.d, i * (contract.intervalValue || 1));
    }

    items.push({
      index: i + 1,
      title: `قسط ${i + 1}`,
      amount: i === 0 ? firstPayment : eachInstallment,
      dueDate: currentDue
    });
  }

  return {
    isCash: false,
    totalAmount,
    issueDate: realIssueDate,
    baseDate: issueDate,
    basis: useToday ? "today" : "issue",
    firstPayment,
    eachInstallment,
    items
  };
}

// ---------- ۳. توابع پر کردن فرم‌های سامانه پاسارگاد ----------
function setInputValue(el, val) {
  if (!el) return;
  el.focus();
  el.value = String(val);
  el.dispatchEvent(new Event("input", { bubbles: true }));
  el.dispatchEvent(new KeyboardEvent("keyup", { bubbles: true, keyCode: 50 }));
  el.dispatchEvent(new Event("change", { bubbles: true }));
  el.blur();
}

function setJalaliDateOnForm(baseId, dateObj) {
  const yStr = String(dateObj.y);
  const mStr = String(dateObj.m).padStart(2, "0");
  const dStr = String(dateObj.d).padStart(2, "0");

  const yEl = document.getElementById(`${baseId}_y`);
  const mEl = document.getElementById(`${baseId}_m`);
  const dEl = document.getElementById(`${baseId}_d`);
  const hiddenEl = document.getElementById(baseId);

  if (yEl) yEl.value = yStr;
  if (mEl) mEl.value = mStr;
  if (dEl) dEl.value = dStr;
  if (hiddenEl) hiddenEl.value = `${yStr}/${mStr}/${dStr}`;

  [yEl, mEl, dEl].forEach((input) => {
    if (!input) return;
    input.focus();
    input.dispatchEvent(new KeyboardEvent("keydown", { bubbles: true, keyCode: 50 }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
    input.dispatchEvent(new FocusEvent("blur", { bubbles: true }));
  });
}

async function resolveBankAccountId(accountNumber, lovUrl) {
  const cleanAcc = String(accountNumber).trim();
  if (KNOWN_BANK_ACCOUNTS[cleanAcc]) {
    return { id: KNOWN_BANK_ACCOUNTS[cleanAcc], desc: cleanAcc };
  }
  try {
    const res = await fetch(lovUrl, { credentials: "include" });
    const html = await res.text();
    const regex = /select\(\s*'([^']+)'\s*,\s*'([^']+)'\s*\)/g;
    let match;
    while ((match = regex.exec(html)) !== null) {
      if (match[2].trim() === cleanAcc || match[1].trim() === cleanAcc) {
        return { id: match[1].trim(), desc: match[2].trim() };
      }
    }
  } catch (e) {
    console.warn("[Mamoot] خطا در دریافت لیست حساب بانکی:", e);
  }
  return { id: "72947350", desc: cleanAcc };
}

async function selectBankAccountOnForm(baseInputId, accountNumber, isInstallmentTab) {
  const lovUrl = isInstallmentTab
    ? "/cins/hesaabBimeh.lov.html?isOmr=false&vahedBimeh=0&noeHesab=2"
    : "/cins/hesaabBimeh.lov.html?filterByVahedBimeh=true&noeHesabList=2,5";

  localStorage.setItem("mamoot_target_bank_account", String(accountNumber).trim());
  const { id, desc } = await resolveBankAccountId(accountNumber, lovUrl);

  const hiddenInput = document.getElementById(baseInputId);
  const descInput = document.getElementById(`${baseInputId}.desc`);

  if (hiddenInput) hiddenInput.value = id;
  if (descInput) {
    descInput.value = desc;
    descInput.dispatchEvent(new Event("change", { bubbles: true }));
  }
}

function handleLovPopupWindow() {
  if (!window.location.href.includes("hesaabBimeh.lov.html")) return false;
  const targetAcc = localStorage.getItem("mamoot_target_bank_account") || "5366595106";
  const links = Array.from(document.querySelectorAll("a.btn2, a[onclick*='window.opener.select']"));
  const matched = links.find((a) => a.textContent.trim().includes(targetAcc));
  if (matched) {
    matched.click();
    return true;
  }
  return false;
}

// ---------- ثبت نهایی (حالت اتوماتیک پرو): کلیک روی دکمه «ثبت» پس از ایجاد «همه» ردیف‌ها ----------
// قبلاً به محض ظاهر شدن «اولین» ردیف دکمه ثبت زده می‌شد؛ حالا تعداد ردیف‌های جدول لیست اسناد مالی
// (editform:AsnadMaaliScenario) باید به تعداد مورد انتظار (اقساط تعریف‌شده یا ۱ ردیف نقدی) برسد.
const FINAL_SUBMIT_KEY = "mamoot_pending_final_submit";
const FINAL_SUBMIT_TTL_MS = 90000;
let finalWatcherPromise = null;

function armFinalSubmit(expectedRows) {
  try {
    sessionStorage.setItem(
      FINAL_SUBMIT_KEY,
      JSON.stringify({ t: Date.now(), expected: Math.max(1, parseInt(expectedRows, 10) || 1) })
    );
  } catch (e) {}
}

function readFinalSubmitState() {
  try {
    const raw = sessionStorage.getItem(FINAL_SUBMIT_KEY);
    if (!raw) return null;
    let st;
    try { st = JSON.parse(raw); } catch (e) { st = null; }
    // سازگاری با فرمت قدیمی (فقط عدد زمان)
    if (!st || typeof st !== "object") st = { t: parseInt(raw, 10) || Date.now(), expected: 1 };
    return st;
  } catch (e) {
    return null;
  }
}

function clearFinalSubmit() {
  try { sessionStorage.removeItem(FINAL_SUBMIT_KEY); } catch (e) {}
}

// تعداد ردیف‌های واقعی جدول «لیست اسناد مالی»؛ اگر جدول در صفحه نبود -1 برمی‌گرداند
function getScenarioRowCount() {
  const tb =
    document.getElementById("editform:AsnadMaaliScenario:tbody_element") ||
    (document.getElementById("editform:AsnadMaaliScenario")
      ? document.getElementById("editform:AsnadMaaliScenario").querySelector("tbody")
      : null);
  if (!tb) return -1;
  let n = 0;
  Array.from(tb.children).forEach((tr) => {
    if (tr.tagName !== "TR") return;
    const tds = Array.from(tr.children).filter((c) => c.tagName === "TD");
    if (tds.length < 4) return;
    if (!/\d/.test(toLatinDigits(tds[0].textContent || ""))) return;
    n++;
  });
  return n;
}

// آیا تعداد ردیف‌ها به مقدار مورد انتظار رسیده است؟ (اگر جدول پیدا نشد از جمع مستندات استفاده می‌شود)
function rowsReady(expected) {
  const cnt = getScenarioRowCount();
  if (cnt >= 0) return cnt >= expected;
  return getNewDocumentsTotalAmount() !== 0;
}

// منتظر می‌ماند تا تعداد ردیف‌ها به expected برسد
async function waitForRowCount(expected, maxMs) {
  const start = Date.now();
  while (Date.now() - start < maxMs) {
    if (!isExtensionAlive()) return false;
    if (isInsidePaymentPage() && rowsReady(expected)) return true;
    await sleep(300);
  }
  return false;
}

// پایش ثبت نهایی: تا زمانی که همه ردیف‌ها در لیست ظاهر نشده‌اند صبر می‌کند و سپس «ثبت» را می‌زند.
// بین رفرش‌های صفحه هم کار می‌کند (وضعیت در sessionStorage است).
function watchFinalSubmit() {
  if (finalWatcherPromise) return finalWatcherPromise;
  finalWatcherPromise = (async () => {
    const st = readFinalSubmitState();
    if (!st) return;
    isRunningAutomation = true;
    try {
      let stableHits = 0;
      while (true) {
        if (!isExtensionAlive()) return;
        const cur = readFinalSubmitState();
        if (!cur) return; // توسط نمونه‌ی دیگری انجام یا لغو شده است
        if (Date.now() - cur.t > FINAL_SUBMIT_TTL_MS) {
          clearFinalSubmit();
          showToast("همه ردیف‌ها در لیست ایجاد نشد؛ ثبت نهایی انجام نشد. لطفاً دستی ثبت کنید.", "error", 6000);
          return;
        }
        if (isInsidePaymentPage() && rowsReady(cur.expected)) {
          // دو بررسی پشت‌سرهم (پایدار بودن جدول) تا از به‌روزرسانی نیمه‌کاره صفحه جلوگیری شود
          stableHits++;
          if (stableHits >= 2) break;
        } else {
          stableHits = 0;
        }
        updateFloatingPanelStatus(
          `در انتظار ثبت همه ردیف‌ها در لیست (${Math.max(0, getScenarioRowCount())} از ${cur.expected})...`
        );
        await sleep(350);
      }

      clearFinalSubmit(); // فقط یک بار
      const btn = document.getElementById("editform:save");
      if (!btn) {
        showToast("دکمه «ثبت» پیدا نشد؛ لطفاً ثبت نهایی را دستی انجام دهید.", "error", 5000);
        return;
      }
      updateFloatingPanelStatus("همه ردیف‌ها ایجاد شد؛ در حال ثبت نهایی...");
      await sleep(400);
      btn.click();
    } finally {
      isRunningAutomation = false;
    }
  })().finally(() => {
    finalWatcherPromise = null;
  });
  return finalWatcherPromise;
}

// پایان کار: در حالت پرو منتظر ظاهر شدن همه ردیف‌ها می‌ماند و «ثبت» را می‌زند؛ سپس پنل بسته و قفل آزاد می‌شود
async function finalizeAfterAdd(autoPro) {
  try {
    if (autoPro && readFinalSubmitState()) {
      await watchFinalSubmit();
    }
  } finally {
    removeFloatingPanel();
    if (!finalWatcherPromise) isRunningAutomation = false;
  }
}

// ---------- ۴. اجرای اتوماسیون نقدی و دو مرحله‌ای اقساط ----------
async function executeContractAutomation(contract, schedule, opts = {}) {
  const autoPro = !!opts.autoPro;
  isRunningAutomation = true;
  markAutomationJustRan();
  removeToast();

  // غیرفعال کردن بستن پنل با کلیک بیرون در حین انجام عملیات
  detachOutsideClickListener();

  chrome.runtime.sendMessage({
    action: "log_usage",
    payload: {
      draftNo: extractDraftNoFromPage(),
      contractId: contract.id,
      contractName: contract.name,
      paymentType: schedule.isCash ? "نقدی" : "اقساطی",
      totalAmount: schedule.totalAmount,
      numInstallments: schedule.items.length,
      issueDate: formatJalali(schedule.issueDate)
    }
  });

  if (schedule.isCash) {
    updateFloatingPanelStatus(`در حال ثبت واریز نقدی (${contract.name})...`);
    sessionStorage.setItem("mamoot_just_finished_all", "true");

    const cashTabBtn = document.querySelector('input[name="editform:tabbedPane.0"]');
    if (cashTabBtn) cashTabBtn.click();
    await sleep(250);

    setInputValue(document.getElementById("pardakhtMablaghVarizBeHesab"), schedule.totalAmount);
    setInputValue(document.getElementById("mablaghVarizBeHesab"), schedule.totalAmount);
    await sleep(200);

    await selectBankAccountOnForm("hesabBimehVarizBeHesab", contract.bankAccount, false);
    await sleep(250);

    const saveCashBtn = document.getElementById("editform:vaarizbehesaabtab:vaarizbehesaabsavebutton");
    if (autoPro && saveCashBtn) armFinalSubmit(1);
    if (saveCashBtn) saveCashBtn.click();

    // اگر صفحه رفرش کامل نشد، وضعیت اجرا آزاد شود تا پنل در نوبت بعد دوباره بتواند بیاید
    setTimeout(() => finalizeAfterAdd(autoPro), 1500);
    return;
  }

  // ---------- حالت اقساطی (مرحله ۱: قسط اول) ----------
  const totalCount = schedule.items.length;
  const moddatMonths = contract.intervalUnit === "month"
    ? Math.max(1, parseInt(contract.intervalValue, 10) || 1)
    : Math.max(1, Math.round((parseInt(contract.intervalValue, 10) || 30) / 30));

  updateFloatingPanelStatus(`مرحله ۱ از ۲: در حال تعریف قسط اول...`);

  const installTabBtn = document.querySelector('input[name="editform:tabbedPane.3"]');
  if (installTabBtn) installTabBtn.click();
  await sleep(250);

  setInputValue(document.getElementById("tedadAghsaat"), 1);
  setInputValue(document.getElementById("moddatAghsaat"), moddatMonths);
  setJalaliDateOnForm(
    "editform:ghest_tab_sanadtaahodavar:tarikhSarresidAghsat",
    schedule.items[0].dueDate
  );
  setInputValue(document.getElementById("mablaghAghsat"), schedule.firstPayment);

  await selectBankAccountOnForm(
    "editform:ghest_tab_sanadtaahodavar:hesabBimehAghsat",
    contract.bankAccount,
    true
  );
  await sleep(250);

  // ذخیره اطلاعات مرحله دوم (اقساط دوم به بعد) قبل از کلیک روی اضافه به لیست
  if (totalCount > 1 && schedule.eachInstallment > 0) {
    const step2Payload = {
      remainingCount: totalCount - 1,
      moddatMonths,
      secondDueDate: schedule.items[1].dueDate,
      eachInstallment: schedule.eachInstallment,
      bankAccount: contract.bankAccount,
      contractName: contract.name,
      schedule,
      autoPro,
      createdAt: Date.now()
    };
    sessionStorage.setItem("mamoot_pending_step2", JSON.stringify(step2Payload));
    window.__mamootStep1ClickedOnThisWindow = true;
  } else {
    sessionStorage.setItem("mamoot_just_finished_all", "true");
    if (autoPro) armFinalSubmit(totalCount);
  }

  const saveInstallBtn = document.getElementById("editform:ghest_tab_sanadtaahodavar:aghsaat_savebutton");
  if (saveInstallBtn) saveInstallBtn.click();

  // اگر صفحه بدون رفرش کامل آپدیت شد، پس از 0.5 ثانیه مرحله دوم را بررسی و اجرا کن
  if (totalCount > 1 && schedule.eachInstallment > 0) {
    setTimeout(() => {
      window.__mamootAllowSameWindowStep2 = true;
      checkAndRunPendingStep2();
    }, 600);
    // شبکه اطمینان: اگر مرحله دوم در همین صفحه اجرا نشد و صفحه هم رفرش نشد، قفل اجرا آزاد شود
    setTimeout(() => {
      if (!window.__mamootStep2Running) isRunningAutomation = false;
    }, 15000);
  } else {
    setTimeout(() => finalizeAfterAdd(autoPro), 1500);
  }
}

// اجرای مرحله دوم اقساط با مکث 0.5 ثانیه (500 میلی‌ثانیه)
async function checkAndRunPendingStep2() {
  const raw = sessionStorage.getItem("mamoot_pending_step2");
  if (!raw) return false;

  if (window.__mamootStep1ClickedOnThisWindow && !window.__mamootAllowSameWindowStep2) {
    return true;
  }
  if (window.__mamootStep2Running) return true;

  let payload;
  try {
    payload = JSON.parse(raw);
  } catch (e) {
    sessionStorage.removeItem("mamoot_pending_step2");
    return false;
  }

  if (Date.now() - payload.createdAt > 60000) {
    sessionStorage.removeItem("mamoot_pending_step2");
    return false;
  }

  const installTabBtn = document.querySelector('input[name="editform:tabbedPane.3"]');
  if (!installTabBtn) return false;

  window.__mamootStep2Running = true;
  isRunningAutomation = true;
  markAutomationJustRan();
  removeToast();

  // نمایش پنل شناور در طول مرحله دوم
  renderFloatingPanel({
    mode: "preview_only",
    contractName: payload.contractName,
    schedule: payload.schedule,
    statusText: `قسط اول ثبت شد. در حال ثبت ${payload.remainingCount} قسط بعدی...`
  });

  // مکث دقیق 0.5 ثانیه (500 میلی‌ثانیه) طبق درخواست شما
  await sleep(500);

  // مطمئن می‌شویم قسط اول واقعاً در لیست ثبت شده است (در صورت به‌روزرسانی بدون رفرش صفحه)
  if (getScenarioRowCount() === 0) {
    updateFloatingPanelStatus("در انتظار ثبت قسط اول در لیست...");
    await waitForRowCount(1, 8000);
  }

  updateFloatingPanelStatus(`مرحله ۲ از ۲: در حال ثبت ${payload.remainingCount} قسط بعدی...`);

  installTabBtn.click();
  await sleep(250);

  setInputValue(document.getElementById("tedadAghsaat"), payload.remainingCount);
  setInputValue(document.getElementById("moddatAghsaat"), payload.moddatMonths);
  setJalaliDateOnForm(
    "editform:ghest_tab_sanadtaahodavar:tarikhSarresidAghsat",
    payload.secondDueDate
  );
  setInputValue(document.getElementById("mablaghAghsat"), payload.eachInstallment);

  await selectBankAccountOnForm(
    "editform:ghest_tab_sanadtaahodavar:hesabBimehAghsat",
    payload.bankAccount,
    true
  );
  await sleep(250);

  sessionStorage.removeItem("mamoot_pending_step2");
  sessionStorage.setItem("mamoot_just_finished_all", "true");

  const saveInstallBtn = document.getElementById("editform:ghest_tab_sanadtaahodavar:aghsaat_savebutton");
  if (payload.autoPro && saveInstallBtn) armFinalSubmit(payload.schedule && payload.schedule.items ? payload.schedule.items.length : payload.remainingCount + 1);
  if (saveInstallBtn) saveInstallBtn.click();

  setTimeout(() => finalizeAfterAdd(!!payload.autoPro), 800);

  return true;
}

// ---------- ۵. رابط کاربری پنل شناور پایین سمت چپ ----------
function detachOutsideClickListener() {
  if (outsideClickListener) {
    document.removeEventListener("mousedown", outsideClickListener, true);
    outsideClickListener = null;
  }
}

// حذف فوری پنل (بدون انیمیشن) — برای رندر مجدد داخل خود پنل
function removeFloatingPanelInstant() {
  detachOutsideClickListener();
  const existing = document.getElementById("mamoot-installment-panel-host");
  if (existing) existing.remove();
}

// بستن پنل با انیمیشن خروج
function removeFloatingPanel() {
  detachOutsideClickListener();
  const existing = document.getElementById("mamoot-installment-panel-host");
  if (!existing) return;
  if (existing.dataset.leaving === "1") return;
  existing.dataset.leaving = "1";
  const panel = existing.shadowRoot ? existing.shadowRoot.getElementById("mainPanel") : null;
  if (panel) panel.classList.add("leaving");
  setTimeout(() => {
    if (existing.dataset.leaving === "1") existing.remove();
  }, 380);
}

function closePanelByUser() {
  userDismissedForCurrentZeroState = true;
  removeFloatingPanel();
}

// ---------- پاپ‌آپ پیام پایین وسط صفحه (با انیمیشن، ناپدید شدن بعد از ۵ ثانیه) ----------
function removeToast() {
  if (toastHideTimer) {
    clearTimeout(toastHideTimer);
    toastHideTimer = null;
  }
  const existing = document.getElementById("mamoot-toast-host");
  if (existing) existing.remove();
}

function showToast(message, kind = "error", durationMs = 5000) {
  removeToast();
  if (!document.body) return;

  const host = document.createElement("div");
  host.id = "mamoot-toast-host";
  document.body.appendChild(host);
  const shadow = host.attachShadow({ mode: "open" });
  const isError = kind === "error";

  shadow.innerHTML = `
    <style>
      @font-face { font-family: 'vazir'; src: url('${chrome.runtime.getURL("fonts/Vazir-Regular.woff2")}') format('woff2'); font-weight: normal; }
      @font-face { font-family: 'vazir'; src: url('${chrome.runtime.getURL("fonts/Vazir-Bold.woff2")}') format('woff2'); font-weight: bold; }
      @font-face { font-family: 'vazir'; src: url('${chrome.runtime.getURL("fonts/Vazir-Black.woff2")}') format('woff2'); font-weight: 900; }
      :host { all: initial; }
      .toast {
        position: fixed;
        bottom: 28px;
        left: 50%;
        z-index: 2147483647;
        direction: rtl;
        font-family: 'vazir', Tahoma, sans-serif;
        font-size: 13.5px;
        font-weight: bold;
        color: #ffffff;
        background: ${isError ? "linear-gradient(135deg, #b91c1c, #ef4444)" : "linear-gradient(135deg, #1e3a8a, #2563eb 60%, #0369a1)"};
        border-radius: 16px;
        padding: 13px 20px 16px;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
        max-width: 90vw;
        width: max-content;
        overflow: hidden;
        display: flex;
        align-items: center;
        gap: 10px;
        transform: translateX(-50%) translateY(40px);
        opacity: 0;
        animation: toastIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards;
      }
      .toast.out { animation: toastOut 0.45s ease-in forwards; }
      .bar {
        position: absolute;
        bottom: 0;
        right: 0;
        height: 3px;
        width: 100%;
        background: rgba(255, 255, 255, 0.75);
        transform-origin: right;
        animation: toastBar ${durationMs}ms linear forwards;
      }
      @keyframes toastIn {
        from { opacity: 0; transform: translateX(-50%) translateY(40px); }
        to { opacity: 1; transform: translateX(-50%) translateY(0); }
      }
      @keyframes toastOut {
        from { opacity: 1; transform: translateX(-50%) translateY(0); }
        to { opacity: 0; transform: translateX(-50%) translateY(40px); }
      }
      @keyframes toastBar {
        from { transform: scaleX(1); }
        to { transform: scaleX(0); }
      }
    </style>
    <div class="toast" id="toastBox">
      <span>${isError ? "⚠️" : "ℹ️"}</span>
      <span id="toastMsg"></span>
      <div class="bar"></div>
    </div>
  `;
  shadow.getElementById("toastMsg").textContent = message;

  toastHideTimer = setTimeout(() => {
    const box = shadow.getElementById("toastBox");
    if (box) box.classList.add("out");
    toastHideTimer = setTimeout(() => {
      host.remove();
      toastHideTimer = null;
    }, 450);
  }, durationMs);
}

function updateFloatingPanelStatus(text) {
  const host = document.getElementById("mamoot-installment-panel-host");
  if (!host || !host.shadowRoot) return;
  const badge = host.shadowRoot.getElementById("statusBadge");
  if (badge) badge.textContent = text;
}

function renderFloatingPanel(options) {
  // انیمیشن ورود فقط وقتی پنل از قبل باز نبوده اجرا می‌شود (نه در رندر مجدد محتوا)
  const alreadyOpen = !!document.getElementById("mamoot-installment-panel-host") &&
    document.getElementById("mamoot-installment-panel-host").dataset.leaving !== "1";
  removeFloatingPanelInstant();
  if (!document.body) return;

  const host = document.createElement("div");
  host.id = "mamoot-installment-panel-host";
  document.body.appendChild(host);
  const shadow = host.attachShadow({ mode: "open" });

  shadow.innerHTML = `
    <style>
      @font-face { font-family: 'vazir'; src: url('${chrome.runtime.getURL("fonts/Vazir-Regular.woff2")}') format('woff2'); font-weight: normal; }
      @font-face { font-family: 'vazir'; src: url('${chrome.runtime.getURL("fonts/Vazir-Bold.woff2")}') format('woff2'); font-weight: bold; }
      @font-face { font-family: 'vazir'; src: url('${chrome.runtime.getURL("fonts/Vazir-Black.woff2")}') format('woff2'); font-weight: 900; }

      :host { all: initial; }

      .panel {
        position: fixed;
        bottom: 20px;
        left: 20px;
        z-index: 2147483647;
        width: 355px;
        direction: rtl;
        font-family: 'vazir', Tahoma, sans-serif;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 22px;
        box-shadow: 0 24px 48px -18px rgba(15, 23, 42, 0.45);
        overflow: hidden;
        color: #1e293b;
        font-size: 12.5px;
      }

      .panel.enter {
        animation: panelIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
      }

      .panel.leaving {
        animation: panelOut 0.35s ease-in forwards;
        pointer-events: none;
      }

      @keyframes panelIn {
        from { opacity: 0; transform: translateY(40px) scale(0.94); }
        to { opacity: 1; transform: translateY(0) scale(1); }
      }

      @keyframes panelOut {
        from { opacity: 1; transform: translateY(0) scale(1); }
        to { opacity: 0; transform: translateY(40px) scale(0.94); }
      }

      .panel-header {
        background: radial-gradient(circle at 85% 0%, rgba(250,204,21,.28), transparent 42%), linear-gradient(125deg, #0f172a, #1e3a8a 55%, #0369a1);
        color: #ffffff;
        padding: 13px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 900;
        font-size: 14px;
      }

      .close-btn {
        cursor: pointer;
        border: none;
        background: rgba(255, 255, 255, 0.2);
        color: #ffffff;
        width: 26px;
        height: 26px;
        border-radius: 9px;
        font-size: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
      }

      .close-btn:hover {
        background: rgba(239, 68, 68, 0.85);
      }

      .panel-body {
        padding: 14px;
      }

      .summary-bar {
        display: flex;
        justify-content: space-between;
        background: linear-gradient(135deg, #eff6ff, #f8fafc);
        border: 1px solid #dbeafe;
        padding: 9px 11px;
        border-radius: 12px;
        margin-bottom: 10px;
        font-size: 12px;
      }

      .summary-bar b {
        color: #1d4ed8;
      }

      .select-box {
        margin-bottom: 10px;
      }

      .select-box label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: #334155;
      }

      select {
        font-family: inherit;
        width: 100%;
        padding: 8px 10px;
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        font-size: 13px;
        background: #f8fafc;
        color: #0f172a;
        cursor: pointer;
        outline: none;
      }

      .basis-title {
        font-weight: bold;
        color: #334155;
        margin-bottom: 6px;
      }

      .basis-btns {
        display: flex;
        gap: 8px;
        margin-bottom: 4px;
      }

      .basis-btn {
        flex: 1;
        font-family: inherit;
        font-size: 12px;
        padding: 8px 6px;
        border: 1px solid #bfdbfe;
        border-radius: 12px;
        background: #eff6ff;
        color: #0f172a;
        cursor: pointer;
        line-height: 1.8;
        transition: background 0.2s, color 0.2s;
      }

      .basis-btn:hover {
        background: linear-gradient(135deg, #2563eb, #4f46e5);
        color: #ffffff;
      }

      .status-badge {
        background: #ecfdf5;
        color: #047857;
        border: 1px solid #a7f3d0;
        padding: 8px 10px;
        border-radius: 12px;
        font-weight: bold;
        text-align: center;
        margin-bottom: 10px;
        font-size: 12px;
      }

      .preview-table-wrap {
        max-height: 185px;
        overflow-y: auto;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
      }

      table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
      }

      th {
        background: #f8fafc;
        color: #475569;
        padding: 6px 8px;
        border-bottom: 1px solid #e2e8f0;
        position: sticky;
        top: 0;
      }

      td {
        padding: 6px 8px;
        border-bottom: 1px solid #f1f5f9;
        text-align: center;
      }

      tr:first-child td {
        font-weight: bold;
        background: #f0f9ff;
        color: #0369a1;
      }

      .footer {
        margin-top: 10px;
        text-align: center;
        font-size: 10px;
        color: #94a3b8;
        opacity: 0.75;
      }
    </style>

    <div class="panel" id="mainPanel">
      <div class="panel-header">
        <span>دستیار اقساط فناپ - بیمه باما</span>
        <button class="close-btn" id="closeBtn" title="بستن پنل">×</button>
      </div>
      <div class="panel-body" id="panelBody"></div>
    </div>
  `;

  const panelBody = shadow.getElementById("panelBody");
  if (!alreadyOpen) shadow.getElementById("mainPanel").classList.add("enter");

  // بستن پنل با کلیک روی دکمه ضربدر
  shadow.getElementById("closeBtn").addEventListener("click", (e) => {
    e.stopPropagation();
    closePanelByUser();
  });

  function renderScheduleTable(schedule, contractName, statusText) {
    const rowsHtml = schedule.items
      .map(
        (item) => `
        <tr>
          <td>${item.title}</td>
          <td dir="ltr">${formatNumber(item.amount)}</td>
          <td dir="ltr">${formatJalali(item.dueDate)}</td>
        </tr>
      `
      )
      .join("");

    panelBody.innerHTML = `
      <div class="summary-bar">
        <span>مانده: <b dir="ltr">${formatNumber(schedule.totalAmount)}</b></span>
        <span>${schedule.basis === "today" ? "تاریخ روز" : "صدور"}: <b dir="ltr">${formatJalali(schedule.baseDate || schedule.issueDate)}</b></span>
      </div>
      <div class="status-badge" id="statusBadge">${statusText || `در حال اجرای «${contractName}»...`}</div>
      <div class="preview-table-wrap">
        <table>
          <thead>
            <tr>
              <th>ردیف</th>
              <th>مبلغ (ریال)</th>
              <th>تاریخ سررسید</th>
            </tr>
          </thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>
      <div class="footer">Created By: MMBehzadi</div>
    `;
  }

  if (options.mode === "preview_only") {
    renderScheduleTable(options.schedule, options.contractName, options.statusText);
    return;
  }

  // حالت انتخاب قرارداد (وقتی حالت اتوماتیک خاموش است)
  const { contracts, defaultContractId, totalAmount, issueDate } = options;
  const optionsHtml = [
    `<option value="" disabled selected>-- یک قرارداد را جهت شروع انتخاب کنید --</option>`,
    ...contracts.map(
      (c) => `<option value="${c.id}">${c.name} ${c.id === defaultContractId ? "(پیش‌فرض)" : ""}</option>`
    )
  ].join("");

  panelBody.innerHTML = `
    <div class="summary-bar">
      <span>مانده: <b dir="ltr">${formatNumber(totalAmount)}</b></span>
      <span>تاریخ صدور: <b dir="ltr">${formatJalali(issueDate)}</b></span>
    </div>
    <div class="select-box">
      <label>انتخاب قرارداد (شروع خودکار بلافاصله پس از انتخاب):</label>
      <select id="contractSelector">${optionsHtml}</select>
    </div>
    <div id="basisBox"></div>
    <div class="footer">Created By: MMBehzadi</div>
  `;

  const selector = shadow.getElementById("contractSelector");

  // بستن خودکار پنل در صورت کلیک در هر جای صفحه بیرون از پنل
  setTimeout(() => {
    outsideClickListener = (event) => {
      const path = event.composedPath ? event.composedPath() : [];
      if (!path.includes(host)) {
        closePanelByUser();
      }
    };
    document.addEventListener("mousedown", outsideClickListener, true);
  }, 150);

  selector.addEventListener("change", () => {
    const chosenId = selector.value;
    const chosenContract = contracts.find((c) => c.id === chosenId);
    if (!chosenContract) return;

    const startWith = (basis) => {
      const schedule = buildSchedule(totalAmount, issueDate, chosenContract, basis);
      renderScheduleTable(schedule, chosenContract.name);
      executeContractAutomation(chosenContract, schedule);
    };

    // قرارداد «هر دو»: کاربر مبنای تاریخ را انتخاب می‌کند و بعد شروع می‌شود
    const basisBox = shadow.getElementById("basisBox");
    if (chosenContract.dateBasis === "both" && !chosenContract.isCash) {
      const todayStr = formatJalali(getTodayJalali());
      basisBox.innerHTML = `
        <div class="basis-title">مبنای محاسبه سررسیدها را انتخاب کنید:</div>
        <div class="basis-btns">
          <button class="basis-btn" id="basisIssue">بر اساس تاریخ صدور<br><b dir="ltr">${formatJalali(issueDate)}</b></button>
          <button class="basis-btn" id="basisToday">بر اساس تاریخ روز<br><b dir="ltr">${todayStr}</b></button>
        </div>`;
      shadow.getElementById("basisIssue").addEventListener("click", () => startWith("issue"));
      shadow.getElementById("basisToday").addEventListener("click", () => startWith("today"));
      return;
    }
    basisBox.innerHTML = "";
    startWith(chosenContract.dateBasis === "today" ? "today" : "issue");
  });
}

// ---------- ۶. پایش هوشمند صفحه پرداخت و تایمر ۳ ثانیه‌ای ----------
function isInsidePaymentPage() {
  return !!document.getElementById("totalMaandehGB") && !!document.getElementById("editform_tabbedPane");
}

function stopPaymentPolling() {
  if (paymentPollingInterval) {
    clearInterval(paymentPollingInterval);
    paymentPollingInterval = null;
  }
}

function ensurePaymentPolling() {
  if (!paymentPollingInterval) {
    paymentPollingInterval = setInterval(() => {
      evaluatePaymentPage(null);
    }, 3000);
  }
}

// ترتیب بررسی‌ها:
// ۱) آیا داخل صفحه پرداخت هستیم؟  ۲) آیا «جمع ریالی مستندات جدید و حواله های موجود» صفر است؟
//   - صفر: حالت دستی ← پنل انتخاب قرارداد | حالت اتوماتیک ← شروع با قرارداد پیش‌فرض
//   - غیر صفر: حالت دستی ← پنل نمی‌آید | حالت اتوماتیک ← پاپ‌آپ خطا (۵ ثانیه)
// تا زمانی که داخل صفحه پرداخت هستیم هر ۳ ثانیه دوباره بررسی می‌شود.
// وقتی افزونه Reload/آپدیت می‌شود، اسکریپت قدیمی داخل صفحه‌های باز «یتیم» می‌شود و خطای
// Extension context invalidated می‌دهد. در این حالت همه‌چیز را متوقف می‌کنیم (صفحه باید رفرش شود).
function isExtensionAlive() {
  try {
    return !!(chrome.runtime && chrome.runtime.id);
  } catch (e) {
    return false;
  }
}

function shutdownOrphanedScript() {
  stopPaymentPolling();
  try { observer.disconnect(); } catch (e) {}
  if (scheduledEvaluateTimer) {
    clearTimeout(scheduledEvaluateTimer);
    scheduledEvaluateTimer = null;
  }
  removeFloatingPanelInstant();
  const t = document.getElementById("mamoot-toast-host");
  if (t) t.remove();
}

async function evaluatePaymentPage(forceContractId = null) {
  if (!isExtensionAlive()) {
    shutdownOrphanedScript();
    return;
  }
  if (!document.body) return;

  if (handleLovPopupWindow()) return;

  // اگر از صفحه پرداخت خارج شده‌ایم، تایمر ۳ ثانیه‌ای را متوقف کن
  if (!isInsidePaymentPage()) {
    stopPaymentPolling();
    hasTriggeredForCurrentZeroState = false;
    userDismissedForCurrentZeroState = false;
    nonZeroErrorShown = false;
    removeFloatingPanel();
    removeToast();
    return;
  }

  // داخل صفحه پرداخت هستیم؛ فعال‌سازی چک ۳ ثانیه‌ای
  ensurePaymentPolling();

  // جلوگیری از اجرای هم‌زمان چند بررسی (باعث دوبار اجرا شدن اتوماتیک می‌شد)
  if (isEvaluating) return;
  isEvaluating = true;
  try {
    await runPaymentEvaluation(forceContractId);
  } catch (e) {
    if (!isExtensionAlive() || /context invalidated/i.test(String(e && e.message))) {
      shutdownOrphanedScript();
    } else {
      console.warn("[Mamoot] خطا در بررسی صفحه پرداخت:", e);
    }
  } finally {
    isEvaluating = false;
  }
}

let runningSince = 0;
async function runPaymentEvaluation(forceContractId) {
  // اگر قفل اجرا بیش از ۹۰ ثانیه گیر کرده باشد (عامل «باز نشدن» افزونه)، آزاد می‌شود
  if (isRunningAutomation) {
    if (!runningSince) runningSince = Date.now();
    if (Date.now() - runningSince > 90000 && !finalWatcherPromise && !window.__mamootStep2Running) {
      isRunningAutomation = false;
      runningSince = 0;
    }
  } else {
    runningSince = 0;
  }
  if (isRunningAutomation && !forceContractId) return;

  // اولویت اول: اگر مرحله دوم اقساط از رفرش قبلی منتظر است، فوراً اجرا شود
  const step2Ran = await checkAndRunPendingStep2();
  if (step2Ran) return;

  // حالت اتوماتیک پرو: بعد از رفرش صفحه، تا ظاهر شدن همه ردیف‌ها صبر کن و سپس «ثبت» را بزن
  if (readFinalSubmitState()) {
    watchFinalSubmit();
    return;
  }

  // اگر تمام مراحل به تازگی تمام شده، پنل را ببند
  if (sessionStorage.getItem("mamoot_just_finished_all") === "true") {
    sessionStorage.removeItem("mamoot_just_finished_all");
    removeFloatingPanel();
    isRunningAutomation = false;
  }

  const data = await chrome.storage.local.get([
    "contracts",
    "defaultContractId",
    "autoMode",
    "isBlocked",
    "userName",
    "autoPro",
    "dateBasisChoice"
  ]);

  // کاربر ثبت‌نام نکرده (یا از سرور حذف شده) است: ابتدا باید ثبت‌نام کند
  if (!data.userName) {
    removeFloatingPanel();
    if (!unregisteredToastShown) {
      unregisteredToastShown = true;
      showToast("برای استفاده از افزونه روی آیکونِ آن بزنید و با نام‌کاربری‌ای که مدیر در پنل برایتان تعریف کرده وارد شوید.", "info", 6000);
    }
    return;
  }
  unregisteredToastShown = false;

  if (data.isBlocked) return;

  // بررسی عدد جلوی «جمع ريالي مستندات جديد و حواله هاي موجود»
  const newDocsTotal = getNewDocumentsTotalAmount();

  if (!forceContractId) {
    if (newDocsTotal !== 0) {
      // غیر صفر: پنل نیاید و اگر باز است بسته شود؛ منتظر چک ۳ ثانیه بعدی بمان
      hasTriggeredForCurrentZeroState = false;
      userDismissedForCurrentZeroState = false;
      removeFloatingPanel();

      // حالت اتوماتیک: یک‌بار پاپ‌آپ خطا (مگر اینکه همین الان خود افزونه ردیف‌ها را ثبت کرده باشد)
      if (data.autoMode && !nonZeroErrorShown && !isErrorSuppressed()) {
        nonZeroErrorShown = true;
        showToast(NONZERO_ERROR_TEXT, "error", 5000);
      }
      return;
    }

    // صفر شد: وضعیت خطا ریست می‌شود
    nonZeroErrorShown = false;
    if (!hasTriggeredForCurrentZeroState) {
      try { sessionStorage.removeItem(SUPPRESS_ERROR_KEY); } catch (e) {}
    }
    removeToast();

    // اگر کاربر پنل را دستی بسته یا قبلاً در این وضعیت صفر اجرا شده، مجدد باز نکن
    if (hasTriggeredForCurrentZeroState || userDismissedForCurrentZeroState) return;
  }

  const targetSpan = document.getElementById("totalMaandehGB");
  const totalAmount = targetSpan ? parseNumber(targetSpan.innerText) : null;
  if (!totalAmount || totalAmount <= 0) return;

  hasTriggeredForCurrentZeroState = true;
  const issueDate = extractIssueDateFromPage();

  const contracts =
    Array.isArray(data.contracts) && data.contracts.length > 0
      ? data.contracts
      : FALLBACK_CONTRACTS;

  // اولویت: انتخابِ دستی ← قراردادِ ردیفِ سایت (شرکتِ همین پیش‌نویس در پنل) ← قراردادِ پیش‌فرضِ کاربر
  const siteContract = forceContractId ? null : await siteContractForPage();
  const defaultContractId =
    forceContractId || (siteContract && contracts.some((c) => c.id === siteContract) ? siteContract : "") || data.defaultContractId || (contracts[0] ? contracts[0].id : "");
  const autoMode = forceContractId ? true : !!data.autoMode;

  if (autoMode) {
    const activeContract = contracts.find((c) => c.id === defaultContractId) || contracts[0];
    const basis = resolveDateBasis(activeContract, data.dateBasisChoice);
    const schedule = buildSchedule(totalAmount, issueDate, activeContract, basis);
    renderFloatingPanel({
      mode: "preview_only",
      contractName: activeContract.name,
      schedule
    });
    executeContractAutomation(activeContract, schedule, { autoPro: !!data.autoPro });
  } else {
    renderFloatingPanel({
      mode: "select_contract",
      contracts,
      defaultContractId,
      totalAmount,
      issueDate
    });
  }
}

// بررسی با تأخیر کوتاه و بدون تکرار (برای تغییرات پی‌درپی صفحه)
function scheduleEvaluate(delay = 150) {
  if (scheduledEvaluateTimer) return;
  scheduledEvaluateTimer = setTimeout(() => {
    scheduledEvaluateTimer = null;
    evaluatePaymentPage(null);
  }, delay);
}

// دستور اجرای دستی از داخل پاپ‌آپ افزونه
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  // پاسخ به پاپ‌آپ: آیا این تب/فریم در صفحه پرداخت حق بیمه است؟ (فقط فریم صفحه پرداخت پاسخ می‌دهد)
  if (request.action === "query_payment_page") {
    if (isInsidePaymentPage()) sendResponse({ inPayment: true });
    return false;
  }
  if (request.action === "manual_trigger") {
    sessionStorage.removeItem("mamoot_just_finished_all");
    hasTriggeredForCurrentZeroState = false;
    userDismissedForCurrentZeroState = false;
    isRunningAutomation = false;
    evaluatePaymentPage(request.contractId);
  }
});

// گوش دادن به تغییرات تنظیمات (مثلاً وقتی کاربر دکمه اتوماتیک را در پاپ‌آپ روشن می‌کند)
chrome.storage.onChanged.addListener((changes, area) => {
  if (area === "local" && changes.autoMode && changes.autoMode.newValue === true) {
    if (isInsidePaymentPage() && !isRunningAutomation) {
      nonZeroErrorShown = false;
      if (getNewDocumentsTotalAmount() === 0) {
        hasTriggeredForCurrentZeroState = false;
        userDismissedForCurrentZeroState = false;
      } else {
        try { sessionStorage.removeItem(SUPPRESS_ERROR_KEY); } catch (e) {}
      }
      evaluatePaymentPage(null);
    }
  }
});

// رصد تغییرات صفحه برای تشخیص ورود یا خروج از صفحه پرداخت
const observer = new MutationObserver(() => {
  if (isInsidePaymentPage()) {
    ensurePaymentPolling();
    if (!hasTriggeredForCurrentZeroState && !userDismissedForCurrentZeroState && !isRunningAutomation) {
      scheduleEvaluate();
    }
  } else {
    stopPaymentPolling();
  }
});

function start() {
  if (!document.body) return;
  if (handleLovPopupWindow()) return;
  observer.observe(document.body, { childList: true, subtree: true });
  // بلافاصله با باز شدن صفحه پرداخت بررسی انجام می‌شود
  setTimeout(() => evaluatePaymentPage(null), 100);
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", start);
} else {
  start();
}
})();
