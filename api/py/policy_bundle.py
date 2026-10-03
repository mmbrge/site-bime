# -*- coding: utf-8 -*-
# فایل: api/py/policy_bundle.py
# «صدور گروهی»: فایلِ خروجیِ سایتِ بیمه‌گر (چند بیمه‌نامه پشتِ سرِ هم؛ هر بیمه‌نامه چند صفحه: خودِ بیمه‌نامه،
# صفحه‌های پرداخت و فیش‌ها) را تکه‌تکه می‌کند. روش همان برنامه‌ی ویندوزیِ قبلی (context_processor.py):
#   - صفحه‌ی اول، صفحه‌ی بیمه‌نامه است و «الگو» حساب می‌شود؛ هر صفحه‌ای که شباهتِ کلماتش با الگو بیش از ۰٫۲۵ باشد
#     صفحه‌ی بیمه‌نامه‌ی بعدی است (بقیه پرداخت/فیشِ همان بیمه‌نامه‌اند)
#   - اطلاعاتِ هر بیمه‌نامه با همان extract_insurance_data استخراج می‌شود
# اجرا:  python3 policy_bundle.py <input.pdf> <out_dir>
# خروجی (آخرین خطِ stdout، JSON):
#   {"ok": true, "pages": N, "text_pages": k, "policies": [{"page": i, "end": j, "has_text": bool, "file": "pol_001.pdf",
#    "seg_file": "seg_001.pdf", "similarity": 0.8, "data": {...}}]}
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
    for i in range(n):
        try:
            texts.append(doc.load_page(i).get_text() or "")
        except Exception:
            texts.append("")
    words = [set(normalize_text(t).split()) for t in texts]
    text_pages = sum(1 for t in texts if len(t.strip()) > 30)

    # صفحه‌های بیمه‌نامه: صفحه‌ی اول + هر صفحه‌ای که به الگو (صفحه‌ی اول) شبیه است.
    # اگر فایل متن ندارد (اسکن)، همه‌ی صفحه‌ها نامزدند و تشخیص با موتورِ OCR در PHP انجام می‌شود.
    starts = []
    if text_pages == 0:
        starts = list(range(n))
        sims = [None] * n
    else:
        tpl = words[0]
        sims = []
        for i in range(n):
            s = 1.0 if i == 0 else jaccard(tpl, words[i])
            sims.append(round(s, 3))
            if i == 0 or s > 0.25:
                starts.append(i)

    policies = []
    for k, i in enumerate(starts):
        end = (starts[k + 1] - 1) if k + 1 < len(starts) else n - 1
        if text_pages == 0:
            end = i
        has_text = len(texts[i].strip()) > 30
        data = extract_insurance_data(texts[i]) if has_text else None
        fn = "pol_%03d.pdf" % (i + 1)
        sfn = "seg_%03d.pdf" % (i + 1)
        try:
            one = fitz.open(); one.insert_pdf(doc, from_page=i, to_page=i); one.save(os.path.join(outdir, fn)); one.close()
            seg = fitz.open(); seg.insert_pdf(doc, from_page=i, to_page=end); seg.save(os.path.join(outdir, sfn)); seg.close()
        except Exception as e:
            out({"ok": False, "error": "جدا کردنِ صفحه‌ها ممکن نشد.", "debug": str(e)[:300]}); return
        policies.append({"page": i, "end": end, "has_text": has_text, "file": fn, "seg_file": sfn,
                         "similarity": sims[i] if sims else None, "data": data})
    doc.close()
    out({"ok": True, "pages": n, "text_pages": text_pages, "policies": policies})


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        out({"ok": False, "error": "خطای پیش‌بینی‌نشده در پردازشِ فایل.", "debug": str(e)[:500]})
