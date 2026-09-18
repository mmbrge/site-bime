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
        "chassis_num": "",
        "engine_num": "",
        "total_premium": "",
        "unique_code": ""
    }

    # ---------------------------------------------------------------
    # تشخیص معرفی‌نامه - نکته‌ی مهم (باگ واقعی که حین تست پیدا و رفع شد): چک قبلی («معرفی» یا
    # «آرام مانا» به‌تنهایی) روی خودِ بیمه‌نامه‌های صادرشده هم غلط trigger می‌شد، چون «آرام مانا»
    # (نام کارگزاری) روی سربرگ بیمه‌نامه‌های پاسارگاد هم چاپ شده. الان فقط ترکیب دقیق‌تر و
    # اختصاصیِ معرفی‌نامه («شماره پرسنلی» + «شاغل در» با هم) تشخیص داده می‌شود.
    if "پرسنلی" in text_norm and "شاغل در" in text_norm:
        data["ins_type"] = "معرفی‌نامه"

        m_name = re.search(r'(?:آقا[یي]\s*[/\\-]?\s*خانم|آقا[یي]|خانم)\s*([آ-ی\s]+?)\s*(?:به\s*شماره|با\s*شماره)', text_norm)
        if m_name:
            data["insured_name"] = re.sub(r'\s+', ' ', m_name.group(1).strip())

        m_pid = re.search(r'پرسنلی[\s\n:]*(\d{4,10})', text_norm)
        if m_pid:
            data["personnel_id"] = m_pid.group(1).strip()

        m_nid = re.search(r'(?:ملی|کد)[\s\n:]*(\d{10})', text_norm)
        if m_nid:
            data["national_id"] = m_nid.group(1).strip()

        m_comp = re.search(r'شاغل\s*در\s*([آ-یa-zA-Z0-9\s]+?)\s*ب\s*لامانع', text_norm)
        if m_comp:
            comp_clean = re.sub(r'\s+', ' ', m_comp.group(1).strip())
            # طبق درخواست: اگر واژه‌ی «شرکت» هم جزو متن خوانده‌شده باشد، از نام شرکت حذف شود
            comp_clean = re.sub(r'^شرکت\s*|\s*شرکت$', '', comp_clean).strip()
            data["company_name"] = comp_clean
        else:
            data["company_name"] = "دنیای ماموت"

        m_date = re.search(r'تاریخ[\s\n:؛-]*(\d{2,4})\s*/\s*(\d{1,2})\s*/\s*(\d{2,4})', text_norm)
        if m_date:
            p1, p2, p3 = m_date.groups()
            if len(p3) == 4:
                data["letter_date"] = f"{p3}/{p2.zfill(2)}/{p1.zfill(2)}"
            elif len(p1) == 4:
                data["letter_date"] = f"{p1}/{p2.zfill(2)}/{p3.zfill(2)}"
            else:
                data["letter_date"] = f"{p1}/{p2}/{p3}"

        data["plate"] = ""
        data["vin"] = ""
        data["policy_num"] = ""
        return data

    # ---------------------------------------------------------------
    # تشخیص بیمه‌نامه‌ی صادرشده (ثالث/بدنه) - پورت‌شده از الگوریتم دسکتاپی
    # که قبلاً روی نمونه‌های واقعی بیمه پاسارگاد تست و تایید شده بود
    # ---------------------------------------------------------------
    head_text = text_norm[:800]
    if "pasargadinsurance" in text_norm.lower() or "پاسارگاد" in head_text:
        data["ins_company"] = "پاسارگاد"
    elif "10103858742" in text_norm or "بیمه ایران" in head_text:
        data["ins_company"] = "ایران"

    if "ثالث" in text_norm or "ثالت" in text_norm or "تعهد جانی" in text_norm or "حوادث راننده" in text_norm:
        data["ins_type"] = "ثالث"
    elif "بدنه" in text_norm or "ارزش وسیله نقلیه" in text_norm:
        data["ins_type"] = "بدنه"

    # شماره‌ی بیمه‌نامه
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

    # جایگزینی اسلش معمولی با «∕» (U+2215 DIVISION SLASH) طبق قرارداد نام‌گذاری فایل‌ها
    policy_num = policy_num.replace(' ', '')
    data["policy_num"] = policy_num.replace('/', '∕')

    # نام بیمه‌گذار
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

    data["insured_name"] = insured_name

    # کد ملیِ بیمه‌گذار (برای تطبیق با پرونده - در نسخه‌ی وین وجود نداشت، این‌جا اضافه شد)
    m_nid2 = re.search(r'کد\s*ملی\s*[:\n]*\s*(\d{10})', text_norm)
    if m_nid2:
        data["national_id"] = m_nid2.group(1)

    # شماره موتور و شماره شاسی (جدا از VIN) - طبق درخواست، فیلد جداگانه
    # نکته‌ی مهم (باگ رفع‌شده حین تست): الگوی «لیبل سپس مقدار» اگر با \n مجاز باشد، وقتی برچسبِ
    # دوم بلافاصله زیرِ برچسبِ اول می‌آید (مثل «...شماره موتور»\n«مقدار :شماره شاسی») مقدارِ خط
    # بعدی را اشتباهی می‌قاپد. برای همین، اول الگوی رایج‌ترِ «مقدار سپس لیبل» (بدون عبور از خط)
    # را امتحان می‌کنیم و فقط به‌عنوان جایگزین سراغ حالت دیگر می‌رویم.
    for key, label in [('engine_num', 'موتور'), ('chassis_num', 'شاسی')]:
        m_ec = re.search(r'([A-Za-z0-9]{5,17})\s*:\s*شماره\s*' + label, text_norm)
        if not m_ec:
            m_ec = re.search(r'شماره\s*' + label + r'\s*:\s*([A-Za-z0-9]{5,17})(?!\s*\n)', text_norm)
        data[key] = m_ec.group(1) if m_ec else ""

    # حق بیمه‌ی کل (مبلغ نهایی قابل پرداخت) - رایج‌ترین چیدمان «مبلغ سپس برچسبِ جمع کل»،
    # و برای بیمه‌نامه‌هایی که به‌صورت قسطی پرداخت می‌شوند (بدون یک عدد «مبلغ قابل پرداخت» واحد)
    # جایگزین می‌شود با «مبلغ قابل پرداخت»
    m_premium = re.search(r'([\d,]{5,})\s*\n?\s*:\s*جمع\s*کل', text_norm)
    if not m_premium:
        m_premium = re.search(r'مبلغ\s*قابل\s*پرداخت[\s\S]{0,80}?ریال\s*([\d,]{5,})', text_norm)
    data["total_premium"] = m_premium.group(1).replace(',', '') if m_premium else ""

    # کد یکتای بیمه مرکزی (برای ثالث معمولاً موجود است؛ برای بدنه ممکن است اصلاً درج نشده باشد)
    m_unique = re.search(r'(\d{8,15})\s*:\s*کد\s*یکتا\s*بیمه\s*مرکزی', text_norm)
    if not m_unique:
        m_unique = re.search(r'کد\s*یکتا\s*بیمه\s*مرکزی\s*:?\s*(\d{8,15})', text_norm)
    data["unique_code"] = m_unique.group(1) if m_unique else ""

    # شماره شاسی (VIN)
    vin_match = re.search(r'(?:VIN|شماره\s*شاسی)\s*[:\n]*\s*([A-Za-z0-9]{5,17})', text_norm, re.IGNORECASE)
    if not vin_match:
        vin_match = re.search(r'([A-Za-z0-9]{5,17})\s*[:\n]*\s*(?:شماره\s*شاسی|VIN)', text_norm, re.IGNORECASE)
    if vin_match:
        data["vin"] = vin_match.group(1)
    else:
        backup_match = re.search(r'\b(?=.*[A-Za-z])(?=.*\d)[A-Za-z0-9]{17}\b', text_norm)
        if backup_match:
            data["vin"] = backup_match.group(0)

    # پلاک
    if data["ins_company"] == "ایران" and data["ins_type"] == "ثالث":
        data["plate"] = ""
    else:
        plate_text = text_norm.replace('\n', ' ')
        m1 = re.search(r'(\d{2})\s*([آ-یa-zA-Z])\s*(\d{3})[^\d]{0,10}ایران\s*(\d{2})', plate_text)
        m2 = re.search(r'(\d{3})\s*([آ-یa-zA-Z])\s*(\d{2})[^\d]{0,10}ایران\s*(\d{2})', plate_text)
        m3 = re.search(r'(\d{2})[^\d]{0,10}ایران\s*(\d{2})\s*([آ-یa-zA-Z])\s*(\d{3})', plate_text)
        m4 = re.search(r'(\d{2})\s*ایران\s*(\d{2})\s*([آ-یa-zA-Z])\s*(\d{3})', plate_text)

        if m1: data["plate"] = f"{m1.group(4)}ایران {m1.group(3)} {m1.group(2)} {m1.group(1)}"
        elif m2: data["plate"] = f"{m2.group(4)}ایران {m2.group(1)} {m2.group(2)} {m2.group(3)}"
        elif m3: data["plate"] = f"{m3.group(1)}ایران {m3.group(4)} {m3.group(3)} {m3.group(2)}"
        elif m4: data["plate"] = f"{m4.group(1)}ایران {m4.group(4)} {m4.group(3)} {m4.group(2)}"

    # پاکسازی نام از پیشوندهای متداول («جناب آقای»، «شماره بیمه نامه» و ...) - تکراری تا پایدار شود
    if data["insured_name"]:
        name_clean = data["insured_name"]
        prev = ""
        while prev != name_clean:
            prev = name_clean
            name_clean = re.sub(r'^(?:جناب\s*آقا[یي]|سرکار\s*خانم|آقا[یي]\s*[/\\-]\s*خانم|آقا[یي]|خانم|شرکت|شماره\s*بیمه\s*نامه|بیمه\s*گ[ذز]ار|نام|جناب)\s*', '', name_clean).strip()
        data["insured_name"] = re.sub(r'\s+', ' ', name_clean).strip()

    return data
