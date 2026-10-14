# -*- coding: utf-8 -*-
# فایل: api/py/proposal_reader.py
# خواندنِ «پرسش‌نامه و پیشنهادِ بیمه‌نامه» (و «پیش‌نویسِ بیمه‌نامه») پاسارگاد برای صفحه‌ی صدورِ کارکنان.
#   - پیشنهاد: فرمِ یک‌صفحه‌ای؛ برچسب‌ها در یک تکه و مقدارها جدا در همان ردیف‌اند، پس مقدارِ هر ستون از روی جایش
#     در ردیف (نسبت به عرضِ صفحه) برداشته می‌شود و بعد با الگوهای متنی (کد ملیِ ۱۰ رقمی، تاریخ، مبلغ) تکمیل می‌شود.
#     مهم برای بستگان: «بیمه‌گزار مالکِ وسیله نقلیه می‌باشد؟» و «ذینفع» (معمولاً همان مالک).
#   - پیش‌نویس: همان صفحه‌ی اولِ بیمه‌نامه بدونِ شماره؛ با همان خواننده‌ی بیمه‌نامه (policy_bundle.extract_policy).
# اجرا:  python3 proposal_reader.py <file.pdf>
# خروجی (آخرین خط، JSON): {"ok": true, "kind": "proposal|draft", "data": {...}, "missing": [...]}
import sys
import os
import re
import json

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import policy_bundle as pb  # noqa: E402


def out(o):
    sys.stdout.write(json.dumps(o, ensure_ascii=False) + "\n")
    sys.stdout.flush()


HONOR_RX = re.compile(r'^(جناب\s*آقای|جناب\s*آقا|آقای|سرکار\s*خانم|خانم|شرکت)\s*')


def honor_split(name):
    """«جناب آقای علیرضا داودی» → («علیرضا داودی», 'M')"""
    name = re.sub(r'\s+', ' ', (name or '')).strip(' -:')
    m = HONOR_RX.match(name)
    g = ''
    if m:
        h = m.group(1)
        g = 'F' if 'خانم' in h else ('M' if 'آقا' in h else '')
        name = name[m.end():].strip()
    return name, g


# پوشش‌ها با نامِ مرتب (متنِ PDF جهتِ جمله‌ها را به هم می‌ریزد)
COVERS = [(r'آتش\s*سوزی.{0,12}سرقت\s*کلی', 'آتش‌سوزی، سرقت کلی و حادثه (پایه)'),
          (r'سرقت\s*لوازم', 'سرقت لوازم و قطعات'),
          (r'سیل|زلزله|بلایای', 'بلایای طبیعی (سیل، زلزله، تگرگ، طوفان...)'),
          (r'شکست\s*شیشه', 'شکست شیشه'),
          (r'نوسان\s*قیمت', 'نوسان قیمت'),
          (r'ایاب\s*و\s*ذهاب', 'هزینه ایاب و ذهاب'),
          (r'ترانزیت', 'ترانزیت (خارج از کشور)'),
          (r'مواد\s*شیمیایی|اسید', 'پاشیدن رنگ/اسید/مواد شیمیایی'),
          (r'کشیدن\s*رنگ|خط\s*و\s*خش', 'کشیدن رنگ')]


def covers_clean(text):
    found = []
    for rx, name in COVERS:
        if re.search(rx, text or '') and name not in found:
            found.append(name)
    return '، '.join(found)


def other_names(text, skip):
    """نام‌هایی با «جناب آقای / سرکار خانم» که با بیمه‌گذار فرق دارند (ذینفع/مالک در پیش‌نویس و بیمه‌نامه)"""
    res = []
    for m in re.finditer(r'(جناب\s*آقای|سرکار\s*خانم)\s*([آ-ی\s]{3,40}?)(?=[-\d():،,]|$|\n)', text):
        nm = re.sub(r'\s+', ' ', m.group(2)).strip()
        if nm and nm != skip and nm not in [r[0] for r in res]:
            res.append((nm, 'F' if 'خانم' in m.group(1) else 'M'))
    return res


def rows_by(spans, rx):
    """همه‌ی تکه‌های ردیفی که برچسبش با rx می‌خواند (به‌جز خودِ برچسب‌ها)"""
    labels = [s for s in spans if rx.search(s['t'])]
    if not labels:
        return None, []
    lab = labels[0]
    row = [s for s in spans if s is not lab and pb.same_row(s, lab, 0.3)]
    return lab, row


def band(row, w, lo, hi, allow_label=False):
    """تکه‌هایی که مرکزشان بین lo و hi (نسبتِ عرضِ صفحه) است"""
    res = []
    for s in row:
        c = (s['x0'] + s['x1']) / 2 / w
        if lo <= c < hi and (allow_label or ':' not in s['t']):
            res.append(s)
    res.sort(key=lambda s: -s['x1'])
    return ' '.join(s['t'] for s in res).strip()


def clean(v):
    v = re.sub(r'\s+', ' ', (v or '')).strip(' -:')
    return '' if re.fullmatch(r'[-\s.]*', v) else v


def parse_proposal(page, text):
    w = page.rect.width
    sp = pb.page_spans(page)
    t = pb.normalize_text(text)
    d = {'ins_type': 'بدنه' if 'بدنه' in t[:300] else ('ثالث' if 'ثالث' in t[:300] else '')}

    # ---- مشخصاتِ متقاضی (بیمه‌گزار) ----
    lab, row = rows_by(sp, re.compile(r'کد\s*ملی'))
    if lab:
        nm = band(row + [lab], w, 0.55, 0.95)
        d['insured_name'], d['insured_gender'] = honor_split(nm)
        for s in row + [lab]:
            m = re.search(r'(?<!\d)(\d{10})(?!\d)', s['t'])
            if m and not d.get('national_id'):
                d['national_id'] = m.group(1)
            if not d.get('birth_date'):
                ds = pb.dates_in(s['t'])
                if ds:
                    d['birth_date'] = ds[0]
        d['job'] = clean(band(row, w, 0.0, 0.2))
    lab, row = rows_by(sp, re.compile(r'نشانی'))
    if lab:
        d['address'] = clean(band(row, w, 0.3, 0.92))
        for s in row:
            m = re.search(r'(?<!\d)(\d{10})(?!\d)', s['t'])
            if m:
                d['postal_code'] = m.group(1)
    lab, row = rows_by(sp, re.compile(r'^\s*:?\s*تلفن'))
    if lab:
        for s in row:
            ph = pb.norm_phone(s['t'])
            if ph and not d.get('phone'):
                d['phone'] = ph
        own = [s for s in row if s['t'] in ('بلی', 'بله', 'خیر')]
        if own:
            d['insured_is_owner'] = own[0]['t'] in ('بلی', 'بله')
        ben = band(row, w, 0.0, 0.3)
        if ben and not re.search(r'\d{4}', ben):
            d['beneficiary'], d['beneficiary_gender'] = honor_split(ben)
    if 'insured_is_owner' not in d:
        m = re.search(r'مالک\s*وسیله\s*نقلیه\s*می\s*باشد\??\s*(بلی|بله|خیر)', t)
        if m:
            d['insured_is_owner'] = m.group(1) != 'خیر'

    # ---- مشخصاتِ وسیله نقلیه ----
    lab, row = rows_by(sp, re.compile(r'شماره\s*پلاک'))
    if lab:
        pl = band(row, w, 0.68, 0.9)
        d['plate'] = pb.parse_plate(pl) if re.search(r'\d', pl) else ''
        d['car_kind'] = clean(band(row, w, 0.45, 0.68))
        d['car_system'] = clean(band(row, w, 0.25, 0.45))
        d['car_tip'] = clean(band(row, w, 0.0, 0.25))
    lab, row = rows_by(sp, re.compile(r'سال\s*ساخت'))
    if lab:
        my = clean(band(row, w, 0.68, 0.95))
        d['model_year'] = my if re.fullmatch(r'(1[34]\d{2}|19\d{2}|20\d{2})', my) else ''
        d['color'] = clean(band(row, w, 0.45, 0.68))
        d['capacity'] = clean(band(row, w, 0.25, 0.45))
        d['usage'] = clean(band(row, w, 0.0, 0.25))
    lab, row = rows_by(sp, re.compile(r'شماره\s*موتور'))
    if lab:
        d['engine_no'] = re.sub(r'[^A-Za-z0-9]', '', band(row, w, 0.65, 0.95))
        d['vin'] = re.sub(r'[^A-Za-z0-9]', '', band(row, w, 0.4, 0.65))
        cy = clean(band(row, w, 0.0, 0.25))
        d['cylinders'] = cy if re.fullmatch(r'\d{1,2}', cy) else ''

    # ---- سابقه‌ی بیمه ----
    lab, row = rows_by(sp, re.compile(r'بیمه\s*نامه\s*سال\s*قبل'))
    if lab:
        pn = clean(band(row, w, 0.62, 0.95))
        d['prev_policy'] = pn if re.search(r'\d{3}', pn) else ''
        ds = [x for s in row for x in pb.dates_in(s['t'])]
        d['prev_expiry'] = ds[0] if ds else ''
        pi = clean(band(row, w, 0.0, 0.25))
        d['prev_insurer'] = pi if pi and not re.search(r'\d', pi) else ''
    m = re.search(r'دارای\s*\.*\s*(\d+)\s*\.*\s*مرتبه\s*خسارت', t)
    if m:
        d['prev_claims'] = m.group(1)

    # ---- پوشش‌ها (سطرهای بینِ «خطرهای اصلی» و «لیست وسایل اضافی») ----
    top = next((s for s in sp if 'خطرهای اصلی' in s['t']), None)
    bot = next((s for s in sp if 'لیست وسایل اضافی' in s['t']), None)
    if top and bot:
        cv = [s['t'].strip() for s in sorted(sp, key=lambda s: s['y0']) if top['y1'] < s['y0'] < bot['y0'] and len(s['t'].strip()) > 3]
        d['covers'] = covers_clean(' '.join(cv)) or '، '.join(cv)

    # ---- مبلغ، شروع و مدت (جمله‌ی «اینجانب ... به مبلغ ... ریال از تاریخ ... به مدت ... روز») ----
    m = re.search(r'([\d,]{9,})\s*اینجانب\s*(.+?)\s*با\s*ت[آا]یید', t) or re.search(r'اینجانب\s*(.+?)\s*با\s*ت[آا]یید[\s\S]{0,300}?([\d,]{9,})', t)
    if m:
        a, b = m.group(1), m.group(2)
        amount, applicant = (a, b) if re.match(r'[\d,]+$', a) else (b, a)
        d['car_value'] = amount.replace(',', '')
        d['applicant'], _ = honor_split(applicant)
    m = re.search(r'(\d{1,3})\s*به\s*مدت\s*(1[34]\d{2}/\d{1,2}/\d{1,2})', t) or re.search(r'از\s*تاریخ\s*(1[34]\d{2}/\d{1,2}/\d{1,2})[\s\S]{0,40}?به\s*مدت\s*(\d{1,3})', t)
    if m:
        g1, g2 = m.group(1), m.group(2)
        days, start = (g1, g2) if '/' in g2 else (g2, g1)
        d['start_date'] = pb.dates_in(start)[0] if pb.dates_in(start) else ''
        d['duration_days'] = days
    ds = pb.dates_in(t[-400:])
    if ds:
        d['proposal_date'] = ds[-1]

    d = {k: v for k, v in d.items() if v not in ('', None)}
    missing = [fa for k, fa in [('insured_name', 'نام بیمه‌گزار'), ('national_id', 'کد ملی'), ('vin', 'شماره شاسی'), ('car_value', 'ارزش خودرو')] if not d.get(k)]
    return d, missing


def main():
    if len(sys.argv) < 2:
        out({'ok': False, 'error': 'usage'}); return
    fitz = pb.load_fitz()
    if fitz is None:
        out({'ok': False, 'error': 'کتابخانه‌ی PyMuPDF روی پایتونِ سرور نصب نیست.'}); return
    try:
        doc = fitz.open(sys.argv[1])
        page = doc.load_page(0)
        text = page.get_text() or ''
    except Exception as e:
        out({'ok': False, 'error': 'فایل PDF باز نشد.', 'debug': str(e)[:200]}); return
    t = pb.normalize_text(text)
    if len(t.strip()) < 30:
        out({'ok': False, 'error': 'متنِ فایل خوانده نشد (اسکن است)؛ فایلِ PDFِ خودِ سایتِ بیمه‌گر را بارگذاری کنید.'}); return
    if re.search(r'پرسش\s*نامه\s*و\s*پیشنهاد|پیشنهاد\s*بیمه', t[:600]):
        data, missing = parse_proposal(page, text)
        out({'ok': True, 'kind': 'proposal', 'data': data, 'missing': missing}); return
    if re.search(r'بیمه\s*نامه', t[:1500]):
        data, missing = pb.extract_policy(page, text)
        data = {k: v for k, v in data.items() if v not in ('', None, 'ناشناخته')}
        if data.get('insured_name'):
            data['insured_name'], data['insured_gender'] = honor_split(data['insured_name'])
        if data.get('covers'):
            data['covers'] = covers_clean(data['covers']) or data['covers']
        ben = other_names(t, data.get('insured_name', ''))
        if ben and 'ذینفع' in t:
            data['beneficiary'], data['beneficiary_gender'] = ben[0]
        out({'ok': True, 'kind': 'draft' if not data.get('policy_num') else 'policy', 'data': data, 'missing': [m for m in missing if m != 'شماره بیمه‌نامه']}); return
    out({'ok': False, 'error': 'این فایل پیشنهاد یا پیش‌نویسِ بیمه‌نامه نیست.'})


if __name__ == '__main__':
    main()
