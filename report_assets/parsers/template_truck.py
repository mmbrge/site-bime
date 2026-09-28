import re, importlib.util, unicodedata
from pathlib import Path

# تلاش برای بارگذاری پارسر مشترک جهت استفاده به عنوان پایه
try:
    _spec = importlib.util.spec_from_file_location("shared_car_parser", Path(__file__).with_name("template_car.py"))
    _module = importlib.util.module_from_spec(_spec)
    _spec.loader.exec_module(_module)
    _car = _module.parse_pdf_fields
except Exception:
    _car = None

def _fix_digits(s):
    if not s: return ""
    mapping = {
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
        '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
        '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
        '،': ',', 'ﻉ': 'ع', 'ي': 'ی', 'ك': 'ک'
    }
    for k, v in mapping.items():
        s = s.replace(k, v)
    s = re.sub(r'\(cid:\d+\)', ' ', s)
    return unicodedata.normalize('NFKC', s)

def parse_pdf_fields(raw_text):
    out = _car(raw_text) if _car else {}
    if not out: out = {}
    
    t = _fix_digits(raw_text or "")
    
    # ۱. پلاک (پشتیبانی کامل از ساختارهای درهم‌ریخته و حرف ع)
    m_plate_1 = re.search(r'(\d{2})\s*ایران\s*(\d{2})\s*([a-zA-Zآ-یع])\s*(\d{3})', t)
    if m_plate_1:
        out['plate_part3'] = m_plate_1.group(1)
        out['plate_part1'] = m_plate_1.group(2)
        out['plate_letter'] = m_plate_1.group(3)
        out['plate_part2'] = m_plate_1.group(4)
    else:
        m_plate_2 = re.search(r'(\d{2})\s*([a-zA-Zآ-یع])\s*(\d{3})\s*ایران\s*(\d{2})', t)
        if m_plate_2:
            out['plate_part1'] = m_plate_2.group(1)
            out['plate_letter'] = m_plate_2.group(2)
            out['plate_part2'] = m_plate_2.group(3)
            out['plate_part3'] = m_plate_2.group(4)

    # ۲. شاسی (دقیقاً ۱۷ کاراکتر و پاکسازی از متن برای جلوگیری از تداخل با موتور)
    m_chassis = re.findall(r'(?<![A-Za-z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Za-z0-9])', t, re.I)
    valid_chassis = [c for c in m_chassis if re.search(r'[A-Za-z]', c) and re.search(r'[0-9]', c)]
    if valid_chassis:
        out["shasi"] = valid_chassis[0].upper()
        t = t.replace(valid_chassis[0], ' ')
        
    # ۳. موتور (۶ تا ۱۵ کاراکتر با فیلتر کدهای ملی و سال)
    engine_cands = re.findall(r'(?<![A-Za-z0-9])([A-Z0-9\-]{6,15})(?![A-Za-z0-9])', t, re.I)
    valid_engines = [c for c in engine_cands if not (c.isdigit() and len(c) in (10, 11) and c.startswith('0')) and not re.match(r'^(13|14)\d{2}$', c)]
    if valid_engines:
        alphanumeric_engines = [c for c in valid_engines if re.search(r'[A-Za-z]', c)]
        if alphanumeric_engines:
            out["motor"] = max(alphanumeric_engines, key=len).upper()
        else:
            out["motor"] = max(valid_engines, key=len).upper()

    # ۴. سال ساخت
    m_year = re.search(r'\b(13[4-9]\d|14[0-1]\d)\b', t)
    if m_year: out["sal_sakht"] = m_year.group(1)

    # ۵. رنگ
    m_color = re.search(r'(سفید|مشکی|قرمز|آبی|نقره\s*ای|نوک\s*مدادی|خاکستری|سبز|زرد|نارنجی|قهوه\s*ای|کرم|بژ|زرشکی|سربی|دلفینی)(?:\s*(روغنی|متالیک|صدفی))?', t)
    if m_color: out["rang"] = m_color.group(0).strip()

    # ۶. نوع، کاربری، ظرفیت، سیلندر و سیستم/تیپ
    m_type = re.search(r'(تریلرکش|کشنده|کامیون|کامیونت|وانت|مینی\s*بوس|اتوبوس|تراکتور|کمپرسی|بارکش)', t)
    if m_type: out["noecar"] = m_type.group(1).strip()

    m_usage = re.search(r'(بارکش|مسافربر|شخصی|عمومی|کشاورزی|راهسازی|صنعتی|آموزشی)', t)
    if m_usage: out["mordes"] = m_usage.group(1).strip()
        
    m_cap = re.search(r'(\d{1,3})\s*تن', t)
    if m_cap: out["zarfiat"] = m_cap.group(1) + " تن"

    m_cyl = re.search(r'سیلندر\s*[:\s]*(\d+)|(\d+)\s*(?:تعداد\s*)?سیلندر', t)
    if m_cyl: out["cylinder_count"] = m_cyl.group(1) or m_cyl.group(2)

    m_brand = re.search(r'(پیلسان|اسکانیا|ولوو|بنز|فاو|سیبا|دانگ\s*فنگ|هوو|الوند|آمیکو|ایسوزو|کاویان|هیوندای|رنو|شاکمان|دیما|داف|ماک|ایویکو|فوتون|جک|کاما)[ \t]*([A-Za-z0-9\-]+)?', t)
    if m_brand: out["brand"] = m_brand.group(0).strip()

    # ۷. ارزش و مبلغ (شناسایی مستقل و هوشمند با انتخاب بزرگترین رقم ریالی)
    val_cands = []
    clean_for_nums = t.replace(',', '').replace('،', '').replace('.', '')
    # جستجوی تمام اعداد بالای ۷ رقم که به 000 ختم می‌شوند (مبالغ ریالی)
    for match in re.finditer(r'\d{7,15}', clean_for_nums):
        num_str = match.group(0)
        if num_str.endswith('000'):
            val_cands.append(int(num_str))

    if val_cands:
        out["arzesh"] = str(max(val_cands))

    # ۸. اطلاعات هویتی و آدرس (شخص حقیقی/حقوقی)
    m_nat = re.search(r'(?!0\d)(\d{10,11})(?!\d)', t)
    if m_nat: out["national_id"] = m_nat.group(1)
         
    m_phone = re.search(r'(09\d{9}|0\d{7,10})(?!\d)', t)
    if m_phone: out["phone_bimeg"] = m_phone.group(1)

    m_name = re.search(r'([^\n:]+?)\s*:\s*کد\s*ملی', t)
    if not m_name: m_name = re.search(r'(?:نام\s*و\s*نام\s*خانوادگی|اینجانب)\s*[:\s]*([^\n\d]+?)(?:\n|\r|کد|با|:|ملی)', t)
    if m_name:
        cl_name = m_name.group(1).replace(':', '').replace('کد ملی', '').strip()
        if len(cl_name) > 2: 
            out["name"] = re.sub(r'(?:نام|متقاضی|بیمه|گزار|مشخصات|اینجانب)', '', cl_name).strip()

    m_addr = re.search(r'(استان\s+.*?)(?:کد\s*پستی|تلفن|شغل|نام\s*و\s*نام)', t, re.DOTALL)
    if m_addr:
        addr = m_addr.group(1).replace('\n', ' ').replace('-', ' ').strip()
        addr = re.sub(r'\s+', ' ', addr)
        out["addres_bimeg"] = addr

    # ۹. همگام‌سازی فیلدهای اختصاصی خودروهای سنگین (الگوی sa)
    sync_pairs = [
        ("shasi", "chassis_no", "shasisa"),
        ("motor", "engine_no", "motorsa"),
        ("sal_sakht", "year", "sal_sakhtsa"),
        ("rang", "color", "rangsa"),
        ("mordes", "usage", "mordessa"),
        ("noecar", "vehicle_type", "noecarsa"),
        ("arzesh", "insured_value", "arzeshsa"),
        ("brand", "tip"),
        ("zarfiat", "capacity")
    ]
    for group in sync_pairs:
        val = next((out[k] for k in group if out.get(k)), "")
        if val:
            for k in group: out[k] = val

    # ۱۰. حفظ فیلدهای مختص یدک
    patterns = {
        "lastikka": r"وضعیت\s*لاستیک\s*[:：]?\s*([^\n]+)", 
        "yadaknk": r"نوع\s*یدک\s*[:：]?\s*([^\n]+)",
        "pelakk": r"شماره\s*شهربانی\s*[:：]?\s*([^\n]+)", 
        "salsayk": r"سال\s*ساخت\s*یدک\s*[:：]?\s*(\d{4})",
        "rangyk": r"رنگ\s*یدک\s*[:：]?\s*([^\n]+)", 
        "termoshk": r"ترموکینگ\s*[:：]?\s*([^\n]+)",
        "shasiyk": r"شاسی\s*یدک\s*[:：]?\s*([A-Za-z0-9-]+)",
        "arzeshyakchal": r"ارزش\s*یخچال[^\d]*([\d,]+)", 
        "arzeshyadak": r"ارزش\s*یدک[^\d]*([\d,]+)"
    }
    for key, pattern in patterns.items():
        m = re.search(pattern, t, re.I)
        if m: 
            out[key] = m.group(1).strip().replace(",", "") if "arzesh" in key else m.group(1).strip()
            
    return {k: v for k, v in out.items() if v}