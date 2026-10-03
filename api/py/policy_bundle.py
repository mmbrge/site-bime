# -*- coding: utf-8 -*-
# فایل: api/py/policy_bundle.py
# «صدور گروهی»: فایلِ خروجیِ سایتِ بیمه‌گر (چند بیمه‌نامه پشتِ سرِ هم؛ هر بیمه‌نامه چند صفحه: خودِ بیمه‌نامه و
# شرایط، اعلامیه‌ی حق بیمه و فیش‌ها) را تکه‌تکه و تک‌تکِ بیمه‌نامه‌ها را شناسایی می‌کند.
#   جدا کردن (به ترتیبِ اولویت):
#     ۱) شمارنده‌ی پاورقیِ هر صفحه («۱ از ۱۷»): صفحه‌ای که شماره‌اش ۱ است، شروعِ بیمه‌نامه‌ی بعدی است
#     ۲) روشِ برنامه‌ی ویندوزیِ قبلی (context_processor.py): شباهتِ کلماتِ صفحه با صفحه‌ی اول بیش از ۰٫۲۵
#   شناسایی: خواندنِ «برچسب ← مقدار» از روی جای متن در صفحه (همان ردیفِ برچسب)، برای پلاک، بیمه‌گذار، کد ملی،
#     حق بیمه، شماره بیمه‌نامه، شاسی، موتور، خودرو، تاریخ‌ها و ارزش؛ هر فیلدی که این‌طور پیدا نشد با قواعدِ
#     متنیِ همان برنامه‌ی قبلی (extract_insurance_data) پر می‌شود
# اجرا:  python3 policy_bundle.py <input.pdf> <out_dir>
# خروجی (آخرین خطِ stdout، JSON):
#   {"ok": true, "pages": N, "text_pages": k, "split": "footer|similarity|scan",
#    "policies": [{"page": i, "end": j, "policy_end": k, "has_text": bool, "file": "pol_001.pdf", "seg_file": "seg_001.pdf",
#                  "similarity": 0.8, "data": {...}, "missing": [...]}]}
import sys
import os
import re
import json
import unicodedata


def out(obj):
    sys.stdout.write(json.dumps(obj, ensure_ascii=False) + "\n")
    sys.stdout.flush()


def load_fitz():
    try:
        import pymupdf as fitz  # نسخه‌های تازه
        return fitz
    except Exception:
        pass
    try:
        import fitz  # PyMuPDF
        return fitz
    except Exception:
        return None


def normalize_text(text):
    if not text:
        return ""
    text = unicodedata.normalize("NFKC", text)
    text = text.replace('ي', 'ی').replace('ك', 'ک').replace('‌', ' ')
    text = text.replace('ـ', '')
    for p, e in zip("۰۱۲۳۴۵۶۷۸۹", "0123456789"):
        text = text.replace(p, e)
    for p, e in zip("٠١٢٣٤٥٦٧٨٩", "0123456789"):
        text = text.replace(p, e)
    return text


# ---- همان منطقِ context_processor.extract_insurance_data ----
def extract_insurance_data(raw_text):
    text_norm = normalize_text(raw_text)
    data = {"ins_company": "ناشناخته", "ins_type": "ناشناخته", "policy_num": "", "insured_name": "", "vin": "",
            "plate": "", "national_id": "", "personnel_id": "", "company_name": "", "premium": ""}

    if "شماره پرسنلی" in text_norm and "شاغل در" in text_norm:
        data["ins_type"] = "معرفی‌نامه"
        return data

    head_text = text_norm[:800]
    if "pasargadinsurance" in text_norm.lower() or "پاسارگاد" in head_text:
        data["ins_company"] = "پاسارگاد"
    elif "10103858742" in text_norm or "بیمه ایران" in head_text:
        data["ins_company"] = "ایران"

    if "ثالث" in text_norm or "ثالت" in text_norm or "تعهد جانی" in text_norm or "حوادث راننده" in text_norm:
        data["ins_type"] = "ثالث"
    elif "بدنه" in text_norm or "ارزش وسیله نقلیه" in text_norm:
        data["ins_type"] = "بدنه"

    policy_num = ""
    if data["ins_company"] == "ایران" and data["ins_type"] == "ثالث":
        m = re.search(r'(\d{4}/\d{1,2}/\d{1,2}/\d+/\d+/\d+)', text_norm)
        if m: policy_num = m.group(1)
    elif data["ins_company"] == "ایران" and data["ins_type"] == "بدنه":
        m = re.search(r'(\d{4}/\d+/\d+/\d+/\d+)', text_norm)
        if m: policy_num = m.group(1)
    elif data["ins_company"] == "پاسارگاد" and data["ins_type"] == "ثالث":
        m = re.search(r'([\d/]+-)\s*:\s*شماره\s*بیمه\s*نامه[\s\n]*([\d/]+)', text_norm)
        if m: policy_num = m.group(1) + m.group(2)
        if not policy_num:
            m = re.search(r'(\d+/\d+/\d+-\d+/\d+/\d+)', text_norm)
            if m: policy_num = m.group(1)
    elif data["ins_company"] == "پاسارگاد" and data["ins_type"] == "بدنه":
        m = re.search(r'(\d+/\d+/\d+-\d+/\d+/\d+)', text_norm)
        if m: policy_num = m.group(1)
    if not policy_num:
        m = re.search(r'شماره\s*بیمه\s*نامه\s*[:\n]*\s*([A-Za-z0-9/]+(?:-[A-Za-z0-9/]+)?)', text_norm)
        if m: policy_num = m.group(1)
    data["policy_num"] = policy_num.replace(' ', '')

    insured_name = ""
    if data["ins_company"] == "ایران" and data["ins_type"] == "ثالث":
        m = re.search(r'نام\s*([آ-یa-zA-Z\s]+?)\s*شخصیت', text_norm)
        if m: insured_name = m.group(1).strip()
    elif data["ins_company"] == "ایران" and data["ins_type"] == "بدنه":
        m = re.search(r'نام\s*بیمه\s*گ[ذز]ار\s*[:\n]+([آ-یa-zA-Z\s]+?)\s*(?:\n|\d{10,11}|کد)', text_norm)
        if m: insured_name = m.group(1).strip()
    elif data["ins_company"] == "پاسارگاد" and data["ins_type"] == "بدنه":
        m = re.search(r'(?:بیمه\s*گ[ذز]ار|شماره\s*بیمه\s*نامه)[\s\S]{1,150}?([آ-یa-zA-Z\s]+?)\s*\d{10,11}', text_norm)
        if m: insured_name = m.group(1).strip()
    elif data["ins_company"] == "پاسارگاد" and data["ins_type"] == "ثالث":
        m = re.search(r'بیمه\s*گ[ذز]ار\s*[:\n]*\s*([آ-یa-zA-Z0-9\s]+?)\s*(?:\n|کد\s*ملی|\d{10,11})', text_norm)
        if m: insured_name = m.group(1).strip()
    if not insured_name:
        m = re.search(r'نام\s*بیمه\s*گ[ذز]ار\s*[:\n]+([آ-یa-zA-Z\s]{3,40}?)\s*(?:\n|\d)', text_norm)
        if m: insured_name = m.group(1).strip()
    if insured_name:
        prev = ""
        while prev != insured_name:
            prev = insured_name
            insured_name = re.sub(r'^(?:جناب\s*آقا[یي]|سرکار\s*خانم|آقا[یي]\s*[/\\-]\s*خانم|آقا[یي]|خانم|شرکت|شماره\s*بیمه\s*نامه|بیمه\s*گ[ذز]ار|نام|جناب)\s*', '', insured_name).strip()
        insured_name = re.sub(r'\s+', ' ', insured_name).strip()
    data["insured_name"] = insured_name

    vin_match = re.search(r'(?:VIN|شماره\s*شاسی)\s*[:\n]*\s*([A-Za-z0-9]{5,17})', text_norm, re.IGNORECASE)
    if not vin_match:
        vin_match = re.search(r'([A-Za-z0-9]{5,17})\s*[:\n]*\s*(?:شماره\s*شاسی|VIN)', text_norm, re.IGNORECASE)
    if vin_match:
        data["vin"] = vin_match.group(1)
    else:
        backup = re.search(r'\b(?=[A-Za-z0-9]*[A-Za-z])(?=[A-Za-z0-9]*\d)[A-Za-z0-9]{17}\b', text_norm)
        if backup: data["vin"] = backup.group(0)

    if not (data["ins_company"] == "ایران" and data["ins_type"] == "ثالث"):
        pt = text_norm.replace('\n', ' ')
        m1 = re.search(r'(\d{2})\s*([آ-یa-zA-Z])\s*(\d{3})[^\d]{0,10}ایران\s*(\d{2})', pt)
        m2 = re.search(r'(\d{3})\s*([آ-یa-zA-Z])\s*(\d{2})[^\d]{0,10}ایران\s*(\d{2})', pt)
        m3 = re.search(r'(\d{2})[^\d]{0,10}ایران\s*(\d{2})\s*([آ-یa-zA-Z])\s*(\d{3})', pt)
        m4 = re.search(r'(\d{2})\s*ایران\s*(\d{2})\s*([آ-یa-zA-Z])\s*(\d{3})', pt)
        if m1: data["plate"] = f"{m1.group(4)}ایران {m1.group(3)} {m1.group(2)} {m1.group(1)}"
        elif m2: data["plate"] = f"{m2.group(4)}ایران {m2.group(1)} {m2.group(2)} {m2.group(3)}"
        elif m3: data["plate"] = f"{m3.group(1)}ایران {m3.group(4)} {m3.group(3)} {m3.group(2)}"
        elif m4: data["plate"] = f"{m4.group(1)}ایران {m4.group(4)} {m4.group(3)} {m4.group(2)}"
        else:
            # چیدمانِ برعکسِ بعضی PDFها: «12 ایران 345 ب 66» (کد شهر، ایران، سه رقم، حرف، دو رقم)
            m5 = re.search(r'(\d{2})\s*ایران\s*(\d{3})\s*([آ-یa-zA-Z])\s*(\d{2})(?!\d)', pt)
            if m5: data["plate"] = f"{m5.group(1)}ایران {m5.group(2)} {m5.group(3)} {m5.group(4)}"

    # حق بیمه (برای ساختِ اقساط): بزرگ‌ترین مبلغی که بلافاصله بعد از عبارت‌های «حق بیمه ...» آمده
    best = 0
    lbl = r'(?:حق\s*بیمه(?:\s*(?:قابل\s*پرداخت|کل|نهایی|پرداختی))?|جمع\s*کل|مبلغ\s*قابل\s*پرداخت)'
    # «حق بیمه ...: 12,500,000» یا (چیدمانِ برعکسِ PDF) «12,500,000 :حق بیمه ...»
    for pat in (lbl + r'[^\d\n]{0,25}([\d,]{5,})', r'([\d,]{5,})[^\S\n]*:?[^\S\n]*' + lbl):
        for m in re.finditer(pat, text_norm):
            try:
                v = int(m.group(1).replace(',', ''))
                if 10000 <= v <= 1000000000000 and v > best: best = v
            except Exception:
                pass
    if best: data["premium"] = str(best)
    return data


# =====================================================================
#  شناساییِ «برچسب ← مقدار» از روی جای متن در صفحه
#  در PDFهای بیمه‌گر برچسب و مقدار، تکه‌های جدایی‌اند که فقط در یک ردیف کنار هم نشسته‌اند؛ متنِ خامِ صفحه
#  ترتیبِ آن‌ها را به هم می‌ریزد. پس هر تکه با مختصاتش خوانده و مقدارِ هر برچسب از همان ردیف برداشته می‌شود.
# =====================================================================
PLATE_LETTERS = 'آابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهیالفژ'


def page_spans(page):
    spans = []
    try:
        d = page.get_text('dict')
    except Exception:
        return spans
    for b in d.get('blocks', []):
        for l in b.get('lines', []):
            for sp in l.get('spans', []):
                t = normalize_text(sp.get('text', '')).strip()
                if not t:
                    continue
                x0, y0, x1, y1 = sp['bbox']
                spans.append({'t': t, 'x0': x0, 'y0': y0, 'x1': x1, 'y1': y1, 'size': sp.get('size', 0)})
    return spans


def is_label(sp):
    return ':' in sp['t']


def same_row(a, b, ratio=0.4):
    top, bot = max(a['y0'], b['y0']), min(a['y1'], b['y1'])
    h = min(a['y1'] - a['y0'], b['y1'] - b['y0']) or 1
    return (bot - top) / h >= ratio


def find_labels(spans, pattern):
    rx = re.compile(pattern)
    return [sp for sp in spans if is_label(sp) and rx.search(sp['t'])]


def row_values(spans, label, left_only=False):
    """تکه‌های غیرِبرچسبِ همان ردیف (نزدیک‌ترین به برچسب اول)؛ left_only: فقط سمتِ چپِ برچسب (مقدارِ برچسب‌های تکی)"""
    out = []
    for sp in spans:
        if sp is label or not same_row(sp, label):
            continue
        if is_label(sp) and not re.search(r'\d{3}', sp['t']):
            continue
        if left_only and (sp['x0'] + sp['x1']) / 2 > label['x0'] + 2:
            continue
        out.append(sp)
    out.sort(key=lambda sp: -sp['x1'])
    return out


def inline_kv(spans, label):
    """برچسب و مقدار در یک تکه: «نوع:    سواری» یا «۱۴۰۲    :مدل» → مقدار"""
    rx1 = re.compile(r'^\s*(?:' + label + r')\s*:\s*(.+?)\s*$')
    rx2 = re.compile(r'^\s*(.+?)\s*:\s*(?:' + label + r')\s*$')
    for sp in spans:
        t = sp['t']
        if ':' not in t:
            continue
        m = rx1.match(t) or rx2.match(t)
        if m:
            v = m.group(1).strip(' -:')
            if v and ':' not in v and not re.fullmatch(r'[-\s.]*', v):
                return v
    return ''


def own_dates(label_span):
    """تاریخ‌های داخلِ خودِ تکه‌ی برچسب («۱۴۰۵/۰۶/۱۶ ۱۱:۲۸:۰۸ :تاریخ صدور»)"""
    return dates_in(label_span['t'])


DATE_LABEL_RX = re.compile(r'تاریخ|مدت\s*بیمه|سررسید|مورخ')


def row_dates(spans, label):
    """تاریخِ یک برچسب: اول داخلِ خودِ تکه، بعد تکه‌های همان ردیف که برچسبِ تاریخِ دیگری ندارند"""
    ds = own_dates(label)
    if ds:
        return ds
    out = []
    for v in row_values(spans, label):
        if ':' in v['t'] and DATE_LABEL_RX.search(v['t']):
            continue   # تاریخِ برچسبِ دیگری است (مثلاً «تاریخ انقضا» کنارِ «تاریخ صدور»)
        out += dates_in(v['t'])
    return out


def clean_label_text(t):
    # برچسب‌ها گاهی به مقدار چسبیده‌اند («:کد اقتصادی411111651948»)؛ متنِ برچسب‌ها برداشته می‌شود
    return re.sub(r'[^:]*:', ' ', t) if ':' in t else t


def money_values(text):
    vals = []
    for m in re.finditer(r'(?<![\d/])(\d{1,3}(?:[,٬.]\d{3})+|\d{5,})(?![\d/])', text):
        try:
            vals.append(int(re.sub(r'[,٬.]', '', m.group(1))))
        except Exception:
            pass
    return vals


# عدد به حروف («بیست و هشت میلیارد») → عدد؛ تعهداتِ بیمه‌نامه‌ی ثالث با حروف نوشته می‌شود
FA_NUM = {'صفر': 0, 'یک': 1, 'دو': 2, 'سه': 3, 'چهار': 4, 'پنج': 5, 'شش': 6, 'شیش': 6, 'هفت': 7, 'هشت': 8, 'نه': 9, 'ده': 10,
          'یازده': 11, 'دوازده': 12, 'سیزده': 13, 'چهارده': 14, 'پانزده': 15, 'پونزده': 15, 'شانزده': 16, 'هفده': 17, 'هجده': 18, 'هیجده': 18,
          'نوزده': 19, 'بیست': 20, 'سی': 30, 'چهل': 40, 'پنجاه': 50, 'شصت': 60, 'هفتاد': 70, 'هشتاد': 80, 'نود': 90,
          'صد': 100, 'یکصد': 100, 'دویست': 200, 'سیصد': 300, 'چهارصد': 400, 'پانصد': 500, 'ششصد': 600, 'هفتصد': 700, 'هشتصد': 800, 'نهصد': 900}
FA_SCALE = {'هزار': 10 ** 3, 'میلیون': 10 ** 6, 'ملیون': 10 ** 6, 'میلیارد': 10 ** 9, 'ملیارد': 10 ** 9, 'بیلیون': 10 ** 9}


def fa_words_to_int(text):
    toks = [t for t in re.split(r'[\s\u200c]+|(?<=\S)و(?=\s)', re.sub(r'ریال|تومان|حداکثر|تا', ' ', text or '')) if t and t != 'و']
    total, cur, seen = 0, 0, False
    for t in toks:
        if re.fullmatch(r'\d+', t):
            cur += int(t); seen = True
        elif t in FA_NUM:
            cur += FA_NUM[t]; seen = True
        elif t in FA_SCALE:
            total += (cur or 1) * FA_SCALE[t]; cur = 0; seen = True
        elif seen:
            break
    return total + cur if seen else 0


def amount_in(text):
    """مبلغ از یک تکه: اول رقمی («5,000,000,000»)، بعد به حروف"""
    vals = [v for v in money_values(text) if v >= 1000]
    return max(vals) if vals else fa_words_to_int(text)


def norm_phone(t):
    t = re.sub(r'[\s\-()]', '', t or '')
    m = re.search(r'(?<!\d)(0\d{7,10})(?!\d)', t)   # موبایل ۱۱ رقمی یا تلفنِ ثابت (گاهی بدونِ یک رقم چاپ شده)
    return m.group(1) if m else ''


DATE_RX = re.compile(r'(1[34]\d{2})/(\d{1,2})/(\d{1,2})')


def dates_in(text):
    return ['%s/%02d/%02d' % (m.group(1), int(m.group(2)), int(m.group(3))) for m in DATE_RX.finditer(text)]


def parse_plate(text):
    """پلاکِ ایران از یک تکه متن؛ خروجی به قالبِ سیستم: «کدشهرایران سه‌رقم حرف دورقم»"""
    t = text.replace('\u200e', '').replace('\u200f', '')
    L = r'([آ-یa-zA-Z]|الف)'
    pats = [
        (r'(\d{2})\s*ایران\s*(\d{2})\s*' + L + r'\s*(\d{3})', lambda m: (m.group(1), m.group(4), m.group(3), m.group(2))),
        (r'(\d{2})\s*' + L + r'\s*(\d{3})[^\d]{0,10}ایران\s*(\d{2})', lambda m: (m.group(4), m.group(3), m.group(2), m.group(1))),
        (r'(\d{3})\s*' + L + r'\s*(\d{2})[^\d]{0,10}ایران\s*(\d{2})', lambda m: (m.group(4), m.group(1), m.group(2), m.group(3))),
        (r'(\d{2})[^\d]{0,10}ایران\s*(\d{2})\s*' + L + r'\s*(\d{3})', lambda m: (m.group(1), m.group(4), m.group(3), m.group(2))),
        (r'(\d{2})\s*ایران\s*(\d{3})\s*' + L + r'\s*(\d{2})(?!\d)', lambda m: (m.group(1), m.group(2), m.group(3), m.group(4))),
    ]
    for rx, fn in pats:
        m = re.search(rx, t)
        if m:
            region, three, letter, two = fn(m)
            return '%sایران %s %s %s' % (region, three, letter, two)
    return ''


def footer_counter(spans, page_h):
    """شمارنده‌ی پاورقی «۱ از ۱۷» → (شماره‌ی صفحه، کلِ صفحه‌ها)"""
    for sp in spans:
        if sp['y0'] < page_h * 0.88:
            continue
        m = re.fullmatch(r'(\d{1,3})\s*از\s*(\d{1,3})', sp['t']) or re.fullmatch(r'صفحه\s*(\d{1,3})\s*از\s*(\d{1,3})', sp['t'])
        if m:
            a, b = int(m.group(1)), int(m.group(2))
            return (min(a, b), max(a, b))
    return None


PAYMENT_RX = re.compile(r'سند\s*دریافت|اعلامیه\s*آخرین\s*وضعیت\s*حق\s*بیمه|رسید\s*پرداخت|فیش\s*واریز|پرداخت\s*الکترونیک')


def is_payment_page(text):
    return bool(PAYMENT_RX.search(normalize_text(text)[:600]))


RECEIPT_RX = re.compile(r'سند\s*دریافت|رسید\s*پرداخت|فیش\s*واریز|پرداخت\s*الکترونیک')


def receipt_info(text):
    """صفحه‌ی فیشِ یک قسط («سند دریافت نقدی»): تاریخ، مبلغ و شماره‌ی فیش"""
    t = normalize_text(text)
    info = {}
    ds = dates_in(t)
    if ds:
        info['date'] = ds[0]
    amts = [int(m.replace(',', '').replace('٬', '')) for m in re.findall(r'(?<!\d)(\d{1,3}(?:[,٬]\d{3})+)(?!\d)', t)]
    amts = [a for a in amts if 1000 <= a <= 10 ** 12]
    if amts:
        info['amount'] = max(amts)
    m = re.search(r'شماره\s*فیش\s*:?\s*(\d{10,24})', t) or re.search(r'(\d{10,24})\s*:?\s*کد\s*شناسه', t) or re.search(r'(?<!\d)(0{2,}\d{12,20})(?!\d)', t)
    if m:
        info['fish'] = m.group(1)
    return info


def extract_layout(page):
    sp = page_spans(page)
    if not sp:
        return {}
    d = {}
    alltext = '\n'.join(s['t'] for s in sp)

    # بیمه‌گر و نوع: از سرتیترِ صفحه (درشت‌ترین متنِ بالای صفحه)
    if 'pasargad' in alltext.lower() or 'پاسارگاد' in alltext:
        d['ins_company'] = 'پاسارگاد'
    elif 'بیمه ایران' in alltext or 'iraninsurance' in alltext.lower():
        d['ins_company'] = 'ایران'
    heads = sorted([s for s in sp if s['y0'] < page.rect.height * 0.2 and 'بیمه' in s['t']], key=lambda s: -s['size'])
    for h in heads[:3]:
        if 'ثالث' in h['t']:
            d['ins_type'] = 'ثالث'; break
        if 'بدنه' in h['t']:
            d['ins_type'] = 'بدنه'; break

    # شماره بیمه‌نامه: قالبِ پاسارگاد «2/49357/5051-11/1405/512»، وگرنه مقدارِ ردیفِ برچسب (غیر از «سال قبل»)
    for s in sp:
        m = re.search(r'\d{1,2}/\d{4,6}/\d{3,5}-\d{1,3}/\d{4}/\d+', s['t'])
        if m:
            d['policy_num'] = m.group(0); break
    if not d.get('policy_num'):
        for lb in find_labels(sp, r'شماره\s*بیمه\s*نامه'):
            for v in row_values(sp, lb):
                if 'سال قبل' in v['t']:
                    continue
                m = re.search(r'[\dA-Za-z][\dA-Za-z/\-]{6,}', clean_label_text(v['t']))
                if m and not DATE_RX.fullmatch(m.group(0)):
                    d['policy_num'] = m.group(0); break
            if d.get('policy_num'):
                break

    # پلاک: اول ردیفِ «شماره پلاک»، بعد هر تکه‌ای که شکلِ پلاک دارد
    for lb in find_labels(sp, r'شماره\s*(?:پلاک|انتظامی)'):
        for v in row_values(sp, lb):
            pl = parse_plate(v['t'])
            if pl:
                d['plate'] = pl; break
        if d.get('plate'):
            break
    if not d.get('plate'):
        for s in sp:
            pl = parse_plate(s['t'])
            if pl:
                d['plate'] = pl; break

    # بیمه‌گذار + کد/شناسه‌ی ملی (در پاسارگاد هر دو در یک تکه کنار هم‌اند)
    for lb in find_labels(sp, r'^:?\s*(?:نام\s*)?بیمه\s*گ[ذز]ار\s*:?\s*$|^:?\s*(?:نام\s*)?بیمه\s*گ[ذز]ار\s*:'):
        parts = []
        for v in row_values(sp, lb):
            t = clean_label_text(v['t'])
            nid = re.search(r'(?<!\d)(\d{10,11})(?!\d)', t)
            if nid and not d.get('national_id'):
                d['national_id'] = nid.group(1)
            name = re.sub(r'[\d:/\-]+', ' ', t).strip()
            if name and re.search(r'[آ-ی]{2,}', name):
                parts.append(name)
            if parts:
                break
        if parts:
            d['insured_name'] = re.sub(r'\s+', ' ', parts[0]).strip()
            break
    if not d.get('national_id'):
        for lb in find_labels(sp, r'(?:کد|شناسه)\s*ملی'):
            for v in row_values(sp, lb):
                m = re.search(r'(?<!\d)(\d{10,11})(?!\d)', v['t'])
                if m:
                    d['national_id'] = m.group(1); break
            if d.get('national_id'):
                break

    # حق بیمه: «مبلغ قابل پرداخت» / «حق بیمه قابل پرداخت» / «جمع کل حق بیمه» / «جمع کل» (ثالث)
    for prx in [r'مبلغ\s*قابل\s*پرداخت', r'حق\s*بیمه\s*(?:قابل\s*پرداخت|نهایی)', r'جمع\s*(?:کل\s*)?حق\s*بیمه', r'^\s*:?\s*جمع\s*کل\s*:?\s*$', r'حق\s*بیمه\s*کل']:
        for lb in find_labels(sp, prx):
            vals = money_values(clean_label_text(lb['t'])) + [x for v in row_values(sp, lb) for x in money_values(v['t'])]
            vals = [x for x in vals if 10000 <= x <= 10 ** 13]
            if vals:
                d['premium'] = str(max(vals)); break
        if d.get('premium'):
            break

    # برچسب و مقدار در یک تکه (قالبِ ثالثِ پاسارگاد): «نوع: سواری»، «سیستم: سایپا»، «۱۴۰۲ :مدل»، «M155... :شماره موتور»
    for key, lab in [('car_kind', r'نوع'), ('car_system', r'سیستم'), ('car_tip', r'تیپ'), ('model_year', r'مدل'), ('color', r'رنگ'),
                     ('usage', r'مورد\s*استفاده|کاربری'), ('engine_no', r'شماره\s*موتور'), ('chassis', r'شماره\s*شاسی'),
                     ('capacity', r'ظرفیت'), ('cylinders', r'تعداد\s*سیلندر')]:
        v = inline_kv(sp, lab)
        if v:
            d[key] = v
    if d.get('chassis'):
        tok = re.sub(r'[^A-Za-z0-9]', '', d.pop('chassis'))
        if len(tok) == 17 and not d.get('vin'):
            d['vin'] = tok.upper()
        elif tok:
            d['chassis_no'] = tok.upper()
    if d.get('engine_no'):
        d['engine_no'] = re.sub(r'[^A-Za-z0-9]', '', d['engine_no']).upper() or d.pop('engine_no')
    if d.get('model_year'):
        m = re.search(r'(1[34]\d{2}|19\d{2}|20\d{2})', d['model_year'])
        if m: d['model_year'] = m.group(1)
        else: d.pop('model_year')
    inline_car = bool(d.get('car_system') or d.get('car_tip'))
    if inline_car:
        d['car_name'] = ' '.join(x for x in [d.get('car_system', ''), d.get('car_tip', '')] if x).strip()
    if not d.get('national_id'):
        m = re.search(r'(?<!\d)(\d{10})\s*:\s*کد\s*ملی', alltext)
        if m: d['national_id'] = m.group(1)

    # شاسی و موتور؛ برچسبِ دوتایی «شماره موتور: شماره شاسی» (بدنه) → مقدارِ اول موتور، دوم شاسی (از راست به چپ)
    for lb in find_labels(sp, r'شماره\s*موتور.*شماره\s*شاسی|شماره\s*شاسی.*شماره\s*موتور'):
        toks = [re.sub(r'[^A-Za-z0-9]', '', v['t']).upper() for v in row_values(sp, lb) if re.search(r'\d', v['t'])]
        toks = [t for t in toks if len(t) >= 4]
        first_engine = lb['t'].find('موتور') < lb['t'].find('شاسی')   # برچسبِ اولِ رشته = راست‌ترین = مقدارِ اول
        if len(toks) >= 2:
            eng, ch = (toks[0], toks[1]) if first_engine else (toks[1], toks[0])
            if not d.get('engine_no'): d['engine_no'] = eng
            if not d.get('vin') and not d.get('chassis_no'): d['vin'] = ch
        break
    for lb in find_labels(sp, r'شماره\s*(?:شاسی|موتور)|VIN'):
        for v in row_values(sp, lb):
            for tok in re.findall(r'[A-Za-z0-9]{6,20}', v['t']):
                if not re.search(r'\d', tok):
                    continue
                if len(tok) == 17 and re.search(r'[A-Za-z]', tok) and not d.get('vin'):
                    d['vin'] = tok.upper()
                elif not d.get('engine_no') and tok.upper() != d.get('vin'):
                    d['engine_no'] = tok.upper()
        if d.get('vin'):
            break

    # خودرو: «نوع: سیستم: تیپ» (از راست به چپ)
    for lb in ([] if inline_car else find_labels(sp, r'سیستم')):
        near = page.rect.width * 0.35   # فقط تکه‌های نزدیکِ برچسب؛ متنِ ستونِ دیگرِ صفحه مقدارِ سیستم نیست
        vals = [v['t'] for v in row_values(sp, lb) if not re.fullmatch(r'[-\s]*', v['t']) and abs(v['x1'] - lb['x0']) < near
                and not BAD_TEXT.search(v['t'])]
        if vals:
            if len(vals) >= 3:
                d['car_kind'], d['car_system'], d['car_tip'] = vals[0], vals[1], vals[2]
            elif len(vals) == 2:
                d['car_system'], d['car_tip'] = vals[0], vals[1]
            else:
                d['car_system'] = vals[0]
            d['car_name'] = ' '.join(x for x in [d.get('car_system', ''), d.get('car_tip', '')] if x).strip()
            break
    for lb in ([] if d.get('model_year') else find_labels(sp, r'^:?\s*مدل\s*:?$|:\s*مدل')):
        for v in row_values(sp, lb):
            m = re.search(r'(?<!\d)(1[34]\d{2}|19\d{2}|20\d{2})(?!\d)', v['t'])
            if m:
                d['model_year'] = m.group(1); break
        if d.get('model_year'):
            break
    for lb in ([] if d.get('color') else find_labels(sp, r'رنگ')):
        vals = row_values(sp, lb)
        if vals:
            d['color'] = vals[0]['t']
            if len(vals) > 1:
                d['usage'] = vals[1]['t']
        break

    # ارزشِ وسیله (بدنه) و تعهدِ مالی (ثالث)
    for lb in find_labels(sp, r'ارزش\s*وسیله\s*نقلیه'):
        vals = [x for v in row_values(sp, lb, left_only=True) for x in money_values(v['t'])]
        if vals:
            d['car_value'] = str(max(vals)); break
    for lb in find_labels(sp, r'تعهد(?:ات)?\s*مالی'):
        vals = [x for v in row_values(sp, lb) for x in money_values(v['t'])]
        if vals:
            d['liability'] = str(max(vals)); break

    # تاریخ‌ها: مدتِ بیمه (شروع/پایان)، صدور، انقضای بیمه‌نامه‌ی قبلی
    for lb in find_labels(sp, r'مدت\s*بیمه|تاریخ\s*(?:شروع|پایان)'):
        ds = sorted(set(x for v in row_values(sp, lb) for x in dates_in(v['t'])) | set(dates_in(lb['t'])))
        if len(ds) >= 2:
            d['start_date'], d['end_date'] = ds[0], ds[-1]; break
        if len(ds) == 1 and not d.get('start_date'):
            d['start_date'] = ds[0]
    for lb in find_labels(sp, r'تاریخ\s*صدور'):
        ds = row_dates(sp, lb)
        if ds:
            d['issue_date'] = ds[0]; break
    for lb in find_labels(sp, r'تاریخ\s*انقضا'):
        ds = row_dates(sp, lb)
        if ds:
            d['prev_expiry'] = ds[0]; break
    for lb in find_labels(sp, r'بیمه\s*نامه\s*سال\s*قبل'):
        own = re.sub(r':?\s*بیمه\s*نامه\s*سال\s*قبل\s*:?', ' ', lb['t']).strip()
        m = re.search(r'\d[\d/\-]{6,}\d', own)
        if m:
            d['prev_policy'] = m.group(0)
            ins = re.sub(r'[\d/\-]+', ' ', own).replace('سهامی', ' ').strip(' -:')
            if ins:
                d['prev_insurer'] = re.sub(r'\s+', ' ', ins)
            break
        for v in row_values(sp, lb):
            if ':' in v['t'] and re.search(r'شماره\s*بیمه\s*نامه|تاریخ|واحد', v['t']):
                continue
            t = v['t']
            if d.get('policy_num') and d['policy_num'] in t:
                continue
            m = re.search(r'\d[\d/\-]{6,}\d', t)
            if m:
                d['prev_policy'] = m.group(0)
                ins = re.sub(r'[\d/\-]+', ' ', t).strip(' -')
                if ins:
                    d['prev_insurer'] = re.sub(r'\s+', ' ', ins)
                break
        break
    for lb in find_labels(sp, r'شماره\s*بیمه\s*مرکزی|کد\s*یکتا'):
        m = re.search(r'\d{8,}', lb['t'])
        if m:
            d['central_no'] = m.group(0); break
        for v in row_values(sp, lb):
            m = re.search(r'\d{8,}', v['t'])
            if m:
                d['central_no'] = m.group(0); break
        break

    # برچسب‌های تکی با مقدارِ جدا در سمتِ چپ (بدنه): ظرفیت، تعداد سیلندر، اتاق بار
    for key, lab in [('capacity', r'^:?\s*ظرفیت\s*:?$'), ('cylinders', r'^:?\s*تعداد\s*سیلندر\s*:?$'), ('cargo', r'^:?\s*اتاق\s*بار\s*:?$')]:
        if d.get(key):
            continue
        for lb in find_labels(sp, lab):
            vals = [v for v in row_values(sp, lb, left_only=True) if not re.fullmatch(r'[-\s.]*', v['t'])]
            if vals:
                d[key] = vals[0]['t'].strip(); break
    if not d.get('cargo'):
        v = inline_kv(sp, r'اتاق\s*بار')
        if v: d['cargo'] = v
    if d.get('capacity'):
        m = re.fullmatch(r'\s*(تن|نفر|کیلوگرم)\s*(\d+(?:[.,]\d+)?)\s*', d['capacity'])
        if m: d['capacity'] = m.group(2) + ' ' + m.group(1)
    if d.get('cylinders'):
        m = re.search(r'\d+', d['cylinders'])
        if m: d['cylinders'] = m.group(0)
        else: d.pop('cylinders')

    # اطلاعاتِ بیمه‌گذار (تلفن، نشانی، کد پستی): فقط در بلوکِ زیرِ برچسبِ «بیمه‌گذار»؛ تلفن و نشانیِ شرکت/واحدِ صدور جدا است
    ins = [s for s in sp if re.search(r'بیمه\s*گ[ذز]ار\s*:|:\s*بیمه\s*گ[ذز]ار', s['t']) and not re.search(r'پیشنهاد', s['t'])]
    if ins:
        y0 = min(s['y0'] for s in ins)
        block = [s for s in sp if y0 - 4 <= s['y0'] <= y0 + 62 and not re.search(r'آدرس|واحد\s*صدور|دورنگار|ذینفع', s['t'])]
        bset = set(id(s) for s in block)
        for s in block:
            m = re.search(r'([0-9][0-9\-\s()]{7,}[0-9])\s*:\s*تلفن', s['t']) or re.search(r'تلفن\s*:\s*([0-9][0-9\-\s()]{7,}[0-9])', s['t'])
            if m and norm_phone(m.group(1)):
                d['phone'] = norm_phone(m.group(1)); break
        if not d.get('phone'):
            for lb in [s for s in block if re.fullmatch(r'\s*:?\s*تلفن\s*:?\s*', s['t'])]:
                for v in row_values(sp, lb):
                    if id(v) in bset and norm_phone(v['t']):
                        d['phone'] = norm_phone(v['t']); break
                if d.get('phone'): break
        for lb in [s for s in block if re.search(r'کد\s*پستی', s['t'])]:
            m = re.search(r'(?<!\d)(\d{10})(?!\d)', lb['t'])
            vals = [m.group(1)] if m else [re.search(r'(?<!\d)(\d{10})(?!\d)', v['t']).group(1) for v in row_values(sp, lb) if re.search(r'(?<!\d)\d{10}(?!\d)', v['t'])]
            if vals:
                d['postal_code'] = vals[0]; break
        for lb in [s for s in block if re.search(r'نشانی', s['t'])]:
            parts = []
            t = lb['t']
            if re.search(r'نشانی\s*:\s*\S', t):   # «...پلاک 5 واحد41نشانی: استان البرز ، کرج ...»
                before, after = re.split(r'نشانی\s*:', t, 1)
                parts = [after.strip(), before.strip()]
            else:
                row = [v for v in row_values(sp, lb, left_only=True) if re.search(r'[آ-ی]{3,}', v['t']) and ':' not in v['t']]
                if row:
                    first = row[0]
                    parts.append(first['t'])
                    for v in sp:   # ادامه‌ی نشانی در خطِ بعد، راست‌چین با خطِ اول
                        if v is not first and 4 < v['y0'] - first['y0'] < 20 and abs(v['x1'] - first['x1']) < 8 and ':' not in v['t'] \
                                and re.search(r'[آ-ی]', v['t']) and not norm_phone(v['t']):
                            parts.append(v['t'])
            def fix_line(line):
                # بخش‌های جداشده با «-» در PDF برعکس چیده می‌شوند («کوچه مفاخری- ... - استان تهران») و «12 پلاک» یعنی «پلاک 12»
                if '-' in line:
                    segs = [x.strip(' ،,') for x in line.split('-') if x.strip(' ،,')]
                    segs = [re.sub(r'^(\d+)\s+(پلاک|طبقه|واحد|کوچه|خیابان|بلوک|ورودی)$', r'\2 \1', x) for x in segs]
                    return '، '.join(reversed(segs))
                return line
            parts = [fix_line(p) for p in parts]
            addr = ' '.join(p for p in parts if p)
            m = re.match(r'^(.*?)\s*(استان\s.*)$', addr)
            if m and m.group(1).strip():
                addr = m.group(2) + ' ' + m.group(1)
            addr = re.sub(r'\s+', ' ', addr).strip(' ،,-')
            if len(addr) >= 8:
                d['address'] = addr; break

    # تعهداتِ بیمه‌گر (ثالث): مالی، بدنی (دیه) و حوادث راننده - رقمی یا به حروف
    def commit_amount(rx):
        for lb in [s for s in sp if re.search(rx, s['t'])]:
            vals = [amount_in(v['t']) for v in sp if v is not lb and v['x1'] < lb['x0'] + 2 and same_row(v, lb, 0.2) and not re.search(r'^\s*ریال\s*$', v['t'])]
            vals = [v for v in vals if v >= 10 ** 6]
            own = amount_in(re.sub(rx, ' ', lb['t']))
            if own >= 10 ** 6: vals.append(own)
            if vals:
                return str(max(vals))
        return ''
    if not d.get('liability'):
        d['liability'] = commit_amount(r'خسارت\s*مالی|تعهد(?:ات)?\s*مالی')
    d['diya'] = commit_amount(r'خسارت\s*بدنی|دیه')
    d['driver_cover'] = commit_amount(r'حوادث\s*راننده\s*مسبب')

    # تخفیفِ عدمِ خسارت (ثالث): «تخفیف ثالث 60 % سال8»
    disc = []
    for lb in [s for s in sp if re.fullmatch(r'\s*تخفیف\s*(ثالث|حوادث\s*راننده)\s*', s['t'])]:
        row = ' '.join(v['t'] for v in sp if v is not lb and same_row(v, lb, 0.3) and v['x1'] < lb['x0'] + 2 and v['x0'] > lb['x0'] - 160)
        pct = re.search(r'(\d{1,3})\s*%', row)
        yrs = re.search(r'سال\s*(\d{1,2})|(\d{1,2})\s*سال', row)
        if pct or yrs:
            name = re.sub(r'\s*تخفیف\s*', '', lb['t']).strip()
            y = (yrs.group(1) or yrs.group(2)) if yrs else ''
            disc.append(name + ': ' + (y + ' سال' if y else '') + (' (' + pct.group(1) + '%)' if pct else ''))
    if disc:
        d['ncd'] = '، '.join(disc)
    for lb in find_labels(sp, r'وضعیت\s*بیمه\s*نامه\s*قبلی'):
        st = [v['t'] for v in sp if re.search(r'خسارت', v['t']) and 0 <= v['y0'] - lb['y0'] < 50 and abs(v['x1'] - lb['x1']) < 10 and ':' not in v['t']]
        if st:
            d['prev_claims'] = '، '.join(st)
        break

    # بدنه: جمعِ ارزش (سرمایه‌ی بیمه)، ارزشِ یدک و وسایلِ اضافی، و فهرستِ پوشش‌ها
    for key, lab in [('total_value', r'^:?\s*جمع\s*:?$'), ('trailer_value', r'ارزش\s*یدک'), ('extras_value', r'ارزش\s*وسایل\s*اضافی')]:
        for lb in find_labels(sp, lab):
            vals = [x for v in row_values(sp, lb, left_only=True) for x in money_values(v['t'])]
            if vals and max(vals) > 0:
                d[key] = str(max(vals))
            break
    sh = [s for s in sp if re.search(r'شرح\s*\(?\s*حق\s*بیمه', s['t'])]
    if sh:
        y0 = sh[0]['y0']
        end = min([s['y0'] for s in sp if s['y0'] > y0 and re.search(r'مبلغ\s*قابل\s*پرداخت', s['t'])] or [y0 + 260]) - 3
        covers = []
        for s in sorted([s for s in sp if y0 < s['y0'] < end], key=lambda s: s['y0']):
            if re.search(r'تخفیف|تشدید|مالیات|عوارض|کسر|اضافه\s*هزار', s['t']):
                continue
            name = re.sub(r'-?\d{1,3}(?:[,٬.]\d{3})+|-?\d{4,}', ' ', s['t']).replace('ریال', ' ').replace('.', ' ')
            name = re.sub(r'%?\d+%?|[()]', ' ', name)          # درصد و پرانتزهای برعکس‌شده‌ی PDF
            name = re.sub(r'\s+', ' ', name).strip(' ،,-')
            name = re.sub(r'\s+تا$', '', name)
            if re.search(r'[آ-ی]{3,}', name) and name not in ('ریال',):
                covers.append(name)
        if covers:
            d['covers'] = '؛ '.join(covers)
    return d


# مقدارِ اشتباهی که از ردیفِ کناری برداشته شده (مثلاً «5051/30000/1404:. شماره قرارداد8-1» به‌جای سیستمِ خودرو) دور ریخته می‌شود
TEXT_FIELDS = ('car_kind', 'car_system', 'car_tip', 'car_name', 'color', 'usage', 'capacity', 'cargo', 'prev_insurer', 'insured_name')
BAD_TEXT = re.compile(r':|شماره|قرارداد|ریال|تعهد|خسارت|بیمه\s*نامه|مبلغ|حداکثر|\d+/\d+')


def clean_fields(d):
    for k in TEXT_FIELDS:
        v = (d.get(k) or '').strip()
        if not v:
            continue
        bad = len(v) > 45 or (k != 'insured_name' and BAD_TEXT.search(v)) or (k == 'insured_name' and re.search(r'\d{3}|:', v))
        if k == 'prev_insurer':
            bad = len(v) > 40 or ':' in v or re.search(r'\d', v)
        if bad:
            d[k] = ''
    pi = re.sub(r'\s+', ' ', (d.get('prev_insurer') or '').replace('شرکت', ' ')).strip(' -')
    if pi:
        # «سهامی بیمه» (بریده‌شده در PDF) و «سهامی بیمه ایران» همان بیمه ایران است
        if pi in ('سهامی بیمه', 'سهامی بیمه ایران', 'بیمه ایران سهامی'):
            pi = 'بیمه ایران'
        d['prev_insurer'] = re.sub(r'^سهامی\s+', '', pi).strip()
    if d.get('model_year') and not re.fullmatch(r'(1[34]\d{2}|19\d{2}|20\d{2})', str(d['model_year'])):
        d['model_year'] = ''
    if not d.get('car_name') or not d.get('car_system'):
        d['car_name'] = ' '.join(x for x in [d.get('car_system', ''), d.get('car_tip', '')] if x).strip() or d.get('car_name', '')
    for k in ('engine_no', 'vin', 'chassis_no'):
        if d.get(k) and not re.fullmatch(r'[A-Za-z0-9]{5,25}', str(d[k])):
            d[k] = re.sub(r'[^A-Za-z0-9]', '', str(d[k]))[:25]
    return d


CRITICAL = [('plate', 'پلاک'), ('insured_name', 'نام بیمه‌گذار'), ('premium', 'حق بیمه'), ('policy_num', 'شماره بیمه‌نامه')]


def extract_policy(page, raw_text):
    """شناسایی با جای متن + تکمیل با قواعدِ متنیِ برنامه‌ی قبلی"""
    base = extract_insurance_data(raw_text)
    try:
        lay = extract_layout(page)
    except Exception:
        lay = {}
    for k, v in lay.items():
        if v:   # خواندن با جای متن دقیق‌تر از قواعدِ متنی است
            base[k] = v
    if not base.get('premium'):
        # «ریال129,612,000» یا «129,612,000 ریال» کنارِ «قابل پرداخت»
        t = normalize_text(raw_text)
        m = re.search(r'قابل\s*پرداخت[\s\S]{0,200}?(?:ریال\s*([\d,]{6,})|([\d,]{6,})\s*ریال)', t)
        if m:
            base['premium'] = (m.group(1) or m.group(2)).replace(',', '')
    clean_fields(base)
    missing = [fa for k, fa in CRITICAL if not base.get(k)]
    if 'پلاک' in missing and base.get('vin'):
        missing.remove('پلاک')   # بدونِ پلاک (صفر/لیفتراک) با شماره‌ی شاسی شناخته می‌شود
    return base, missing


def jaccard(a, b):
    if not a or not b: return 0.0
    u = len(a | b)
    return len(a & b) / u if u else 0.0


def main():
    if len(sys.argv) < 3:
        out({"ok": False, "error": "usage"}); return
    src, outdir = sys.argv[1], sys.argv[2]
    fitz = load_fitz()
    if fitz is None:
        out({"ok": False, "error": "کتابخانه‌ی PyMuPDF (fitz) روی پایتونِ سرور نصب نیست."}); return
    try:
        doc = fitz.open(src)
    except Exception as e:
        out({"ok": False, "error": "فایل PDF باز نشد (خراب است یا PDF نیست).", "debug": str(e)[:300]}); return
    n = len(doc)
    if n == 0:
        out({"ok": False, "error": "فایل هیچ صفحه‌ای ندارد."}); return
    os.makedirs(outdir, exist_ok=True)

    texts = []
    counters = []
    for i in range(n):
        try:
            pg = doc.load_page(i)
            texts.append(pg.get_text() or "")
            counters.append(footer_counter(page_spans(pg), pg.rect.height))
        except Exception:
            texts.append("")
            counters.append(None)
    words = [set(normalize_text(t).split()) for t in texts]
    text_pages = sum(1 for t in texts if len(t.strip()) > 30)

    # ---- شروعِ هر بیمه‌نامه ----
    starts, sims, split = [], [None] * n, 'similarity'
    with_counter = sum(1 for c in counters if c)
    if text_pages == 0:
        starts, split = list(range(n)), 'scan'
    elif with_counter >= max(2, int(n * 0.6)) and counters[0] and counters[0][0] == 1:
        # ۱) شمارنده‌ی پاورقی: صفحه‌ی «۱ از N» شروعِ بیمه‌نامه است
        split = 'footer'
        starts = [i for i, c in enumerate(counters) if c and c[0] == 1]
        if 0 not in starts:
            starts.insert(0, 0)
    else:
        # ۲) شباهت با صفحه‌ی اول (روشِ برنامه‌ی قبلی)
        tpl = words[0]
        for i in range(n):
            s = 1.0 if i == 0 else jaccard(tpl, words[i])
            sims[i] = round(s, 3)
            if i == 0 or s > 0.25:
                starts.append(i)

    policies = []
    for k, i in enumerate(starts):
        end = (starts[k + 1] - 1) if k + 1 < len(starts) else n - 1
        if split == 'scan':
            end = i
        # صفحه‌های خودِ بیمه‌نامه (تا پیش از اعلامیه/فیش‌ها)؛ بقیه صفحه‌های پرداختِ همین بیمه‌نامه‌اند
        pend = i
        while pend + 1 <= end and not is_payment_page(texts[pend + 1]):
            pend += 1
        has_text = len(texts[i].strip()) > 30
        data, missing = (None, [])
        if has_text:
            data, missing = extract_policy(doc.load_page(i), texts[i])
        fn = "pol_%03d.pdf" % (i + 1)
        sfn = "seg_%03d.pdf" % (i + 1)
        try:
            one = fitz.open(); one.insert_pdf(doc, from_page=i, to_page=pend); one.save(os.path.join(outdir, fn)); one.close()
            seg = fitz.open(); seg.insert_pdf(doc, from_page=i, to_page=end); seg.save(os.path.join(outdir, sfn)); seg.close()
        except Exception as e:
            out({"ok": False, "error": "جدا کردنِ صفحه‌ها ممکن نشد.", "debug": str(e)[:300]}); return
        # صفحه‌های فیشِ اقساط (هر قسط یک صفحه) جدا ذخیره می‌شوند تا هر کدام به ردیفِ قسطِ خودش وصل شود
        receipts, statement = [], None
        for j in range(pend + 1, end + 1):
            tj = normalize_text(texts[j])[:600]
            if RECEIPT_RX.search(tj):
                info = receipt_info(texts[j])
                rfn = "rcp_%03d_%02d.pdf" % (i + 1, len(receipts) + 1)
                try:
                    one = fitz.open(); one.insert_pdf(doc, from_page=j, to_page=j); one.save(os.path.join(outdir, rfn)); one.close()
                except Exception:
                    continue
                info.update({'page': j, 'file': rfn})
                receipts.append(info)
            elif statement is None and re.search(r'اعلامیه\s*آخرین\s*وضعیت', tj):
                statement = "stm_%03d.pdf" % (i + 1)
                try:
                    one = fitz.open(); one.insert_pdf(doc, from_page=j, to_page=j); one.save(os.path.join(outdir, statement)); one.close()
                except Exception:
                    statement = None
        receipts.sort(key=lambda r: (r.get('date') or '9999', r['page']))
        # تاریخ صدور اگر در صفحه‌ی اول نبود، از بقیه‌ی صفحه‌های همین بیمه‌نامه
        if data is not None and not data.get('issue_date'):
            for j in range(i + 1, pend + 1):
                m = re.search(r'تاریخ\s*صدور[^\d]{0,40}(1[34]\d{2}/\d{1,2}/\d{1,2})', normalize_text(texts[j]))
                if m:
                    data['issue_date'] = dates_in(m.group(1))[0]; break
        policies.append({"page": i, "end": end, "policy_end": pend, "has_text": has_text, "file": fn, "seg_file": sfn,
                         "similarity": sims[i], "counter": counters[i], "data": data, "missing": missing,
                         "receipts": receipts, "statement_file": statement})
    doc.close()
    out({"ok": True, "pages": n, "text_pages": text_pages, "split": split, "policies": policies})


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        out({"ok": False, "error": "خطای پیش‌بینی‌نشده در پردازشِ فایل.", "debug": str(e)[:500]})
