# -*- coding: utf-8 -*-
# فایل: api/py/intro_reader.py
# خواندنِ «معرفی‌نامه‌ی پرسنلی» از PDFِ متنی (بدونِ OCR): آیا معرفی‌نامه است؟ + نام، کد ملی، کد پرسنلی، شرکت، تاریخ و شماره‌ی نامه.
# - حروفِ «نمایشی» (ﻓﺮﯾﻤﺎ) با NFKC به حروفِ عادی برمی‌گردند؛ کشیده‌ها (عــــلی) حذف می‌شوند
# - «شاغل در این شرکت» => نامِ شرکت از امضای پایینِ نامه (مثلاً «گروه توسعه مدیریت ویانا» => «گروه ویانا»)
# استفاده: python3 intro_reader.py file.pdf   =>  JSON روی stdout
import sys, re, json, unicodedata

ALIASES = [('ویانا', 'گروه ویانا')]   # کلمه‌ای که در نامِ شرکت باشد => نامِ استانداردِ شرکت
GENERIC_COMPANY = re.compile(r'^(?:این|همین|آن)\s*(?:شرکت|مجموعه|سازمان|گروه)$|^(?:شرکت|مجموعه|سازمان)$')


def norm(t):
    if not t:
        return ''
    t = unicodedata.normalize('NFKC', t)
    t = t.replace('ي', 'ی').replace('ى', 'ی').replace('ك', 'ک').replace('ة', 'ه').replace('ۀ', 'ه')
    t = t.replace('ـ', '')                                   # کشیده
    t = re.sub(r'[ً-ْٰ]', '', t)              # اعراب (تنوین و ...)
    t = t.replace('‌', ' ').replace('‏', ' ').replace('‎', ' ')
    t = t.translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789'))
    t = re.sub(r'\s+', ' ', t)
    return t.strip()


def clean_name(s):
    s = re.sub(r'[^؀-ۿ\s]', ' ', s or '')
    s = re.sub(r'\s+', ' ', s).strip()
    s = re.sub(r'^(?:آقای|آقا|خانم|سرکار|جناب)\s+', '', s)
    return s


def clean_company(s):
    s = re.sub(r'\s+', ' ', (s or '').strip(' .،,:؛-'))
    s = re.sub(r'^(?:شرکت|موسسه|مؤسسه)\s+', '', s)
    s = re.sub(r'\s+(?:شرکت)$', '', s)
    return s.strip()


def alias(s):
    for k, v in ALIASES:
        if k in s:
            return v
    return s


END = r'(?:\s*ب\s*ل\s*ا\s*مانع|\s+(?:می\s*باشد|می\s*باشند|است|هستند|که|و\s+(?:کد|شماره))(?=\s|$|[.،])|\s*[.،])'


def body_company(t):
    """نامِ شرکت از خودِ متنِ نامه؛ «این شرکت/همین مجموعه» => خالی (یعنی برو سراغِ امضا)"""
    for pat in (r'شاغل\s*در\s*(.{2,80}?)' + END,
                r'(?:کارمند|پرسنل|کارکنان|همکار)\s*(?:محترم\s*)?(?:شرکت|مجموعه|گروه)\s+(.{2,60}?)' + END):
        for m in re.finditer(pat, t):
            raw = m.group(1).strip()
            c = clean_company(raw)
            if not c or GENERIC_COMPANY.match(raw) or GENERIC_COMPANY.match(c) or re.match(r'^(?:این|همین|آن)\b', c):
                continue
            if len(c) > 50 or re.search(r'\d', c):
                continue
            return alias(c)
    return ''


def sign_company(t):
    """نامِ شرکت از امضای پایینِ نامه (بعد از «با تشکر» / «سرمایه انسانی»)"""
    tail = t[t.find('با تشکر'):] if 'با تشکر' in t else t[-250:]
    m2 = re.search(r'((?:گروه|شرکت|هلدینگ|موسسه)\s+[\u0600-\u06FF ]{2,50})', tail)
    comp = clean_company(m2.group(1)) if m2 else ''
    comp = re.sub(r'\s+(?:مدیر|سرمایه|انسانی|معاونت|امور|واحد|منابع)(?:\s.*)?$', '', comp).strip()
    if GENERIC_COMPANY.match(comp) or comp in ('شرکت', 'گروه'):
        comp = ''
    if not comp or not any(k in comp for k, _ in ALIASES):
        for k, v in ALIASES:   # نامِ شناخته‌شده‌ای که هرجای امضای نامه آمده باشد
            if k in tail:
                return v
    return alias(comp) if comp else ''


def extract(text):
    t = norm(text)
    score = sum(1 for k in ['پرسنلی', 'شاغل', 'لامانع', 'خانم', 'کد ملی', 'سرمایه انسانی', 'صدور بیمه', 'ثالث/بدنه', 'کارگزاری'] if k in t)
    d = {'ins_type': 'ناشناخته', 'insured_name': '', 'national_id': '', 'personnel_id': '', 'company_name': '', 'letter_date': '', 'letter_no': ''}
    is_intro = ('پرسنلی' in t and ('شاغل' in t or 'لامانع' in t)) or score >= 4
    # نام: «آقای / خانم فریما کیهانی (به) شماره پرسنلی ...»
    m = re.search(r'(?:آقا(?:ی)?\s*/\s*خانم|خانم\s*/\s*آقا(?:ی)?|آقای|خانم)\s+(.{2,60}?)\s+(?:(?:به|با)\s+)?شماره\s*(?:ی\s*)?پرسنلی', t)
    if m:
        d['insured_name'] = clean_name(m.group(1))
    m = re.search(r'پرسنلی\s*[:]?\s*(\d{3,12})', t)
    if m:
        d['personnel_id'] = m.group(1)
    m = re.search(r'(?:کد|شماره)\s*ملی\s*[:]?\s*(\d{8,10})', t)
    if m:
        d['national_id'] = m.group(1).zfill(10)
    # شرکت - اولویت با متنِ نامه («شاغل در شرکت پیلسان بلامانع ...»)؛ فقط اگر متن نامِ شرکت را نگفته بود
    # (یا نوشته بود «این شرکت») از امضای پایینِ نامه برداشته می‌شود
    comp = body_company(t)
    src = 'text' if comp else ''
    if not comp:
        comp = sign_company(t)
        src = 'signature' if comp else ''
    d['company_src'] = src
    d['company_name'] = comp
    # تاریخ: «تاریخ: 14 / 07 / 1405» یا «1405/07/14»
    m = re.search(r'تاریخ\s*[:]?\s*(\d{1,4})\s*/\s*(\d{1,2})\s*/\s*(\d{1,4})', t) or re.search(r'(\d{1,4})\s*/\s*(\d{1,2})\s*/\s*(\d{1,4})\s*[:]?\s*تاریخ', t)
    if m:
        a, b, c = m.groups()
        y, md, dd = (c, b, a) if len(c) == 4 else (a, b, c)
        if len(y) == 4:
            d['letter_date'] = '%s/%02d/%02d' % (y, int(md), int(dd))
    m = re.search(r'شماره\s*[:]?\s*(\d{1,4}\s*[-–—]\s*\d{3,10}\s*[-–—]\s*\d{1,4})', t) or re.search(r'(\d{1,4}\s*[-–—]\s*\d{3,10}\s*[-–—]\s*\d{1,4})\s*[:]?\s*شماره', t)
    if m:
        d['letter_no'] = re.sub(r'[–—]', '-', re.sub(r'\s+', '', m.group(1)))
    if is_intro:
        d['ins_type'] = 'معرفی‌نامه'
    return is_intro, score, d


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else ''
    out = {'ok': False}
    try:
        try:
            import pymupdf as fitz
        except ImportError:
            import fitz
        doc = fitz.open(path)
        text = ' '.join(p.get_text() for p in doc)
        if len(norm(text)) < 40:
            out = {'ok': True, 'has_text': False, 'is_intro': False, 'score': 0, 'data': {}}
        else:
            is_intro, score, d = extract(text)
            out = {'ok': True, 'has_text': True, 'is_intro': is_intro, 'score': score, 'data': d}
    except Exception as e:
        out = {'ok': False, 'error': str(e)[:200]}
    print(json.dumps(out, ensure_ascii=False))


if __name__ == '__main__':
    main()
