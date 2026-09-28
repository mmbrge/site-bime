"""Parser for Pasargad passenger car / pickup / station-wagon PDFs."""
import re
import unicodedata

def _norm(text):
    trans = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
    text = unicodedata.normalize("NFKC", text or "").translate(trans)
    text = text.replace("ي", "ی").replace("ك", "ک").replace("ﺹ", "ص").replace("،", ",")
    return text

def parse_pdf_fields(raw_text):
    t = _norm(raw_text)
    out = {}
    
    # ۱. پلاک (Plate)
    m_plate1 = re.search(r'(\d{2})\s*ایران\s*(\d{2})\s*([آ-یA-Za-zصع])\s*(\d{3})', t)
    m_plate2 = re.search(r'(\d{3})\s*([آ-یA-Za-zصع])\s*(\d{2})\s*ایران\s*(\d{2})', t)
    
    if m_plate1:
        out.update(plate_part1=m_plate1.group(2), plate_letter=m_plate1.group(3), plate_part2=m_plate1.group(4), plate_part3=m_plate1.group(1))
    elif m_plate2:
        out.update(plate_part1=m_plate2.group(3), plate_letter=m_plate2.group(2), plate_part2=m_plate2.group(1), plate_part3=m_plate2.group(4))

    # ۲ و ۳. شماره شاسی و موتور (Chassis & Engine)
    raw_words = re.findall(r'\b[A-Za-z0-9\-]{6,24}\b', t)
    candidates = []
    for w in raw_words:
        if re.match(r'^\d{10,11}$', w) or re.match(r'^(13|14)\d{2}$', w) or re.match(r'^\d{4}$', w):
            continue
        candidates.append(w.upper())
        
    candidates = list(dict.fromkeys(candidates))
    
    chassis_cand = None
    motor_cand = None
    
    for cand in candidates:
        if len(cand) == 17:
            chassis_cand = cand
            break
    if not chassis_cand:
        for cand in candidates:
            if 13 <= len(cand) <= 17:
                chassis_cand = cand
                break
                
    remaining = [c for c in candidates if c != chassis_cand]
    if remaining:
        remaining.sort(key=len, reverse=True)
        motor_cand = remaining[0]

    if chassis_cand:
        out["shasi"] = out["chassis_no"] = chassis_cand
    if motor_cand:
        out["motor"] = out["engine_no"] = motor_cand

    # ۴. سال ساخت (Year)
    year = re.search(r'\b(13[4-9]\d|14[0-1]\d)\b', t)
    if year:
        out["sal_sakht"] = out["year"] = year.group(1)

    # ۵. رنگ (Color)
    color = re.search(r'(سفید|مشکی|خاکستری|نقره\s*ای|نوک\s*مدادی|قرمز|آبی|سرمه\s*ای|قهوه\s*ای|زرد|سبز)\s*(روغنی|متالیک|صدفی)?', t)
    if color:
        out["rang"] = out["color"] = color.group(0).strip()

    # ۶. مورد استفاده (Usage)
    usage = re.search(r'(شخصی|آژانس|کرایه|تاکسی|آموزش|دولتی|عمومی|بارکش)', t)
    if usage:
        out["mordes"] = out["usage"] = usage.group(0).strip()

    # ۷. نوع، سیستم و تیپ خودرو (Vehicle Type)
    brands = r'(پژو|پراید|سمند|تیبا|دنا|تارا|شاهین|کوییک|ساینا|رانا|ام\s*وی\s*ام|MVM|چری|جک|لیفان|تویوتا|هیوندای|کیا|رنو|بنز|بی\s*ام\s*و|نیسان|پارس|TU5|LX)'
    m_type = re.search(r'(سواری|وانت)?\s*' + brands + r'[A-Za-z0-9\sآ-ی]{0,15}', t, re.I)
    if m_type:
        val = m_type.group(0).strip()
        val = re.sub(r'(رنگ|مدل|سال|ظرفیت|سیستم|تیپ|نوع|وسیله|شماره).*', '', val).strip()
        out["noecar"] = out["vehicle_type"] = val

    # ۸. تعداد سیلندر (Cylinders)
    cyl = re.search(r'(?:سیلندر|تعداد\s*سیلندر)\s*[:：\n]?\s*(\d)', t)
    if cyl:
        out["cylinder_count"] = cyl.group(1)

    # ۹. ارزش / مبلغ وسیله نقلیه (Value)
    val_cands = []
    clean_for_nums = t.replace(',', '').replace('،', '').replace('.', '')
    
    # جستجوی مقادیری که حداقل 7 رقم بوده و با 000 تمام می‌شوند (ریال)
    for match in re.finditer(r'\d{7,15}', clean_for_nums):
        num_str = match.group(0)
        if num_str.endswith('000'):
            val_cands.append(int(num_str))

    if val_cands:
        max_val = str(max(val_cands))
        out["arzesh"] = out["insured_value"] = max_val

    # ۱۰. کد ملی یا شناسه حقوقی (National ID)
    ids_11 = re.findall(r'\b(10\d{9}|14\d{9})\b', t)
    if ids_11:
        out["national_id"] = ids_11[0]
    else:
        ids_10 = re.findall(r'\b([1-9]\d{9})\b', t)
        if ids_10:
            out["national_id"] = ids_10[0]

    # ۱۱. تلفن (Phone)
    phone = re.search(r'\b(0[1-9]\d{9})\b', t)
    if phone:
        out["phone_bimeg"] = phone.group(1)

    # ۱۲. آدرس (Address)
    addr_match = re.search(r'(استان\s+[آ-ی\s،,-]+(?:\s*پلاک\s*\d+|\s*طبقه\s*\d+)?)(?=\s*کد\s*پستی|\s*تلفن|\n)', t)
    if not addr_match:
        addr_match = re.search(r'(استان\s+.*?)(?=\s*کد\s*پستی|\s*تلفن|021|\n\s*\n)', t)
    if addr_match:
        out["addres_bimeg"] = addr_match.group(1).replace("کد پستی", "").strip(" ،,-:")

    # ۱۳. نام و نام خانوادگی بیمه‌گذار (Name)
    name_match = re.search(r'نام\s*و\s*نام\s*خانوادگی\s*[:\n]*([آ-ی\s]+?)(?=\s*کد\s*ملی)', t)
    if not name_match:
        name_match = re.search(r'([آ-ی\s]+?)(?:[:\n]*کد\s*ملی)', t)
        
    if name_match:
        n = name_match.group(1).strip()
        # فیلتر هوشمند: پاکسازی کلمات اضافی و لیبل‌هایی که قبل از نام چسبیده‌اند
        n = re.sub(r'(مشخصات\s*متقاضی|ذینفع|نشانی|تلفن|شغل|بیمه\s*گزار|نام\s*و\s*نام\s*خانوادگی)', '', n).strip()
        if len(n) > 3:
            out["name"] = n

    return {k: v for k, v in out.items() if v}