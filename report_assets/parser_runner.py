# -*- coding: utf-8 -*-
"""
اجراکننده‌ی الگوریتم‌های استخراجِ PDF برای ماژولِ «گزارش بازدید» سایت.

  python3 parser_runner.py <file.pdf> [<parser.py>]

- متنِ PDF با PyMuPDF (fitz) و در صورتِ نبود/شکست با pdfplumber یا PyPDF2 خوانده می‌شود.
- اگر فایلِ پارسرِ اختصاصی داده شده باشد (همان فایل‌های template_*.py برنامه‌ی ویندوزی)،
  تابعِ parse_pdf_fields(raw_text) آن اجرا می‌شود. پوشه‌ی پارسرها به sys.path اضافه می‌شود
  تا پارسرهایی که از هم استفاده می‌کنند (مثل template_truck که template_car را صدا می‌زند) بدون تغییر کار کنند.
- اگر پارسرِ اختصاصی چیزی برنگرداند، مثل برنامه‌ی ویندوزی پارسرِ عمومی اجرا می‌شود.
- خروجی: آخرین خطِ stdout یک JSON است:
  {"ok": true, "data": {...}, "method": "PyMuPDF", "parser_used": "custom|generic", "raw_len": 1234, "raw_preview": "..."}
"""
import sys
import os
import re
import json
import unicodedata
import importlib.util
import traceback


def _out(obj):
    sys.stdout.write("\n" + json.dumps(obj, ensure_ascii=False) + "\n")
    sys.stdout.flush()


# ---------------------------------------------------------------------------
#  خواندنِ متن
# ---------------------------------------------------------------------------
def extract_with_pymupdf(pdf_path):
    try:
        import pymupdf as fitz  # نسخه‌های تازه‌ی PyMuPDF
    except ImportError:
        import fitz  # PyMuPDF (نسخه‌های قدیمی)
    doc = fitz.open(pdf_path)
    try:
        return "\n".join([page.get_text() for page in doc])
    finally:
        doc.close()


def extract_with_pdfplumber(pdf_path):
    import pdfplumber
    with pdfplumber.open(pdf_path) as pdf:
        return "\n".join([page.extract_text() or "" for page in pdf.pages])


def extract_with_pypdf2(pdf_path):
    try:
        from PyPDF2 import PdfReader
    except ImportError:
        from pypdf import PdfReader
    with open(pdf_path, 'rb') as f:
        reader = PdfReader(f)
        return "\n".join([page.extract_text() or "" for page in reader.pages])


EXTRACTORS = [('PyMuPDF', extract_with_pymupdf), ('pdfplumber', extract_with_pdfplumber), ('PyPDF2', extract_with_pypdf2)]


def extract_texts(pdf_path):
    """همه‌ی روش‌های در دسترس را به ترتیب امتحان می‌کند: [(method, text), ...]"""
    out, errors = [], []
    for name, fn in EXTRACTORS:
        try:
            t = fn(pdf_path)
            if t and t.strip():
                out.append((name, t))
        except Exception as e:  # کتابخانه نصب نیست یا فایل را نخواند
            errors.append("%s: %s" % (name, e))
    return out, errors


# ---------------------------------------------------------------------------
#  پارسرِ عمومی (همان parse_text_to_fields برنامه‌ی ویندوزی)
# ---------------------------------------------------------------------------
def _fix_digits(s):
    if not s:
        return ""
    mapping = {
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
        '،': ','
    }
    for k, v in mapping.items():
        s = s.replace(k, v)
    return unicodedata.normalize('NFKC', s).replace('ي', 'ی').replace('ك', 'ک')


def parse_text_to_fields(raw_text):
    data = {
        'year': '', 'plate_part1': '', 'plate_letter': '', 'plate_part2': '', 'plate_part3': '',
        'chassis_no': '', 'engine_no': '', 'insured_value': '', 'name': '', 'national_id': '',
        'phone_bimeg': '', 'color': '', 'usage': '', 'vehicle_type': '', 'brand': '', 'addres_bimeg': ''
    }
    if not raw_text.strip():
        return data

    text_normalized = _fix_digits(raw_text)
    text_normalized = re.sub(r'[ \t]+', ' ', text_normalized)

    text_no_dates = re.sub(r'\d{4}\s*[/.-]\s*\d{1,2}\s*[/.-]\s*\d{1,2}', '', text_normalized)
    year_match = re.search(r'(?<!\d)(13[4-9]\d|14\d{2})(?!\d)', text_no_dates)
    if year_match:
        data['year'] = year_match.group(1)

    plate_found = False
    m_inv = re.search(r'(\d{2})\s*ایران\s*(\d{2})\s*([a-zA-Zآ-یﻉعو])\s*(\d{3})', text_normalized)
    if m_inv:
        data['plate_part3'] = m_inv.group(1)
        data['plate_part1'] = m_inv.group(2)
        data['plate_letter'] = m_inv.group(3)
        data['plate_part2'] = m_inv.group(4)
        plate_found = True
    else:
        m_dir = re.search(r'(\d{2})\s*([a-zA-Zآ-یﻉعو])\s*(\d{3})\s*ایران\s*(\d{2})', text_normalized)
        if m_dir:
            data['plate_part1'] = m_dir.group(1)
            data['plate_letter'] = m_dir.group(2)
            data['plate_part2'] = m_dir.group(3)
            data['plate_part3'] = m_dir.group(4)
            plate_found = True

    if not plate_found:
        for line in text_normalized.split('\n'):
            if 'پلاک' in line or 'پالک' in line:
                line_clean = re.sub(r'[^\d\sایرانآ-یa-zA-Z]', '', line)
                nums = re.findall(r'\d+', line_clean)
                letter = re.search(r'[a-zA-Zآ-یﻉعو]', line_clean)
                if len(nums) >= 3:
                    data['plate_part1'] = nums[0] if len(nums[0]) <= 2 else ''
                    data['plate_letter'] = letter.group() if letter else ''
                    data['plate_part2'] = nums[1] if len(nums) > 1 else ''
                    data['plate_part3'] = nums[-1]
                break

    chassis = None
    m17 = re.search(r'[A-Za-z0-9]{17}', text_normalized)
    if m17:
        chassis = m17.group(0).upper()
    else:
        m13_17 = re.search(r'[A-Za-z0-9]{13,17}', text_normalized)
        if m13_17:
            chassis = m13_17.group(0).upper()
    if chassis:
        data['chassis_no'] = chassis
        text_without_chassis = text_normalized.replace(chassis, '', 1)
    else:
        text_without_chassis = text_normalized

    engine_candidates = re.findall(r'\b[A-Za-z0-9\-]{6,25}\b', text_without_chassis)
    filtered = [x for x in engine_candidates if not re.match(r'^\d{10,11}$', x) and not re.match(r'^(13|14)\d{2}$', x)]
    if filtered:
        alphanum = [x for x in filtered if not re.match(r'^[\d\-]+$', x)]
        if alphanum:
            alphanum.sort(key=len, reverse=True)
            data['engine_no'] = alphanum[0].upper()
        else:
            pure_nums = [x for x in filtered if re.match(r'^[\d\-]+$', x)]
            if pure_nums:
                pure_nums.sort(key=lambda x: (len(x), int(re.sub(r'[^\d]', '', x) or 0)), reverse=True)
                data['engine_no'] = pure_nums[0]

    value_match = re.search(r'مبلغ\s*([\d,]+)\s*ریال', text_normalized)
    if value_match:
        data['insured_value'] = value_match.group(1).replace(',', '')
    else:
        vmatch = re.search(r'([1-9][0-9]{0,2}(?:,[0-9]{3}){2,})', text_normalized)
        if vmatch:
            data['insured_value'] = vmatch.group(1).replace(',', '')
        else:
            val_after_keyword = re.search(r'ارزش\s+(?:وسیله\s+نقلیه|خودرو)[\s:]*([\d,]+)', text_normalized)
            if val_after_keyword:
                data['insured_value'] = val_after_keyword.group(1).replace(',', '')
            else:
                big = re.findall(r'[1-9]\d{8,}', text_normalized)
                if big:
                    data['insured_value'] = max(big, key=lambda x: int(x))

    name_match = re.search(r'اینجانب\s+(.*?)\s+با\s*[تت]?[اأآ]?[یي]?[یي]?د?', text_normalized)
    if name_match:
        data['name'] = name_match.group(1).strip()
    else:
        name_match_alt = re.search(r'([آ-یa-zA-Z\s]+)\s*:\s*کد\s*ملی', text_normalized)
        if name_match_alt:
            data['name'] = name_match_alt.group(1).strip()

    nat_match = re.search(r'(?<!\d)(\d{10,11})(?!\d)', text_normalized)
    if nat_match:
        data['national_id'] = nat_match.group(1)

    phone_match = re.search(r'(0?9\d{2}[-\s]?\d{3}[-\s]?\d{4})', text_normalized)
    if phone_match:
        data['phone_bimeg'] = phone_match.group(1).replace('-', '').replace(' ', '')
    else:
        phone_match_alt = re.search(r'(?<!\d)(0\d{7,10})(?!\d)', text_normalized)
        if phone_match_alt:
            data['phone_bimeg'] = phone_match_alt.group(1)

    color_match = re.search(
        r'رنگ[\s:|]*\s*((?:(?!\bظرفیت\b|\bمورد\s+استفاده\b|\bشماره\b|\bسیلندر\b|\bموتور\b|\bشاسی\b)[\s\S])*?)(?=\s*(?:ظرفیت|مورد\s+استفاده|شماره|سیلندر|موتور|شاسی|\Z))',
        text_normalized)
    if color_match and color_match.group(1).strip():
        data['color'] = color_match.group(1).strip()
    else:
        loose_color = re.search(r'(سفید|مشکی|نوک مدادی|نقره ای|سورمه ای|قرمز|آبی|خاکستری|طلایی|قهوه ای|سبز)\s*(متالیک|روغنی|صدفی)?', text_normalized)
        if loose_color:
            data['color'] = loose_color.group(0).strip()

    usage_match = re.search(
        r'مورد\s+استفاده[\s:|]*\s*((?:(?!\bشماره\b|\bموتور\b|\bشاسی\b|\bسیلندر\b|\bرنگ\b|\bظرفیت\b)[\s\S])*?)(?=\s*(?:شماره|موتور|شاسی|سیلندر|رنگ|ظرفیت|\Z))',
        text_normalized)
    if usage_match and usage_match.group(1).strip():
        data['usage'] = usage_match.group(1).strip()
    else:
        loose_usage = re.search(r'(شخصی|بارکش|مسافربر|تاکسی|آژانس|آموزشی|کاربری\s+[آ-ی]+)', text_normalized)
        if loose_usage:
            data['usage'] = loose_usage.group(1).strip()

    brands_pattern = r'(سوزوکی|ام وی ام|MVM|چری|فونیکس|جک|لیفان|تیبا|ساینا|کوییک|تارا|شاهین|دنا|رانا|پیکان|میتسوبیشی|نیسان|مزدا|ماموت|پژو|پراید|سمند|تویوتا|هیوندای|کیا|رنو|بنز|بی ام و|دانگ فنگ|اسکانیا|ولوو|ایسوزو|کوماتسو|فاو|آمیکو)'
    v_type_regex = r'(سواری|کامیون|کامیونت|کشنده|وانت|موتورسیکلت|یدک|کفی|ون|مینی بوس|اتوبوس)'
    v_type_full_match = re.search(r'(' + v_type_regex + r'\s+' + brands_pattern + r'(?:\s+[آ-یA-Za-z0-9]+){0,2})', text_normalized)
    if v_type_full_match:
        data['vehicle_type'] = v_type_full_match.group(1).strip()
    else:
        model_match = re.search(brands_pattern + r'(?:\s+(?!مدل|رنگ|شماره|سال|ظرفیت|مورد|شاسی|موتور)[آ-یA-Za-z0-9]+){0,2}', text_normalized)
        if model_match:
            data['vehicle_type'] = model_match.group(0).strip()
        else:
            v_type_match = re.search(r'(خودرو\s+)?' + v_type_regex, text_normalized)
            if v_type_match:
                data['vehicle_type'] = v_type_match.group(0).strip()

    addr_match = re.search(r'(استان\s+[آ-ی\s،,-]+(?:پلاک\s+\d+|واحد\s+\d+|طبقه\s+[آ-ی\d]+)?)', text_normalized)
    if addr_match:
        data['addres_bimeg'] = addr_match.group(1).strip()
    return data


def _score(d):
    return len([v for v in (d or {}).values() if str(v).strip()])


# ---------------------------------------------------------------------------
#  پارسرِ اختصاصی
# ---------------------------------------------------------------------------
def load_custom_parser(parser_path):
    parser_path = os.path.abspath(parser_path)
    folder = os.path.dirname(parser_path)
    if folder not in sys.path:
        sys.path.insert(0, folder)
    stem = os.path.splitext(os.path.basename(parser_path))[0]
    spec = importlib.util.spec_from_file_location(stem, parser_path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[stem] = module
    spec.loader.exec_module(module)
    fn = getattr(module, 'parse_pdf_fields', None)
    if not callable(fn):
        raise RuntimeError('تابع parse_pdf_fields(raw_text) در فایل پارسر تعریف نشده است.')
    return fn


def _clean(d):
    out = {}
    for k, v in (d or {}).items():
        if v is None:
            continue
        if isinstance(v, (list, tuple)):
            v = '، '.join(str(x) for x in v)
        elif isinstance(v, dict):
            v = json.dumps(v, ensure_ascii=False)
        s = str(v).strip()
        if s.lower() in ('nan', 'none', 'null'):
            s = ''
        out[str(k)] = s
    return out


def main():
    if len(sys.argv) < 2:
        _out({'ok': False, 'error': 'usage: parser_runner.py file.pdf [parser.py]'})
        return 2
    pdf_path = sys.argv[1]
    parser_path = sys.argv[2] if len(sys.argv) > 2 and sys.argv[2] else None
    if not os.path.isfile(pdf_path):
        _out({'ok': False, 'error': 'فایل PDF پیدا نشد.'})
        return 1

    texts, errors = extract_texts(pdf_path)
    if not texts:
        msg = 'متنی در این PDF پیدا نشد (احتمالاً اسکن تصویری است).'
        if errors and len(errors) == len(EXTRACTORS):
            msg = 'هیچ‌کدام از کتابخانه‌های خواندن PDF روی پایتون نصب نیست (PyMuPDF / pdfplumber / PyPDF2).'
        _out({'ok': False, 'error': msg, 'details': errors})
        return 1

    method, raw_text = texts[0]
    data, used, parser_error = {}, 'generic', None
    if parser_path:
        try:
            fn = load_custom_parser(parser_path)
            data = _clean(fn(raw_text) or {})
            used = 'custom'
        except Exception as e:
            parser_error = '%s: %s' % (type(e).__name__, e)
            traceback.print_exc(file=sys.stderr)
            data = {}

    if _score(data) == 0:
        # مثل برنامه‌ی ویندوزی: بهترین نتیجه از بینِ روش‌های مختلفِ خواندن
        best, best_score = {}, -1
        for m, t in texts:
            d = _clean(parse_text_to_fields(t))
            s = _score(d)
            if s > best_score:
                best, best_score, method, raw_text = d, s, m, t
            if s >= 12:
                break
        data, used = best, 'generic'
    else:
        # کلیدهایی که پارسرِ اختصاصی پیدا نکرده از پارسرِ عمومی پر می‌شوند (نتیجه‌ی پارسرِ اختصاصی همیشه مقدم است)
        try:
            extra = _clean(parse_text_to_fields(raw_text))
            added = 0
            for k, v in extra.items():
                if v and not str(data.get(k, '')).strip():
                    data[k] = v
                    added += 1
            if added:
                used = 'custom+generic'
        except Exception:
            traceback.print_exc(file=sys.stderr)

    _out({'ok': True, 'data': {k: v for k, v in data.items() if v != ''}, 'method': method, 'parser_used': used,
          'parser_error': parser_error, 'raw_len': len(raw_text), 'raw_preview': raw_text[:3000]})
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as e:
        traceback.print_exc(file=sys.stderr)
        _out({'ok': False, 'error': '%s: %s' % (type(e).__name__, e)})
        sys.exit(1)
