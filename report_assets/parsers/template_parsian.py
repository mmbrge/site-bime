import re
import unicodedata

class ParsianParser:
    def __init__(self):
        pass

    def _clean_text(self, s):
        if not s: return ""
        mapping = {
            '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
            '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
            '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
            '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
            '،': ',', 'ي': 'ی', 'ك': 'ک', 
            'ͯ': 'ی', 'ͬ': 'ی', 'ͷ': 'ک', 'ﻼ': 'لا', '(': '(', ')': ')'
        }
        for k, v in mapping.items():
            s = s.replace(k, v)
        s = re.sub(r'\(cid:\d+\)', ' ', s)
        return unicodedata.normalize('NFKC', s)

    def parse(self, raw_text):
        out = {
            "document_type": "پیشنهاد بیمه بدنه پارسیان",
            "name": "",
            "national_id": "",
            "phone_number": "",
            "addres_bimeg": "تهران",
            "inspector": "مینو آقاجانی",
            "start_date": "",
            "end_date": "",
            "policy_number": "پیشنهاد (فاقد شماره)",
            "plate_formatted": "",
            "vehicle_type": "",
            "manufacture_year": "",
            "color": "",
            "chassis_no": "",
            "engine_no": "",
            "cylinder_count": "4",
            "usage": "",
            "insured_value": ""
        }
        
        t = self._clean_text(raw_text or "")
        
        # ۱. استخراج تاریخ‌های بیمه‌نامه
        dates = re.findall(r'\d{4}/\d{2}/\d{2}', t)
        if dates:
            dates = sorted(list(set(dates)))
            out["start_date"] = dates[0]
            if len(dates) > 1:
                out["end_date"] = dates[-1]

        # ۲. پلاک (بازگشت به الگوریتم قدرتمند اولی که با ساختار PDF سازگارتر است)
        m_plate = re.search(r'(\d{2,3})\s*([a-zA-Zآ-یهعﻉعون])\s*(\d{2,3})\s*(?:ایران|ايران)\s*(\d{2})', t)
        if m_plate:
            pA, letter, pB, p_iran = m_plate.groups()
            p1 = pB if len(pA) == 3 and len(pB) == 2 else pA
            p2 = pA if len(pA) == 3 and len(pB) == 2 else pB
            letter = letter.replace('ه', 'ه')
            out["plate_formatted"] = f"{p_iran}ایران - {p1} {letter} {p2}"
            out['plate_part1'] = p1
            out['plate_letter'] = letter
            out['plate_part2'] = p2
            out['plate_part3'] = p_iran

        # ۳. شاسی
        m_chassis = re.findall(r'(?<![A-Za-z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Za-z0-9])', t, re.I)
        valid_chassis = [c for c in m_chassis if re.search(r'[A-Za-z]', c) and re.search(r'[0-9]', c)]
        if valid_chassis:
            out["chassis_no"] = valid_chassis[0].upper()
            out["vin"] = out["chassis_no"]
            
        # ۴. موتور
        engine_cands = re.findall(r'(?<![A-Za-z0-9])([A-Z0-9\-]{6,15})(?![A-Za-z0-9])', t.replace(out.get('chassis_no', ''), ''), re.I)
        valid_engines = [c for c in engine_cands if not (c.isdigit() and len(c) in (10, 11) and c.startswith('0')) and not re.match(r'^(13|14)\d{2}$', c)]
        if valid_engines:
            alphanumeric_engines = [c for c in valid_engines if re.search(r'[A-Za-z]', c)]
            out["engine_no"] = max(alphanumeric_engines, key=len).upper() if alphanumeric_engines else max(valid_engines, key=len).upper()

        # ۵. کدملی و نام
        m_nat = re.search(r'(?<!\d)(\d{10})(?!\d)', t)
        if m_nat: out["national_id"] = m_nat.group(1)

        m_name = re.search(r'(?:سرکار\s*خانم|جناب\s*آقای)\s+([\s\S]+?)\s*کد', t)
        if m_name: 
            out["name"] = re.sub(r'\s+', ' ', m_name.group(1)).strip()
        else:
            m_name2 = re.search(r'نام\s*[:\s]*(?:سرکار\s*خانم|جناب\s*آقای)?\s*([آ-یa-zA-Z\s]{3,40}?)\s*(?:کد|شماره\s*شناسنامه)', t)
            if m_name2: out["name"] = m_name2.group(1).strip()

        # ۶. استخراج شماره تماس (اصلاح شده برای یافتن مستقیم بدون وابستگی به فضا)
        m_phone = re.search(r'(09\d{9})', t)
        if m_phone: out["phone_number"] = m_phone.group(1)

        # ۷. آدرس (اسکن هوشمند بین کدملی و کدپستی/شماره موبایل)
        if out["national_id"]:
            pattern = re.escape(out["national_id"]) + r'\s*\n([\s\S]*?)(?=\b\d{10}\b|\b09\d{9}\b|شماره مشتری)'
            m_addr = re.search(pattern, t)
            if m_addr:
                addr = re.sub(r'\s+', ' ', m_addr.group(1)).strip()
                addr = re.sub(r'[,،\-]+$', '', addr).strip()
                if len(addr) > 3: out["addres_bimeg"] = addr

        # ۸. مورد استفاده و نوع وسیله نقلیه
        m_usage_sys = re.search(r'نوع\s*و\s*سیستم\s*[:\s]*([^\n]+)', t)
        if m_usage_sys: out["usage"] = m_usage_sys.group(1).strip()

        m_tip = re.search(r'تیپ\s*[:\s]*([\s\S]{2,30}?)(?=تعداد|رنگ|ظرفیت|شماره|سال|\n)', t)
        if m_tip: out["vehicle_type"] = m_tip.group(1).strip()

        # ۹. رنگ و سیلندر
        m_cyl_color = re.search(r'تعداد\s*سیلندر\s*:\s*رنگ\s*:\s*\n*(\d+)\s*([^\n\d]+)', t)
        if m_cyl_color:
            out["cylinder_count"] = m_cyl_color.group(1).strip()
            out["color"] = m_cyl_color.group(2).strip()
        else:
            m_color = re.search(r'(سفید|مشکی|قرمز|آبی|نقره\s*ای|نوک\s*مدادی|خاکستری|سبز|زرد)(?:\s*(روغنی|متالیک|صدفی))?', t)
            if m_color: out["color"] = m_color.group(0).strip()
            m_cyl = re.search(r'سیلندر(?:[^0-9]{0,20})?(\d+)', t)
            if m_cyl: out["cylinder_count"] = m_cyl.group(1)

        # ۱۰. سال ساخت
        m_year = re.search(r'سال\s*ساخت\s*:\s*(13[4-9]\d|14[0-1]\d)', t)
        if m_year: out["manufacture_year"] = m_year.group(1)

        # ۱۱. ارزش خودرو
        m_val = re.search(r'ارزش\s*خودرو:\s*سایر:\s*([\d,]{7,})', t) or re.search(r'([\d,]{7,})\s*ریال', t)
        if m_val: out["insured_value"] = m_val.group(1).replace(',', '').strip()

        # همگام‌سازی کامل تمام کلیدها با پنل اصلی
        out["shasi"] = out["chassis_no"]
        out["motor"] = out["engine_no"]
        out["sal_sakht"] = out["manufacture_year"]
        out["rang"] = out["color"]
        out["mordes"] = out["usage"]
        out["noecar"] = out["vehicle_type"]
        out["arzesh"] = out["insured_value"]
        out["zarfiat"] = out.get("cylinder_count", "4")
        
        sync_pairs = [
            ("phone_bimeg", "phone_number", "phone"),
            ("addres_bimeg", "address"),
            ("national_id", "kod_meli"),
            ("name", "bime_gozar"),
            ("inspector", "بازدید کننده")
        ]
        for group in sync_pairs:
            val = next((out.get(k) for k in group if out.get(k)), "")
            if val:
                for k in group: out[k] = val
                
        return out

# ==================================
# تابع رابط برای سازگاری با پنل اصلی
# ==================================
def parse_pdf_fields(raw_text):
    parser = ParsianParser()
    return parser.parse(raw_text)