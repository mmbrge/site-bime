# -*- coding: utf-8 -*-
# فایل: api/py/endorse_bundle.py
# فایلِ «الحاقیه‌های گروهی» (چند الحاقیه پشتِ سرِ هم در یک PDF، هر کدام با اعلامیه و فیش‌هایش):
#   python3 endorse_bundle.py <input.pdf> <out_dir>
# هر الحاقیه از صفحه‌ی اصلی‌اش تا پیش از الحاقیه‌ی بعدی جدا (seg_001.pdf ...) و خوانده می‌شود.
# خروجی (آخرین خطِ stdout، JSON): {"ok": true, "pages": N, "items": [{"page", "end", "file", "ok", "data", "statement", "missing", "error"}]}
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from policy_bundle import out, load_fitz  # noqa: E402
from endorse_reader import read_endorse, endorse_starts  # noqa: E402


def main():
    if len(sys.argv) < 3:
        out({"ok": False, "error": "usage"}); return
    fitz = load_fitz()
    if fitz is None:
        out({"ok": False, "error": "کتابخانه‌ی PyMuPDF روی پایتونِ سرور نصب نیست."}); return
    src, outdir = sys.argv[1], sys.argv[2]
    os.makedirs(outdir, exist_ok=True)
    try:
        doc = fitz.open(src)
    except Exception as e:
        out({"ok": False, "error": "فایل PDF باز نشد.", "debug": str(e)[:200]}); return
    n = len(doc)
    starts = endorse_starts(doc)
    if not starts:
        r = read_endorse(doc)
        out({"ok": False, "pages": n, "error": r.get('error') or 'در این فایل الحاقیه‌ای پیدا نشد (صفحه‌ی اصلیِ الحاقیه با شماره‌ی بیمه‌نامه شناسایی نشد).', "scan": r.get('scan', False)}); return
    items = []
    for k, s in enumerate(starts):
        e = (starts[k + 1] - 1) if k + 1 < len(starts) else n - 1
        fn = 'seg_%03d.pdf' % (k + 1)
        try:
            seg = fitz.open()
            seg.insert_pdf(doc, from_page=s, to_page=e)
            seg.save(os.path.join(outdir, fn), garbage=3, deflate=True)
            seg.close()
        except Exception as ex:
            out({"ok": False, "error": "جدا کردنِ صفحه‌ها ممکن نشد.", "debug": str(ex)[:300]}); return
        r = read_endorse(doc, range(s, e + 1))
        items.append({"page": s + 1, "end": e + 1, "file": fn, "ok": bool(r.get('ok')), "data": r.get('data') or {},
                      "statement": r.get('statement') or [], "missing": r.get('missing') or [], "error": r.get('error')})
    doc.close()
    out({"ok": True, "pages": n, "items": items})


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        out({"ok": False, "error": "خطای پیش‌بینی‌نشده در خواندنِ فایلِ گروهی.", "debug": str(e)[:400]})
