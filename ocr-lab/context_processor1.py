import re
import unicodedata

def normalize_text(text):
    if not text:
        return ""
    text = unicodedata.normalize("NFKC", text)
    text = text.replace('ي', 'ی').replace('ك', 'ک').replace('‌', ' ')
    text = text.replace('ـ', '')
    for p, e in zip("۰۱۲۳۴۵۶۷۸۹", "0123456789"):
        text = text.replace(p, e)
    return text

# ==========================================================
#  استخراج اختصاصی بیمه نامه‌های "بدنه" و "ثالث" پاسارگاد
# ==========================================================
POLICY_NUM_RE = re.compile(r'\b(\d/\d{4,5}/\d{3,4}-\d/\d{3,4}/\d{2,4})\b')

def _extract_policy_num(text):
    m = POLICY_NUM_RE.search(text)
    return m.group(1) if m else ""

def _win_before(label, text, window=250):
    idx = text.find(label)
    if idx == -1:
        return ""
    return text[max(0, idx - window):idx]

def _win_after(label, text, window=250):
    idx = text.find(label)
    if idx == -1:
        return ""
    end = idx + len(label)
    return text[end:end + window]

def _clean_name(name):
    name = name.strip()
    prev = None
    while prev != name:
        prev = name
        name = re.sub(r'^(?:جناب\s*آقای|سرکار\s*خانم|آقای|خانم|شرکت)\s*', '', name).strip()
    return re.sub(r'\s+', ' ', name).strip()

def _extract_plate_pasargad(text):
    """پلاک را دقیقاً مطابق ظاهر فیزیکی پلاک می‌سازد (چپ به راست):
    «68ایران - 711 د 16»."""
    m = re.search(r'(\d{2})\s*ایران\s*(\d{2})\s*([آ-ی])\s*(\d{3})', text)
    if m:
        iran_box, two, letter, three = m.groups()
        return f"{iran_box}ایران - {three} {letter} {two}"
    return ""

def _extract_coverages(text, keywords):
    coverages = {}
    for m in re.finditer(r'([آ-یA-Za-z][^\n\d]{3,90}?)(-?[\d][\d,]{2,})\s*\n', text):
        desc = re.sub(r'[()%\d]', '', m.group(1)).strip(' ,:()،')
        amount = m.group(2)
        if not keywords or any(k in desc for k in keywords):
            coverages[desc] = amount
    return coverages

def extract_pasargad_body(text_norm):
    data = {
        "policy_num": "", "unique_code": "", "insured_name": "",
        "national_id": "", "phone": "", "plate": "", "issue_date": "",
        "car_system": "", "car_type": "", "model_year": "", "engine_num": "",
        "chassis_num": "", "vin": "", "car_color": "", "car_value": "",
        "total_premium": "", "coverages": {}, "prev_policy": "",
        "renewal_status": "",
    }

    data["policy_num"] = _extract_policy_num(text_norm)

    m = re.search(r'([\d/\-]{5,})\s*:بیمه نامه سال قبل', text_norm)
    if m and m.group(1).strip():
        data["prev_policy"] = m.group(1).strip()
        data["renewal_status"] = "تمدیدی (دارای بیمه نامه سال قبل)"
    else:
        data["renewal_status"] = "صدور اول"

    m = re.search(r'(جناب\s*آقای|آقای|خانم|سرکار\s*خانم)\s*([آ-ی\s]+?)(\d{10})', text_norm)
    if m:
        data["insured_name"] = _clean_name(m.group(1) + " " + m.group(2))
        data["national_id"] = m.group(3)

    data["plate"] = _extract_plate_pasargad(text_norm)

    m = re.search(r':\s*واحد صدور\s*(\d{4}/\d{1,2}/\d{1,2})', text_norm)
    if not m:
        m = re.search(r'(\d{4}/\d{1,2}/\d{1,2})\s*(?:\d{1,2}:\d{2}:\d{2})?\s*:\s*تاریخ صدور', text_norm)
    if m:
        data["issue_date"] = m.group(1)

    m_chassis = re.search(r'([A-Za-z0-9]{11,20})\s*\n\s*\d{2}\s*ایران', text_norm)
    if m_chassis:
        data["chassis_num"] = m_chassis.group(1)
    m_vin = re.search(r'VIN:\s*([A-Za-z0-9]{6,20})', text_norm)
    if m_vin:
        data["vin"] = m_vin.group(1)
    elif data["chassis_num"]:
        data["vin"] = data["chassis_num"]

    m_eng = re.search(r'\b(\d{3}[A-Za-z]\d{6,8})\b', text_norm)
    if m_eng:
        data["engine_num"] = m_eng.group(1)

    m_model = re.search(r'(?<!\d)(1[34]\d{2})\s*\n\s*\d{3}[A-Za-z]\d{6,8}', text_norm)
    if m_model:
        data["model_year"] = m_model.group(1)

    m_sys = re.search(r'\n([آ-ی]+)([A-Za-z0-9][A-Za-z0-9\s\-]*)\s*\n-?\s*\n', text_norm)
    if m_sys:
        data["car_system"] = m_sys.group(1).strip()
        data["car_type"] = m_sys.group(2).strip()

    m_color = re.search(r'\n([آ-ی]+)\nسواری\n', text_norm)
    if m_color:
        data["car_color"] = m_color.group(1).strip()

    ctx = _win_before(': ارزش وسیله نقلیه', text_norm, 200)
    m_val = re.search(r'ریال\s*([\d,]+)', ctx)
    if m_val:
        data["car_value"] = m_val.group(1)

    ctx2 = _win_after(': مبلغ قابل پرداخت', text_norm, 250)
    m_tot = re.search(r'ریال\s*([\d,]+)', ctx2)
    if m_tot:
        data["total_premium"] = m_tot.group(1)
    else:
        ctx2b = _win_before(': مبلغ قابل پرداخت', text_norm, 40)
        m_tot = re.search(r'ریال\s*([\d,]+)', ctx2b)
        if m_tot:
            data["total_premium"] = m_tot.group(1)

    data["coverages"] = _extract_coverages(
        text_norm,
        ["آتش سوزی", "جنگ", "نوسان قیمت", "پاشیدگی", "شکست شیشه", "سیل", "سرقت لوازم"]
    )
    return data

def extract_pasargad_third(text_norm):
    data = {
        "policy_num": "", "unique_code": "", "insured_name": "",
        "national_id": "", "phone": "", "plate": "", "issue_date": "",
        "car_system": "", "car_type": "", "model_year": "", "engine_num": "",
        "chassis_num": "", "vin": "", "car_color": "", "car_value": "",
        "total_premium": "", "coverages": {}, "prev_policy": "",
        "renewal_status": "",
    }

    data["policy_num"] = _extract_policy_num(text_norm)
    if not data["policy_num"]:
        m = re.search(r'([\d/\-]{8,})\s*:شماره بیمه نامه', text_norm)
        if m:
            data["policy_num"] = m.group(1).strip()

    ctx = _win_before(':کد یکتا بیمه مرکزی', text_norm, 40)
    m2 = re.search(r'(\d{8,15})', ctx)
    if m2:
        data["unique_code"] = m2.group(1)

    ctx_prev = _win_before(':بیمه نامه سال قبل', text_norm, 60)
    m2 = re.search(r'([\d/]{5,})', ctx_prev)
    if m2:
        data["prev_policy"] = m2.group(1)
        data["renewal_status"] = "تمدیدی (دارای بیمه نامه سال قبل)"
    else:
        data["renewal_status"] = "صدور اول"

    m = re.search(r'بیمه گذار:\s*([^\n]+)', text_norm)
    if m:
        data["insured_name"] = _clean_name(m.group(1))

    m = re.search(r'(\d{10})\s*:کدملی', text_norm)
    if m:
        data["national_id"] = m.group(1)

    m = re.search(r'(\d{4}-\d{6,8})\s*:تلفن', text_norm)
    if m:
        data["phone"] = m.group(1)

    data["plate"] = _extract_plate_pasargad(text_norm)

    m = re.search(r'سیستم:\s*([آ-ی]+)([A-Za-z0-9][A-Za-z0-9\-]*)\s*:تیپ', text_norm)
    if m:
        data["car_system"] = m.group(1).strip()
        data["car_type"] = m.group(2).strip()

    m = re.search(r'(\d{4})\s*:مدل', text_norm)
    if m:
        data["model_year"] = m.group(1)

    m = re.search(r'رنگ:\s*([آ-ی\s]+?)\s*ظرفیت', text_norm)
    if m:
        data["car_color"] = m.group(1).strip()

    m = re.search(r'\b([A-Za-z0-9]{6,15})\b\s*:شماره موتور', text_norm)
    if m:
        data["engine_num"] = m.group(1)

    m = re.search(r'\b([A-Za-z0-9]{6,20})\b\s*:شماره شاسی', text_norm)
    if m:
        data["chassis_num"] = m.group(1)

    m = re.search(r'VIN:\s*([A-Za-z0-9]{6,20})', text_norm)
    if m:
        data["vin"] = m.group(1)
    elif data["chassis_num"]:
        data["vin"] = data["chassis_num"]

    m = re.search(r'(\d{4}/\d{1,2}/\d{1,2})\s*[\d:]*\s*:تاریخ صدور', text_norm)
    if m:
        data["issue_date"] = m.group(1)

    m2 = re.search(r'\n([\d,]+)\s*\n:جمع کل', text_norm)
    if m2:
        data["total_premium"] = m2.group(1)

    for m_cov in re.finditer(r'(حق بیمه [^\n\d]{2,40}?|تخفیفات|جریمه خسارت|مالیات و عوارض)(-?[\d,]+)', text_norm):
        data["coverages"][m_cov.group(1).strip()] = m_cov.group(2)

    return data


def extract_insurance_data(raw_text):
    text_norm = normalize_text(raw_text)

    data = {
        "ins_type": "ناشناخته",
        "ins_company": "ناشناخته",
        "insured_name": "",
        "national_id": "",
        "personnel_id": "",
        "company_name": "",
        "letter_date": "",
        "plate": "",
        "vin": "",
        "policy_num": "",
        # فیلدهای تکمیلی پاسارگاد (بدنه/ثالث)
        "unique_code": "",
        "phone": "",
        "issue_date": "",
        "car_system": "",
        "car_type": "",
        "model_year": "",
        "engine_num": "",
        "chassis_num": "",
        "car_color": "",
        "car_value": "",
        "total_premium": "",
        "coverages": {},
        "prev_policy": "",
        "renewal_status": "",
    }

    # تشخیص معرفی‌نامه (باید هم‌زمان چند نشانه با هم باشند تا با بیمه نامه‌های
    # پاسارگاد که نام کارگزار «آرام مانا» را هم دارند اشتباه گرفته نشود)
    if ("پرسنلی" in text_norm and "شاغل در" in text_norm) or "معرفی نامه" in text_norm:
        data["ins_type"] = "معرفی‌نامه"

        # ۱. استخراج نام شخص (سازگار با به هم ریختگی کلمه "به نام")
        m_name = re.search(r'(?:آقا[یي]\s*[/\\-]?\s*خانم|آقا[یي]|خانم)\s*([آ-ی\s]+?)\s*(?:به\s*شماره|با\s*شماره)', text_norm)
        if m_name:
            data["insured_name"] = re.sub(r'\s+', ' ', m_name.group(1).strip())

        # ۲. استخراج کد پرسنلی
        m_pid = re.search(r'پرسنلی[\s\n:]*(\d{4,10})', text_norm)
        if m_pid:
            data["personnel_id"] = m_pid.group(1).strip()

        # ۳. استخراج کد ملی
        m_nid = re.search(r'(?:ملی|کد)[\s\n:]*(\d{10})', text_norm)
        if m_nid:
            data["national_id"] = m_nid.group(1).strip()

        # ۴. استخراج نام شرکت (پوشش فاصله افتادن بین حروف مثل "ب لامانع")
        m_comp = re.search(r'شاغل\s*در\s*([آ-یa-zA-Z0-9\s]+?)\s*ب\s*لامانع', text_norm)
        if m_comp:
            comp = re.sub(r'\s+', ' ', m_comp.group(1).strip())
            comp = re.sub(r'^شرکت\s+', '', comp).strip()
            data["company_name"] = comp
        else:
            data["company_name"] = "دنیای ماموت"

        # ۵. استخراج تاریخ و تبدیل هوشمند به فرمت استاندارد 1405/06/10
        m_date = re.search(r'تاریخ[\s\n:؛-]*(\d{2,4})\s*/\s*(\d{1,2})\s*/\s*(\d{2,4})', text_norm)
        if m_date:
            p1, p2, p3 = m_date.groups()
            if len(p3) == 4:
                data["letter_date"] = f"{p3}/{p2.zfill(2)}/{p1.zfill(2)}"
            elif len(p1) == 4:
                data["letter_date"] = f"{p1}/{p2.zfill(2)}/{p3.zfill(2)}"
            else:
                data["letter_date"] = f"{p1}/{p2}/{p3}"

        # ۶. اطمینان از خالی بودن فیلدهای نامربوط در معرفی‌نامه
        data["plate"] = ""
        data["vin"] = ""
        data["policy_num"] = ""

    else:
        head_text = text_norm[:800]
        if "pasargadinsurance" in text_norm.lower() or "پاسارگاد" in head_text or "پاسارگاد" in text_norm:
            data["ins_company"] = "پاسارگاد"
        elif "بیمه ایران" in head_text:
            data["ins_company"] = "ایران"

        if "بیمه نامه بدنه وسیله نقلیه" in text_norm or ("بدنه" in text_norm[:400] and "ارزش وسیله نقلیه" in text_norm):
            data["ins_type"] = "بدنه"
        elif "ثالث" in text_norm or "حوادث راننده" in text_norm:
            data["ins_type"] = "ثالث"
        elif "بدنه" in text_norm:
            data["ins_type"] = "بدنه"

        if data["ins_company"] == "پاسارگاد" and data["ins_type"] in ("بدنه", "ثالث"):
            pdata = extract_pasargad_body(text_norm) if data["ins_type"] == "بدنه" else extract_pasargad_third(text_norm)
            data["policy_num"] = pdata["policy_num"]
            data["insured_name"] = pdata["insured_name"]
            data["national_id"] = pdata["national_id"]
            data["vin"] = pdata["vin"] or pdata["chassis_num"]
            data["plate"] = pdata["plate"]
            data["unique_code"] = pdata["unique_code"]
            data["phone"] = pdata["phone"]
            data["issue_date"] = pdata["issue_date"]
            data["car_system"] = pdata["car_system"]
            data["car_type"] = pdata["car_type"]
            data["model_year"] = pdata["model_year"]
            data["engine_num"] = pdata["engine_num"]
            data["chassis_num"] = pdata["chassis_num"]
            data["car_color"] = pdata["car_color"]
            data["car_value"] = pdata["car_value"]
            data["total_premium"] = pdata["total_premium"]
            data["coverages"] = pdata["coverages"]
            data["prev_policy"] = pdata["prev_policy"]
            data["renewal_status"] = pdata["renewal_status"]
        else:
            m_nid = re.search(r'\b(\d{10})\b', text_norm)
            if m_nid:
                data["national_id"] = m_nid.group(1)

    return data
