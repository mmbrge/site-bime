# -*- coding: utf-8 -*-
# فایل: api/py/endorse_reader.py
# خواندنِ فایلِ PDFِ «الحاقیه»ی بیمه‌گر (پاسارگاد): اضافی / برگشتی / فسخ / اصلاحی
#   python3 endorse_reader.py <input.pdf>
# خروجی (آخرین خطِ stdout، JSON):
#   {"ok": true, "data": {"policy_num", "endorse_no", "kind": ADDITIONAL|REFUND|CANCELLATION|CORRECTIVE, "kind_fa",
#     "issue_date", "effect_date", "new_end_date", "gross", "net", "tax", "levy", "central_no", "insured", "description",
#     "changes": [...], "insurance_type": "بدنه|ثالث"}, "statement": [{"fish", "due", "amount", "kind"}], "pages": n}
# متنِ این فایل‌ها با «فرمِ نمایشیِ» حروفِ عربی ذخیره شده؛ normalize_text (NFKC) آن را به متنِ عادی برمی‌گرداند.
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from policy_bundle import (out, load_fitz, normalize_text, page_spans, row_values, dates_in, money_values,  # noqa: E402
                           fa_words_to_int, statement_rows)

POLICY_RX = re.compile(r'(\d{1,2}/\d{3,6}/\d{3,6}-\d{1,3}/1[34]\d{2}/\d{1,7})')


def label_value_dates(spans, rx):
    """تاریخِ کنارِ یک برچسب (داخلِ همان تکه یا تکه‌های همان ردیف)"""
    for sp in spans:
        if rx.search(sp['t']):
            ds = dates_in(sp['t'])
            if ds:
                return ds[0]
            for v in row_values(spans, sp):
                ds = dates_in(v['t'])
                if ds:
                    return ds[0]
    return ''


def label_amount(spans, rx, exclude=None):
    """مبلغِ کنارِ برچسب («حق بیمه الحاقیه61,804,000» یا تکه‌ی جدا در همان ردیف؛ پرانتز = منفی/برگشتی)"""
    for sp in spans:
        t = sp['t']
        if not rx.search(t) or (exclude and exclude.search(t)):
            continue
        vals = money_values(t)
        if not vals:
            for v in row_values(spans, sp):
                vals = money_values(v['t'])
                if vals:
                    break
        if vals:
            return max(vals)
    return 0


def fix_value(v):
    """مقدارِ داخلِ «شرح تغییرات» که ترتیبِ تکه‌هایش (عدد/حرف) برعکس خوانده شده: «29د214» => «214د29»"""
    v = (v or '').strip()
    if re.search(r'[آ-ی]', v) and re.search(r'\d', v) and not re.search(r'\s', v):
        parts = re.findall(r'\d+|[^\d]+', v)
        v = ''.join(reversed(parts))
    return v


def fix_suffix(v):
    v = (v or '').strip()
    return v.replace(')', '\x00').replace('(', ')').replace('\x00', '(').strip()


def fix_change_line(line):
    """یک ردیفِ «شرح تغییرات» (متنِ راست‌به‌چپی که تکه‌هایش جابه‌جا خوانده شده) => «بخش: فیلد از «قبلی» به «جدید»»"""
    t = re.sub(r'\s+', ' ', line).strip().lstrip('.').strip()
    t = re.sub(r'^\d{1,2}(?=\D)', '', t).strip()
    sec = ''
    m = re.search(r'^(.*?)-\s*([^-]+)$', t)
    if m and len(m.group(2)) < 40:
        t, sec = m.group(1).strip(), m.group(2).strip()
    m = re.match(r'^(.*?)"\s*(تغییر\s*می\s*کند)(.*?)"\s*(.*?)"\s*به(.*?)"\s*(.+?)\s*:\s*از$', t)
    if m:
        to = (fix_value(m.group(3)) + ' ' + fix_suffix(m.group(1))).strip()
        fr = (fix_value(m.group(5)) + ' ' + fix_suffix(m.group(4))).strip()
        field = m.group(6).strip()
        txt = '%s: از «%s» به «%s»' % (field, fr, to)
        if re.fullmatch(r'[\s()\-]*', fr):
            txt = '%s: «%s» (قبلاً خالی بود)' % (field, to)
    else:
        txt = fix_suffix(t.strip(' .'))
        txt = re.sub(r'از\s*"([^"]*)"\s*به\s*"([^"]*)"\s*تغییر\s*می\s*کند', r'از «\1» به «\2»', txt)
    txt = re.sub(r'\s+', ' ', txt).replace(' :', ':').strip()
    return ('%s - %s' % (sec, txt)) if sec else txt


def read_endorse(doc, pages=None):
    """خواندنِ یک الحاقیه از صفحه‌های داده‌شده‌ی doc => {ok, data, statement, pages, missing}"""
    idx = list(pages) if pages is not None else list(range(len(doc)))
    texts = [normalize_text(doc.load_page(i).get_text() or '') for i in idx]
    full = '\n'.join(texts)
    if len(full.strip()) < 40:
        return {"ok": False, "scan": True, "error": "فایل متن ندارد (اسکن/عکس است)؛ اطلاعاتِ الحاقیه را دستی وارد کنید.", "pages": len(idx)}
    if 'الحاقیه' not in full:
        return {"ok": False, "error": "این فایل الحاقیه به نظر نمی‌رسد (کلمه‌ی «الحاقیه» در آن نیست).", "pages": len(idx)}

    # صفحه‌ی اصلیِ الحاقیه (نه اعلامیه‌ی حق بیمه)
    main_k = next((k for k, t in enumerate(texts) if 'الحاقیه' in t and 'اعلامیه آخرین وضعیت' not in t), 0)
    t = texts[main_k]
    spans = page_spans(doc.load_page(idx[main_k]))
    d = {}

    m = POLICY_RX.search(t) or POLICY_RX.search(full)
    d['policy_num'] = m.group(1) if m else ''

    # شماره‌ی الحاقیه: «الحاقیه شماره ۳» (کنارِ برچسب یا در خودِ آن)
    no = ''
    for sp in spans:
        if re.search(r'الحاقیه\s*شماره', sp['t']):
            mm = re.search(r'الحاقیه\s*شماره\s*:?\s*(\d{1,3})(?!\d)', sp['t'])
            if mm:
                no = mm.group(1); break
            for v in row_values(spans, sp):
                if re.fullmatch(r'\d{1,3}', v['t'].strip()):
                    no = v['t'].strip(); break
            if no:
                break
    if not no:
        mm = re.search(r'الحاقیه\s*شماره\s*:?\s*(\d{1,3})(?!\d)', full) or re.search(r'(?<![\d/])(\d{1,3})\s*\n\s*' + re.escape(d['policy_num'] or '#'), t)
        no = mm.group(1) if mm else ''
    d['endorse_no'] = no

    # نوع: فسخ (بیمه‌گذار/بیمه‌گر) ← برگشتی؛ برگشتی؛ اضافی؛ اصلاحی
    mk = re.search(r'الحاقیه\s*(اضافی|برگشتی|اصلاحی|فسخ)', t)
    if re.search(r'فسخ', t) and re.search(r'ساقط|فسخ\s*بیمه\s*گذار|فسخ\s*از\s*طرف', t):
        kind = 'CANCELLATION'
    elif mk:
        kind = {'اضافی': 'ADDITIONAL', 'برگشتی': 'REFUND', 'اصلاحی': 'CORRECTIVE', 'فسخ': 'CANCELLATION'}[mk.group(1)]
    elif re.search(r'برگشتی', t):
        kind = 'REFUND'
    elif re.search(r'اضافی', t):
        kind = 'ADDITIONAL'
    elif re.search(r'اصلاحی', t):
        kind = 'CORRECTIVE'
    else:
        kind = ''
    d['kind'] = kind
    d['kind_fa'] = {'CANCELLATION': 'فسخ (برگشتی)', 'REFUND': 'برگشتی', 'ADDITIONAL': 'اضافی', 'CORRECTIVE': 'اصلاحی'}.get(kind, '')
    d['insurance_type'] = 'بدنه' if 'بیمه بدنه' in t else ('ثالث' if 'ثالث' in t else '')

    d['issue_date'] = label_value_dates(spans, re.compile(r'تاریخ\s*صدور'))
    # تاریخِ اثر: جمله‌ی «عطف به درخواست ... از ساعت ... روز ... مورخ YYYY/MM/DD»
    eff = ''
    for line in t.split('\n'):
        if re.search(r'عطف\s*به\s*درخواست|از\s*ساعت', line):
            ds = dates_in(line)
            if ds:
                eff = ds[0]; break
    d['effect_date'] = eff or d['issue_date']
    # تاریخِ پایانِ جدید: برچسب یا «تاریخ پایان: از X به Y»
    end = ''
    mm = re.search(r'تاریخ\s*پایان[^\n]*?(1[34]\d{2}/\d{1,2}/\d{1,2})[^\n]*?(1[34]\d{2}/\d{1,2}/\d{1,2})', t)
    if mm:
        a, b = dates_in(mm.group(1))[0], dates_in(mm.group(2))[0]
        end = max(a, b) if 'افزایش' in t else b
    if not end:
        end = label_value_dates(spans, re.compile(r'تاریخ\s*پایان'))
    d['new_end_date'] = end if kind != 'CANCELLATION' else d['effect_date']

    # مبالغ: مبلغِ کل (با مالیات) از «به حروف»، بعد از برچسب؛ خالص و مالیات و عوارض از برچسب‌های خودشان
    gross = 0
    for line_i, line in enumerate(t.split('\n')):
        if re.search(r'به\s*حروف', line):
            rest = '\n'.join(t.split('\n')[line_i:line_i + 3])
            w = re.search(r'([آ-ی\s‌]{6,}?)\s*ریال', rest)
            if w:
                gross = fa_words_to_int(w.group(1))
            if gross:
                break
    lab_gross = label_amount(spans, re.compile(r'حق\s*بیمه\s*(الحاقیه|برگشتی)'), exclude=re.compile(r'خالص|حروف'))
    if not gross or (lab_gross and abs(lab_gross - gross) > 1000):
        gross = lab_gross or gross
    d['gross'] = gross
    d['net'] = label_amount(spans, re.compile(r'حق\s*بیمه\s*خالص'))
    d['tax'] = label_amount(spans, re.compile(r'مالیات'))
    d['levy'] = label_amount(spans, re.compile(r'عوارض'))
    if not d['gross'] and d['net']:
        d['gross'] = d['net'] + d['tax'] + d['levy']

    mm = re.search(r'(?<!\d)(\d{11})(?!\d)\s*:?\s*شماره\s*بیمه\s*مرکزی', t) or re.search(r'شماره\s*بیمه\s*مرکزی\s*:?\s*(\d{11})', t)
    d['central_no'] = mm.group(1) if mm else ''
    # بیمه‌گذار: تکه‌ای که «(سال-شماره) نام» دارد
    ins = ''
    for sp in spans:
        mm = re.search(r'\(\s*1[34]\d{2}\s*-\s*\d+\s*\)\s*(.+)$', sp['t'])
        if mm:
            ins = mm.group(1).strip(); break
    d['insured'] = ins
    mm = re.search(r'شرح\s*الحاقیه\s*:?\s*([^\n]+)', t)
    d['description'] = mm.group(1).strip(' :.') if mm else ''
    # شرحِ تغییرات (ردیف‌های جدولِ «شرح تغییرات»): خط‌هایی که «- بیمه بدنه» یا «- بیمه شخص ثالث» دارند
    changes = []
    for line in t.split('\n'):
        if 'تغییر کرده است' in line:
            continue
        if re.search(r'تغییر\s*می\s*کند|اضافه\s*می\s*شود|حذف\s*می\s*شود', line) and len(line) > 12:
            c = fix_change_line(line)
            if c and c not in changes:
                changes.append(c)
    d['changes'] = changes[:30]
    if not d['description']:
        for kw in ('افزایش مدت بیمه نامه', 'کاهش مدت', 'تغییر مالکیت', 'اصلاح', 'فروش رفته'):
            if kw in t:
                d['description'] = kw; break

    # اعلامیه‌ی حق بیمه (اقساطِ همین الحاقیه)
    st = []
    for tx in texts:
        if 'اعلامیه آخرین وضعیت' in tx:
            st += statement_rows(tx)
    missing = [k for k in ('policy_num', 'kind', 'issue_date', 'gross') if not d.get(k)]
    if kind == 'CORRECTIVE' and 'gross' in missing:
        missing.remove('gross')
    return {"ok": True, "data": d, "statement": st, "pages": len(texts), "missing": missing}


def main():
    if len(sys.argv) < 2:
        out({"ok": False, "error": "usage"}); return
    fitz = load_fitz()
    if fitz is None:
        out({"ok": False, "error": "کتابخانه‌ی PyMuPDF روی پایتونِ سرور نصب نیست."}); return
    try:
        doc = fitz.open(sys.argv[1])
    except Exception as e:
        out({"ok": False, "error": "فایل PDF باز نشد.", "debug": str(e)[:200]}); return
    r = read_endorse(doc)
    # فایلی که چند الحاقیه دارد (فایلِ گروهی): فقط اولی خوانده شد
    starts = endorse_starts(doc)
    if len(starts) > 1:
        r['multi'] = len(starts)
    doc.close()
    out(r)


def endorse_starts(doc):
    """شماره‌ی صفحه‌هایی که یک الحاقیه‌ی تازه از آن‌ها شروع می‌شود (صفحه‌ی اصلیِ هر الحاقیه)"""
    st = []
    for i in range(len(doc)):
        t = normalize_text(doc.load_page(i).get_text() or '')
        if 'الحاقیه' in t and 'اعلامیه آخرین وضعیت' not in t and POLICY_RX.search(t) and re.search(r'الحاقیه\s*شماره|نوع\s*الحاقیه', t):
            st.append(i)
    return st


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        out({"ok": False, "error": "خطای پیش‌بینی‌نشده در خواندنِ الحاقیه.", "debug": str(e)[:400]})
