# -*- coding: utf-8 -*-
"""
فایل: api/py/vehicle_doc_reader.py
خواننده‌ی مدارکِ خودرو برای درخواست‌های شرکتی (هر بار یک صفحه؛ PDFهای چندصفحه‌ای قبلاً در سایت صفحه‌به‌صفحه جدا شده‌اند):
  - «سند فروش وسیله نقلیه» / «فاکتور فروش» (کارخانه، مثلاً پیلسان)  → kind = sales_invoice
  - «سند رسمی مالکیت وسیله نقلیه» (پلیس راهور)                       → kind = ownership_doc
  - بیمه‌نامه (ثالث/بدنه)                                              → kind = policy
  - کارت خودرو                                                           → kind = car_card
  - بقیه (یا خوانده نشد)                                                → kind = unknown

ترتیبِ منابعِ داده (دقیق‌ترین اول):
  ۱) بارکدها (zxing-cpp): شماره شاسی، شماره موتور و کد ملی روی سندهای کارخانه بارکد دارند → بدونِ خطای OCR
  ۲) لایه‌ی متنِ PDF (اگر فایل اسکن نباشد)
  ۳) OCR: PaddleOCR (فارسی) → tesseract (fas+eng) → RapidOCR (فقط حروف و ارقامِ لاتین)
     هر موتوری که روی سرور نصب باشد استفاده می‌شود؛ RapidOCR اگر باشد برای حروفِ لاتین (شاسی/موتور/تیپ) هم اجرا می‌شود.
متن‌ها به «سطر» تبدیل می‌شوند (کادرهای هم‌ارتفاع، از راست به چپ) و «برچسب: مقدار»ها از روی برچسب‌های شناخته‌شده خوانده می‌شوند.

اجرا:  python3 vehicle_doc_reader.py <file.pdf|file.png> [page_no(1-based)]
خروجی (آخرین خط): {"ok":true,"kind":"...","kind_fa":"...","status":"full|partial|none","data":{...},"missing":[...],"engines":[...]}
"""
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile

FA_DIGITS = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')
KIND_FA = {'sales_invoice': 'فاکتور فروش (سند فروش کارخانه)', 'ownership_doc': 'سند مالکیت', 'policy': 'بیمه‌نامه',
           'car_card': 'کارت خودرو', 'unknown': 'نامشخص'}


def norm(s):
    s = str(s or '').translate(FA_DIGITS)
    s = s.replace('ي', 'ی').replace('ك', 'ک').replace('ـ', '').replace('‌', ' ').replace('‏', '').replace('‎', '')
    return re.sub(r'[ \t]+', ' ', s).strip()


def clean_code(s):
    """شماره موتور/شاسی: فقط حروف و ارقامِ لاتین، بزرگ (نقطه، ستاره، خط‌تیره، فاصله و / حذف می‌شوند)"""
    return re.sub(r'[^A-Za-z0-9]', '', norm(s)).upper()


def valid_nid(s):
    """کد ملیِ ۱۰ رقمیِ اشخاص (رقمِ کنترل)"""
    s = re.sub(r'\D', '', s or '')
    if len(s) != 10 or len(set(s)) == 1:
        return False
    c = sum(int(s[i]) * (10 - i) for i in range(9)) % 11
    return int(s[9]) == (c if c < 2 else 11 - c)


VIN_RX = re.compile(r'(?<![A-Z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Z0-9])')


def is_vin(t, strict=True):
    """strict: استانداردِ VIN (بدونِ I/O/Q). شاسیِ بعضی خودروهای ایرانی I و O دارد؛ وقتی کنارِ برچسبِ خودش است، strict=False"""
    t = clean_code(t)
    ok = bool(VIN_RX.fullmatch(t)) if strict else bool(re.fullmatch(r'[A-Z0-9]{17}', t))
    return len(t) == 17 and ok and bool(re.search(r'[A-Z]', t)) and bool(re.search(r'\d', t))


# ---------------------------------------------------------------------------
#  تصویرِ صفحه
# ---------------------------------------------------------------------------
def load_fitz():
    try:
        import pymupdf as fitz
    except ImportError:
        import fitz
    return fitz


def page_image(path, page_no, dpi=300):
    """(PIL.Image | None, png_path, text_spans) — text_spans: متنِ لایه‌ی PDF با جای هر تکه"""
    from PIL import Image
    spans = []
    ext = os.path.splitext(path)[1].lower()
    if ext == '.pdf':
        fitz = load_fitz()
        doc = fitz.open(path)
        pg = doc[max(0, min(len(doc) - 1, page_no - 1))]
        try:
            d = pg.get_text('dict')
            for b in d.get('blocks', []):
                for ln in b.get('lines', []):
                    for sp in ln.get('spans', []):
                        t = sp.get('text', '').strip()
                        if t:
                            x0, y0, x1, y1 = sp['bbox']
                            k = dpi / 72.0
                            spans.append({'t': t, 'x0': x0 * k, 'y0': y0 * k, 'x1': x1 * k, 'y1': y1 * k, 'src': 'text'})
        except Exception:
            pass
        pm = pg.get_pixmap(dpi=dpi)
        im = Image.open(io.BytesIO(pm.tobytes('png'))).convert('RGB')
    else:
        im = Image.open(path).convert('RGB')
        if max(im.size) < 1800:   # عکسِ کوچک: بزرگ‌تر برای OCR
            r = 2400.0 / max(im.size)
            im = im.resize((int(im.width * r), int(im.height * r)))
    fd, png = tempfile.mkstemp(suffix='.png')
    os.close(fd)
    im.save(png)
    return im, png, spans


def clean_image(im):
    """حذفِ پس‌زمینه‌ی رنگیِ طرح‌دار: کانالِ قرمز (طرحِ سبز/آبیِ روشن سفید می‌شود، متنِ تیره سیاه می‌ماند)"""
    from PIL import ImageOps
    r = ImageOps.autocontrast(im.split()[0])
    return r.point(lambda v: 255 if v > 120 else 0).convert('L')


# ---------------------------------------------------------------------------
#  بارکد
# ---------------------------------------------------------------------------
def read_barcodes(im):
    try:
        import zxingcpp
    except Exception:
        return None
    out = []
    seen = set()

    def add(rs):
        for r in rs:
            t = (r.text or '').strip()
            if t and t not in seen:
                seen.add(t)
                out.append({'t': t, 'fmt': r.format.name})
    g = im.convert('L')
    try:
        add(zxingcpp.read_barcodes(g))
    except Exception:
        pass
    # بارکدهای باریکِ کنارِ هم در یک تصویرِ بزرگ دیده نمی‌شوند: نوار به نوار، بزرگ‌شده
    W, H = g.size
    step, hh = int(H * 0.025), int(H * 0.05)
    for y in range(0, max(1, H - hh), max(1, step)):
        strip = g.crop((0, y, W, min(H, y + hh)))
        for sc in (2, 1):
            try:
                s2 = strip.resize((strip.width * sc, strip.height * sc)) if sc != 1 else strip
                rs = zxingcpp.read_barcodes(s2, try_rotate=False)
            except Exception:
                rs = []
            if rs:
                add(rs)
                break
    return out


# ---------------------------------------------------------------------------
#  موتورهای OCR — خروجیِ همه: [{'t','x0','y0','x1','y1','src'}]
# ---------------------------------------------------------------------------
_PADDLE = None


def ocr_paddle(im):
    global _PADDLE
    try:
        import numpy as np
        from paddleocr import PaddleOCR
    except Exception:
        return None
    try:
        if _PADDLE is None:
            try:
                _PADDLE = PaddleOCR(lang='fa', use_angle_cls=False, show_log=False)   # نسخه‌ی ۲
            except TypeError:
                _PADDLE = PaddleOCR(lang='fa')                                     # نسخه‌ی ۳
        arr = np.array(im)
        out = []
        if hasattr(_PADDLE, 'predict'):
            res = _PADDLE.predict(arr)
            for r in res or []:
                rr = r.json.get('res', r) if hasattr(r, 'json') else r
                texts = rr.get('rec_texts') or []
                polys = rr.get('rec_polys') or rr.get('dt_polys') or []
                for t, p in zip(texts, polys):
                    xs = [float(q[0]) for q in p]; ys = [float(q[1]) for q in p]
                    out.append({'t': t, 'x0': min(xs), 'y0': min(ys), 'x1': max(xs), 'y1': max(ys), 'src': 'paddle'})
        else:
            res = _PADDLE.ocr(arr)
            for page in res or []:
                for it in page or []:
                    p, (t, _c) = it[0], it[1]
                    xs = [float(q[0]) for q in p]; ys = [float(q[1]) for q in p]
                    out.append({'t': t, 'x0': min(xs), 'y0': min(ys), 'x1': max(xs), 'y1': max(ys), 'src': 'paddle'})
        return out
    except Exception as e:
        sys.stderr.write('paddle: %s\n' % e)
        return None


def ocr_tesseract(png):
    exe = shutil.which('tesseract')
    if not exe:
        return None
    try:
        langs = subprocess.run([exe, '--list-langs'], capture_output=True, text=True, timeout=20).stdout
        lang = 'fas+eng' if 'fas' in langs else 'eng'
        r = subprocess.run([exe, png, '-', '-l', lang, '--psm', '4', 'tsv'], capture_output=True, text=True, timeout=120)
    except Exception:
        return None
    words = {}
    for ln in r.stdout.splitlines()[1:]:
        f = ln.split('\t')
        if len(f) < 12 or not f[11].strip():
            continue
        try:
            key = (int(f[2]), int(f[3]), int(f[4]))
            x, y, w, h = int(f[6]), int(f[7]), int(f[8]), int(f[9])
        except ValueError:
            continue
        words.setdefault(key, []).append({'t': f[11], 'x0': x, 'y0': y, 'x1': x + w, 'y1': y + h})
    out = []
    for ws in words.values():
        for w in ws:   # هر کلمه یک «اتم» با جای خودش (برای پیدا کردنِ مقدارِ سمتِ چپِ هر برچسب)
            out.append({'t': w['t'], 'x0': w['x0'], 'y0': w['y0'], 'x1': w['x1'], 'y1': w['y1'], 'src': 'tesseract'})
    return out


_RAPID = None


def ocr_rapid(im):
    global _RAPID
    try:
        import numpy as np
        from rapidocr_onnxruntime import RapidOCR
    except Exception:
        return None
    try:
        if _RAPID is None:
            _RAPID = RapidOCR()
        res, _ = _RAPID(np.array(im))
        out = []
        for b, t, c in res or []:
            if float(c) < 0.6:
                continue
            xs = [float(q[0]) for q in b]; ys = [float(q[1]) for q in b]
            out.append({'t': t, 'x0': min(xs), 'y0': min(ys), 'x1': max(xs), 'y1': max(ys), 'src': 'rapid'})
        return out
    except Exception as e:
        sys.stderr.write('rapid: %s\n' % e)
        return None


# ---------------------------------------------------------------------------
#  سطرها و «برچسب: مقدار»
# ---------------------------------------------------------------------------
def to_rows(boxes, tight=False):
    """کادرهای هم‌ارتفاع یک سطر؛ متنِ سطر از راست به چپ"""
    bs = sorted([b for b in boxes if norm(b['t'])], key=lambda b: (b['y0'] + b['y1']) / 2)
    rows = []
    for b in bs:
        cy, h = (b['y0'] + b['y1']) / 2, max(8.0, b['y1'] - b['y0'])
        for r in rows:
            if abs(r['cy'] - cy) < (0.35 if tight else 0.55) * max(h, r['h']):
                r['items'].append(b)
                r['cy'] = (r['cy'] * (len(r['items']) - 1) + cy) / len(r['items'])
                break
        else:
            rows.append({'cy': cy, 'h': h, 'items': [b]})
    out = []
    for r in rows:
        # ترتیبِ راست به چپ؛ لبه‌ی راستِ کلمه‌های فارسی در tesseract گاهی اشتباه است، لبه‌ی چپش درست است
        items = sorted(r['items'], key=lambda b: -(b['x0'] if b.get('src') == 'tesseract' else b['x1']))
        out.append({'y': r['cy'], 'text': '  '.join(norm(b['t']) for b in items), 'items': items})
    return out


# برچسب‌ها (با خطاهای رایجِ OCR: «موتود»، «خانوادکی»، «شناسنامه/ثبت»)
LABELS = {
    'usage': r'نوع\s*کاربری', 'car_system': r'سیستم', 'car_tip': r'تیپ', 'model_year': r'(?<!نوع\s)مدل', 'fuel': r'نوع\s*سوخت',
    'color': r'رنگ', 'capacity': r'ظرفیت', 'cylinders': r'تعداد\s*سیلندر', 'axles': r'تعداد\s*محور', 'wheels': r'تعداد\s*چرخ',
    'engine_no': r'شماره\s*موتو[رد]', 'chassis_no': r'شماره\s*شاس[یي]', 'cab_no': r'شماره\s*اتاق', 'cyl_volume': r'حجم\s*سیلندر',
    'maker_country': r'کشور\s*سازنده', 'vin_label': r'شماره\s*شناسا[یئ][یي]|VIN',
    'owner_name': r'نام\s*و\s*نام\s*خانواد\s*[گک]\s*[یي](?:\s*[/|]\s*سازمان)?(?:\s*[/|]\s*ش\s*ر\s*ک\s*ت)?|نام\s*سازمان|نام\s*ش\s*ر\s*ک\s*ت(?!\s*(?:بیمه|یا))',
    'father_name': r'نام\s*پدر', 'birth_date': r'تاریخ\s*تولد\s*(?:/\s*ثبت)?|تاریخ\s*تاسیس', 'id_no': r'شماره\s*شناسنامه\s*(?:/\s*ثبت)?|شماره\s*ثبت',
    'national_id': r'(?:شماره|کد|شناسه)\s*ملی', 'postal_code': r'کد\s*پستی', 'mobile': r'تلفن\s*همراه', 'phone': r'تلفن\s*ثابت',
    'address': r'آدرس(?:\s*محل\s*سکونت)?(?:\s*\(مالک\s*(?:حقیقی|حقوقی)\))?|نشانی', 'economic_code': r'کد\s*اقتصادی',
    'issue_place': r'محل\s*صدور\s*(?:/\s*ثبت)?|محل\s*ثبت', 'doc_no': r'شماره\s*سند', 'doc_date': r'^\s*تاریخ\s*:',
}
ALL_LABEL_RX = re.compile('|'.join('(?P<%s>%s)' % (k, v) for k, v in LABELS.items()))


def label_hits(atoms):
    """برچسب‌ها: [{key, x0,y0,x1,y1, rest, ids}] — rest: مقدارِ داخلِ همان تکه (مثلِ «نام پدر: عبدالحسین» در یک کادر)"""
    hits = []
    for r in to_rows(atoms, tight=True):
        items = r['items']
        text, spans = '', []
        for i, b in enumerate(items):
            t = norm(b['t'])
            if text:
                text += ' '
            spans.append((len(text), len(text) + len(t), i))
            text += t
        for m in ALL_LABEL_RX.finditer(text):
            idx = [i for (a, e, i) in spans if a < m.end() and e > m.start()]
            if not idx:
                continue
            bs = [items[i] for i in idx]
            last = spans[idx[-1]]
            rest = text[m.end():last[1]].strip(' :|.،-')   # باقیِ همان کادر/کلمه
            hits.append({'key': m.lastgroup, 'x0': min(b['x0'] for b in bs), 'x1': max(b['x1'] for b in bs),
                         'y0': min(b['y0'] for b in bs), 'y1': max(b['y1'] for b in bs), 'rest': rest, 'ids': {id(b) for b in bs}})
    return hits


def kv_geo(atoms, page_w):
    """{key: [(value, y)]} — هر کادرِ غیرِبرچسب به نزدیک‌ترین برچسبِ سمتِ راستش (از نظرِ ارتفاع) تعلق می‌گیرد؛
    مقدار گاهی کمی پایین‌تر از برچسب چاپ می‌شود (فرم‌های کارخانه)، پس نوارِ عمودی تا ۱٫۶ برابرِ ارتفاعِ برچسب است."""
    hits = label_hits(atoms)
    if not hits:
        return {}
    lab_ids = set().union(*[h['ids'] for h in hits])
    for h in hits:
        h['cy'], h['lh'], h['vals'] = (h['y0'] + h['y1']) / 2, max(10.0, h['y1'] - h['y0']), []
    for b in atoms:
        if id(b) in lab_ids or not norm(b['t']):
            continue
        cy = (b['y0'] + b['y1']) / 2
        cands = [h for h in hits if h['x0'] >= b['x1'] - 4 and b['x1'] >= h['x0'] - 0.55 * page_w and abs(cy - h['cy']) < 1.6 * h['lh']]
        if not cands:
            continue
        dmin = min(abs(cy - h['cy']) for h in cands)
        near = [h for h in cands if abs(cy - h['cy']) <= dmin + 0.45 * h['lh']]
        min(near, key=lambda h: h['x0'])['vals'].append(b)
    out = {}
    for h in hits:
        parts = []
        if len(re.sub(r'[\s:|.]', '', h['rest'])) >= 1:
            parts.append(h['rest'])
        if h['vals']:
            parts += [r['text'] for r in to_rows(h['vals'])]
        v = ' '.join(parts).strip(' :|.،-')
        v = re.sub(r'^[:\s|]+', '', v).strip()
        if v:
            out.setdefault(h['key'], []).append((v, h['cy']))
    return out


def kv_rows(rows):
    """{key: [values]} — مقدار = متنِ بینِ پایانِ برچسب و شروعِ برچسبِ بعدی در همان سطر"""
    out = {}
    for r in rows:
        t = r['text']
        ms = [m for m in ALL_LABEL_RX.finditer(t)]
        for i, m in enumerate(ms):
            key = m.lastgroup
            end = ms[i + 1].start() if i + 1 < len(ms) else len(t)
            v = t[m.end():end].strip(' :|.،-')
            v = re.sub(r'^[:\s|]+', '', v).strip()
            if v:
                out.setdefault(key, []).append(v)
    return out


def first(vals, rx=None, clean=None):
    for v in vals or []:
        if rx:
            m = re.search(rx, v)
            if not m:
                continue
            v = m.group(1) if m.groups() else m.group(0)
        v = clean(v) if clean else v.strip()
        if v:
            return v
    return ''


DATE_RX = re.compile(r'(1[34]\d\d)\s*[/.\-]\s*(\d{1,2})\s*[/.\-]\s*(\d{1,2})')


def fdate(s):
    m = DATE_RX.search(norm(s))
    return '%s/%02d/%02d' % (m.group(1), int(m.group(2)), int(m.group(3))) if m else ''


PLATE_RX = re.compile(r'(\d{2})\s*[|\-]?\s*(\d{3})\s*([آ-ی])\s*(\d{2})|(\d{2})\s*([آ-ی])\s*(\d{3})\s*[|\-]?\s*(\d{2})')


def classify(text):
    """نوعِ صفحه با نشانه‌های متنی (OCR عنوان‌های خوشنویسی را معمولاً نمی‌خواند؛ پس نشانه‌های فرم هم شمرده می‌شوند)"""
    t = norm(text)
    sales = len(re.findall(r'سند\s*فروش|فاکتور\s*فروش|صورت\s*حساب\s*فروش|PILSAN|کارخانه|شماره\s*اتاق|نظریه\s*کارشناس\s*تصادفات|شماره\s*پروانه\s*گمرکی|نامبرده\s*زیر\s*واگذار', t, re.I))
    own = len(re.findall(r'مالکیت\s*وسیله|سند\s*رسمی|پلیس\s*راهنمایی|واحد\s*شماره\s*گذاری|شماره\s*انتظامی|اثر\s*انگشت|نام\s*سازمان|تاریخ\s*تاسیس|مسئول\s*بررسی\s*مدارک|مشخصات\s*وسیله\s*\S*\s*:', t))
    pol = len(re.findall(r'بیمه\s*نامه|حق\s*بیمه|تعهدات\s*بیمه\s*گر|بیمه\s*گذار|خسارت|تخفیف', t))
    if re.search(r'کارت\s*(?:خودرو|ماشین|مشخصات\s*خودرو)|VEHICLE\s*CARD', t, re.I) and max(sales, own, pol) < 2:
        return 'car_card'
    best = max((sales, 'sales_invoice'), (own, 'ownership_doc'), (pol, 'policy'))
    return best[1] if best[0] >= 2 or (best[0] == 1 and best[1] != 'policy') else 'unknown'


def latin_tokens(boxes):
    toks = []
    for b in boxes:
        t = norm(b['t'])
        # شاسیِ فاصله‌دار «N 4 7 B E P ...»
        if re.fullmatch(r'(?:[A-Z0-9]\s+){10,}[A-Z0-9]', t):
            toks.append(t.replace(' ', ''))
        for x in re.findall(r'[A-Za-z0-9][A-Za-z0-9.*\-/]{4,40}[A-Za-z0-9*]', t):
            toks.append(x)
    return toks


OWNER_KEYS = ('owner_name', 'father_name', 'birth_date', 'id_no', 'national_id', 'postal_code', 'mobile', 'phone', 'address', 'issue_place')


def similar(a, b):
    """دو کدِ لاتینِ تقریباً یکی (خطای OCR: J/I، O/0) — برای اینکه شاسی به‌جای موتور برداشته نشود"""
    if not a or not b:
        return False
    if a == b or a[-8:] == b[-8:]:
        return True
    if abs(len(a) - len(b)) > 2:
        return False
    return sum(1 for x, y in zip(a, b) if x != y) + abs(len(a) - len(b)) <= 3


def fix_ocr_code(t):
    """کدِ لاتین: O در بخشِ رقمی → 0 (TEPO00004 → TEP000004)"""
    m = re.fullmatch(r'([A-Z]{3})([0-9O]{6})', t) or re.fullmatch(r'([A-Z]{2,4})([0-9O]{4,10})', t)
    return m.group(1) + m.group(2).replace('O', '0') if m else t


def extract(atoms, boxes, barcodes, alltext, page_w):
    kvg = kv_geo(atoms, page_w)
    # بخشِ «مشخصات مالک»: فیلدهای مالک فقط از پایینِ این عنوان (بالای آن مشخصاتِ فروشنده/مدیرانِ کارخانه است)
    owner_y = None
    for b in to_rows(atoms, tight=True):
        if re.search(r'مشخصات\s*مال[کك]', b['text']):
            owner_y = b['y']
            break
    dang = [b for b in atoms if re.search(r'دا?ن[گک]', norm(b['t']))]
    if owner_y is None and dang:
        owner_y = min(b['y0'] for b in dang) - 3 * max(10.0, dang[0]['y1'] - dang[0]['y0'])
    if owner_y is None and len(kvg.get('owner_name', [])) > 1:
        # عنوانِ «مشخصات مالک» خوانده نشد: نامِ بالای صفحه (مدیرانِ کارخانه) کنار می‌رود؛ آخرین «نام و نام خانوادگی» مالک است
        owner_y = max(y for _v, y in kvg['owner_name']) - 10
    kv = {}
    for k, vs in kvg.items():
        if k in OWNER_KEYS and owner_y is not None:
            vs = [x for x in vs if x[1] > owner_y - 10] or ([] if k != 'national_id' else vs)
        kv[k] = [v for v, _y in vs]
    d = {}
    bc = [b['t'] for b in (barcodes or [])]
    # لاتین‌ها: RapidOCR (اگر هست) دقیق‌تر است، پس اول
    lat = latin_tokens(sorted(boxes, key=lambda b: 0 if b.get('src') == 'rapid' else 1))
    # ---- شاسی: بارکد → برچسب → هر کدِ ۱۷ نویسه‌ای ----
    vins = [clean_code(x) for x in bc if is_vin(x)]
    if not vins:
        for v in kv.get('chassis_no', []) + kv.get('vin_label', []):
            vins += [clean_code(x) for x in re.findall(r'[A-Z0-9][A-Z0-9 ]{15,40}', v) if is_vin(clean_code(x), strict=False)]
    if not vins:
        vins = [clean_code(x) for x in lat if is_vin(x)]
    if not vins:
        # شاسیِ حرف‌به‌حرف در خانه‌های جدا («N A A N 1 1 F E …» در سندِ مالکیت): کادرهای تک‌نویسه‌ای یک سطر، از چپ به راست
        singles = [b for b in boxes if re.fullmatch(r'[A-Za-z0-9]', norm(b['t']))]
        for r in to_rows(singles):
            seq = ''.join(norm(b['t']).upper() for b in sorted(r['items'], key=lambda b: b['x0']))
            for k in range(len(seq) - 16):
                if is_vin(seq[k:k + 17], strict=False):
                    vins.append(seq[k:k + 17]); break
    if vins:
        fixed = []
        for x in vins:   # «O» به‌جای صفرِ OCR: اگر با صفر VINِ استاندارد شد، همان
            y = x.replace('O', '0')
            fixed.append(y if not is_vin(x) and is_vin(y) else x)
        strict = [x for x in fixed if is_vin(x)]
        pool = strict or fixed
        d['vin'] = max(set(pool), key=pool.count)
    # شماره شاسیِ غیر ۱۷ نویسه‌ای (خودروهای قدیمی/وارداتی: NAG087NPRF08314) از برچسبِ «شماره شاسی»
    for v in kv.get('chassis_no', []):
        for x in re.findall(r'[A-Za-z0-9][A-Za-z0-9\-]{6,24}', v):
            c = clean_code(x)
            if 8 <= len(c) <= 22 and re.search(r'[A-Z]', c) and re.search(r'\d', c) and c != d.get('vin'):
                d['chassis_no'] = c
                break
        if d.get('chassis_no'):
            break

    def eng_ok(x):
        c = clean_code(x)
        return (6 <= len(c) <= 30 and re.search(r'\d', c) and re.search(r'[A-Z]', c) and not similar(c, d.get('vin', ''))
                and not is_vin(c) and not re.fullmatch(r'[A-Z]{2,4}[0-9O]{5,8}', c) and not re.fullmatch(r'\d+CC', c) and not re.fullmatch(r'S\d{2,8}', c))
    # ---- موتور: بارکد → مقدارِ برچسبِ «شماره موتور» → بلندترین کدِ لاتینِ دیگر ----
    engs = [x for x in bc if eng_ok(x) and not re.search(r'\s|/', x.strip())]
    if not engs:
        for v in kv.get('engine_no', []):
            engs += [x for x in re.findall(r'[A-Za-z0-9*][A-Za-z0-9.*\-/,]{4,40}[A-Za-z0-9*]?', v) if eng_ok(x) and clean_code(x) != d.get('chassis_no')]

    # مقدارِ برچسب با OCRِ فارسی گاهی حرفِ اول را جا می‌اندازد (*13.48-50…)؛ کدِ لاتینِ هم‌پایانِ دقیق‌تر جایش را می‌گیرد
    lat_eng = [x for x in lat if eng_ok(x) and not re.fullmatch(r'\d{2}/\d+-\d+-\d+=\d+', x)]
    if engs and lat_eng:
        e0 = clean_code(engs[0])
        better = [x for x in lat_eng if clean_code(x) != e0 and (clean_code(x).endswith(e0[-10:]) or e0.endswith(clean_code(x)[-10:])) and len(clean_code(x)) >= len(e0)]
        if better and not [x for x in bc if eng_ok(x)]:
            engs = [max(better, key=lambda x: len(clean_code(x)))]
    if not engs and lat_eng:
        engs = [max(lat_eng, key=lambda x: len(clean_code(x)))]
        # نه از بارکد و نه کنارِ برچسب => حدس؛ مگر اینکه انتهایش با مقدارِ (ناقصِ) کنارِ برچسب یکی باشد
        tail = clean_code(engs[0])[-8:]
        if not [x for x in bc if eng_ok(x)] and not any(clean_code(v).find(tail) >= 0 for v in kv.get('engine_no', [])):
            d['engine_guess'] = 1
    if not engs:   # شماره موتورِ فقط رقمی (۵۹۵۸۰۴) فقط وقتی کنارِ برچسبِ خودش است
        for v in kv.get('engine_no', []):
            engs += [x for x in re.findall(r'(?<!\d)\d{5,12}(?!\d)', v) if not similar(x, d.get('vin', ''))][:1]
    if engs:
        d['engine_raw'] = norm(engs[0])
        d['engine_no'] = clean_code(engs[0])
    # ---- کد/شناسه‌ی ملی: بارکدِ ۱۰ رقمیِ معتبر یا ۱۱ رقمیِ شرکت → برچسب ----
    nids = [re.sub(r'\D', '', x) for x in bc if valid_nid(x) or re.fullmatch(r'1\d{10}', x.strip())]
    for v in kv.get('national_id', []):
        for x in re.findall(r'\d[\d\s\-]{8,14}\d', v):
            x = re.sub(r'\D', '', x)
            if valid_nid(x) or re.fullmatch(r'1\d{10}', x):
                nids.append(x)
    if nids:
        d['national_id'] = nids[0]
    cut = lambda s: re.sub(r'\s{3,}.*$', '', s).strip(' :|-،.')
    for k in ('usage', 'car_system', 'fuel', 'color', 'maker_country', 'father_name', 'issue_place'):
        v = first(kv.get(k), clean=cut)
        v = re.sub(r'[^آ-ی0-9\s\-/()]', ' ', v)          # این فیلدها فارسی‌اند؛ حروفِ لاتینِ آن‌ها خطای OCR است
        v = re.sub(r'(?<![\d/])\b\d{1,2}\b(?![\d/])', ' ', v)   # رقمِ تنهای چسبیده (خطای OCR)
        v = re.sub(r'\s+', ' ', v).strip(' -/')
        if v and 2 <= len(v) <= 40 and len(re.findall(r'[آ-ی]', v)) >= 2:
            d[k] = v
    v = first(kv.get('car_tip'), r'([A-Za-z0-9][A-Za-z0-9/\-]{1,14}|[آ-ی][آ-ی ]{1,20})')
    if v: d['car_tip'] = v.strip('/ ')
    shorts = [norm(b['t']).replace(' ', '') for b in sorted(boxes, key=lambda b: 0 if b.get('src') == 'rapid' else 1)]
    if not d.get('car_tip'):
        t = next((x for x in shorts if re.fullmatch(r'/[A-Z0-9]{2,6}|[A-Z]\d[A-Z]{1,2}', x)), '')
        if t: d['car_tip'] = t.strip('/')
    v = first(kv.get('model_year'), r'(1[34]\d\d|20\d\d|19\d\d)')
    if not v:   # کادرِ تنهای «۱۴۰۵» (سالِ ساخت) — تاریخ‌ها «/» دارند و جدا حساب نمی‌شوند
        v = next((x for x in shorts if re.fullmatch(r'13[5-9]\d|14[0-2]\d|20[0-3]\d', x)), '')
    if v: d['model_year'] = v
    for k, rx in (('cylinders', r'(?<!\d)(\d{1,2})(?!\d)'), ('axles', r'(?<!\d)(\d)(?!\d)'), ('wheels', r'(?<!\d)(\d{1,2})(?!\d)')):
        v = first(kv.get(k), rx)
        if v: d[k] = v
    v = first(kv.get('capacity'), clean=cut)
    m = re.search(r'(\d{1,3})\s*(?:تن|نفر)(?:[^\d]{0,20}(\d{1,2})\s*نفر)?', v or '')
    if m: d['capacity'] = (m.group(1) + (' تن' if 'تن' in v[m.start():m.end() + 3] else ' نفر')) + ((' با ' + m.group(2) + ' نفر') if m.group(2) else '')
    m = re.search(r'(\d{3,5})\s*CC|CC\s*(\d{3,5})', alltext, re.I)
    if m: d['cyl_volume'] = m.group(1) or m.group(2)
    cabs = [fix_ocr_code(clean_code(x)) for x in kv.get('cab_no', []) + lat if re.fullmatch(r'[A-Z]{3}[0-9O]{6}', clean_code(x))]
    if cabs: d['cab_no'] = cabs[0]
    # ---- مالک ----
    # در سندهای کارخانه نامِ خریدار همیشه با «( ۶ دانگ )» می‌آید؛ مستقل از جای برچسب، دقیق‌ترین نشانه است
    v = ''
    for ln in alltext.split('\n'):
        m = re.search(r'([آ-یA-Za-z][آ-یA-Za-z\s/]{3,90}?)\s*\(?\s*(\d)\s*دا?ن[گک]\s*\)?', ln)
        if m:
            v = m.group(1) + ' (' + m.group(2) + ' دانگ)'
            v = re.split(r'شر\s*کت\s*:|سازمان\s*/?\s*ش\s*ر\s*ک\s*ت\s*:?', v)[-1]
            break
    if not v and dang:
        # کادرهای سمتِ راستِ «دانگ» در همان نوار = نامِ خریدار (تا برچسبِ «نام و نام خانوادگی …»)
        b0 = dang[0]; h0 = max(10.0, b0['y1'] - b0['y0']); cy0 = (b0['y0'] + b0['y1']) / 2
        labs = [h for h in label_hits(atoms) if abs((h['y0'] + h['y1']) / 2 - cy0) < 1.6 * h0 and h['x0'] > b0['x1']]
        right = min([h['x0'] for h in labs] or [page_w * 0.9])
        near = [b for b in atoms if b is not b0 and abs((b['y0'] + b['y1']) / 2 - cy0) < 0.9 * h0 and b0['x1'] - 4 <= b['x0'] and b['x1'] <= right + 4]
        if near:
            v = ' '.join(r['text'] for r in to_rows(near)) + ' (6 دانگ)'
    if not v:
        v = first([x for x in kv.get('owner_name', []) if not re.search(r'سمت|مدیر', x)], clean=lambda s: s.strip(' :|-'))
    if v:
        sh = re.search(r'\(?\s*(\d)\s*دا?ن[گک]\s*\)?', v)
        if sh:
            d['owner_share'] = sh.group(1)
            v = v[:sh.start()]
        v = re.sub(r'[^آ-ی\s]', ' ', v)
        v = re.sub(r'\s+', ' ', v).strip()
        # باقی‌مانده‌ی برچسب («سازمان / شرکت:») و کلمه‌های تکراری
        v = re.sub(r'^(?:(?:سازمان|ش\s*ر\s*ک\s*ت|نام\s*و\s*نام\s*خانواد\s*[گک]\s*[یي])\s*)+', '', v).strip()
        if 3 <= len(v) <= 90:
            d['owner_name'] = v
    v = first(kv.get('birth_date'), clean=fdate)
    if v: d['birth_date'] = v
    ds = [fdate(x) for x in alltext.split('\n')[:8] if fdate(x)]
    if ds: d['doc_date'] = ds[0]
    v = first(kv.get('id_no'), r'(?<!\d)(\d{5,12})(?!\d)')
    if v: d['id_no'] = v
    if not d.get('national_id') and re.fullmatch(r'1\d{10}', d.get('id_no', '')):
        d['national_id'] = d['id_no']   # شرکت: «شماره ثبت» در سندِ مالکیت همان شناسه‌ی ملیِ ۱۱ رقمی است
    v = first(kv.get('postal_code'), r'(?<!\d)(\d{10})(?!\d)')
    if v: d['postal_code'] = v
    v = first(kv.get('mobile'), r'(?<!\d)(0?9\d{9})(?!\d)')
    if v: d['mobile'] = v if v.startswith('0') else '0' + v
    v = first(kv.get('phone'), r'(?<!\d)(0\d{9,10})(?!\d)')
    if v: d['phone'] = v
    v = first(kv.get('economic_code'), r'(?<!\d)(\d{10,14})(?!\d)')
    if v: d['economic_code'] = v
    m = re.search(r'(?<![A-Z0-9])(S-\d{3,8})', alltext)
    if m: d['doc_no'] = m.group(1)
    addr = [cut(a) for a in kv.get('address', []) if len(re.findall(r'[آ-ی]', a)) > 20]
    if not addr and owner_y is not None:
        # نشانی گاهی سطرِ زیرِ برچسب است
        rows = to_rows(atoms)
        for i, r in enumerate(rows):
            if r['y'] > owner_y and re.search(r'آدرس|نشانی', r['text']) and i + 1 < len(rows) and len(re.findall(r'[آ-ی]', rows[i + 1]['text'])) > 15:
                addr = [rows[i + 1]['text']]
                break
    if addr:
        a = re.sub(r'[^آ-ی0-9\s,،.\-()/]', ' ', addr[0])   # نشانی فارسی است؛ تکه‌های لاتینِ آن خطای OCR‌اند
        d['address'] = re.sub(r'\s+', ' ', a).strip(' ,،.')[:250]
    m = PLATE_RX.search(alltext)
    if m:
        g = m.groups()
        d['plate'] = ('%s%s%s - %s' % (g[0], g[2], g[1], g[3])) if g[0] else ('%s%s%s - %s' % (g[4], g[5], g[6], g[7]))
    if d.get('car_system') or d.get('car_tip'):
        d['car_name'] = ' '.join(x for x in [d.get('car_system', ''), d.get('car_tip', '')] if x).strip()
    return d


REQUIRED = {
    'sales_invoice': [('vin', 'شماره شاسی'), ('engine_no', 'شماره موتور'), ('owner_name', 'نامِ خریدار'), ('national_id', 'کد ملیِ خریدار')],
    'ownership_doc': [('vin', 'شماره شاسی'), ('engine_no', 'شماره موتور'), ('owner_name', 'نامِ مالک')],
    'car_card': [('vin', 'شماره شاسی'), ('plate', 'پلاک')],
    'policy': [('vin', 'شماره شاسی')],
}


def read(path, page_no=1):
    im, png, spans = page_image(path, page_no)
    engines = []
    try:
        boxes = []
        if len(''.join(s['t'] for s in spans)) > 80:
            boxes = spans
            engines.append('text')
        barcodes = read_barcodes(im)
        if barcodes is not None:
            engines.append('barcode')
        if not boxes:
            fa_boxes = ocr_paddle(im)
            eng = 'paddle'
            if fa_boxes is None:
                fa_boxes, eng = ocr_tesseract(png), 'tesseract'
            if fa_boxes is not None:
                engines.append(eng)
                # پس‌زمینه‌ی طرح‌دار (سندِ سبزِ پلیس، کارت): اگر برچسب کم خوانده شد، یک بار روی تصویرِ پاک‌سازی‌شده
                if len(label_hits(fa_boxes)) < 6:
                    im2 = clean_image(im)
                    fd2, png2 = tempfile.mkstemp(suffix='.png'); os.close(fd2); im2.save(png2)
                    try:
                        b2 = ocr_paddle(im2.convert('RGB')) if eng == 'paddle' else ocr_tesseract(png2)
                    finally:
                        os.unlink(png2)
                    if b2 and len(label_hits(b2)) > len(label_hits(fa_boxes)):
                        fa_boxes = b2
                        engines.append('cleaned')
            boxes = fa_boxes or []
            lat = ocr_rapid(im)
            if lat is not None:
                engines.append('rapid')
                boxes = boxes + lat   # حروفِ لاتینِ دقیق‌تر؛ برچسب‌های فارسی از موتورِ فارسی
        if not boxes and not barcodes:
            return {'ok': False, 'error': 'هیچ موتورِ شناسایی (OCR) روی سرور در دسترس نیست یا صفحه خالی است.', 'engines': engines}
        fa_only = [b for b in boxes if b.get('src') != 'rapid']
        rows = to_rows(fa_only or boxes)
        alltext = '\n'.join(r['text'] for r in rows) + '\n' + '\n'.join(norm(b['t']) for b in boxes if b.get('src') == 'rapid')
        kind = classify(alltext)
        data = extract(fa_only or boxes, boxes, barcodes, alltext, im.width)
        if kind == 'unknown' and data.get('vin') and data.get('engine_no'):
            kind = 'sales_invoice' if data.get('cab_no') or data.get('owner_share') else 'ownership_doc' if data.get('plate') else 'unknown'
        if kind == 'policy':   # بیمه‌نامه‌ی قبلیِ همین خودرو: ثالث یا بدنه
            data['policy_type'] = 'BODY' if re.search(r'بدنه', alltext) and not re.search(r'شخص\s*ثالث', alltext) else 'THIRDPARTY'
        req = REQUIRED.get(kind, [])
        missing = [fa for k, fa in req if not (data.get(k) or (k == 'vin' and data.get('chassis_no')))]
        if data.get('engine_guess') and 'شماره موتور' not in missing:
            missing.append('شماره موتور (حدسی؛ تأیید شود)')
        ident = bool(data.get('vin') or data.get('chassis_no') or data.get('engine_no'))
        status = 'full' if (req and not missing) else ('partial' if ident or (kind != 'unknown') else 'none')
        return {'ok': True, 'kind': kind, 'kind_fa': KIND_FA[kind], 'status': status, 'data': data, 'missing': missing,
                'engines': engines, 'barcodes': [b['t'] for b in (barcodes or [])]}
    finally:
        try:
            os.unlink(png)
        except Exception:
            pass


if __name__ == '__main__':
    try:
        p = sys.argv[1]
        n = int(sys.argv[2]) if len(sys.argv) > 2 else 1
        res = read(p, n)
    except Exception as e:
        res = {'ok': False, 'error': 'خواندنِ فایل ممکن نشد: %s' % e}
    sys.stdout.write(json.dumps(res, ensure_ascii=False) + '\n')
