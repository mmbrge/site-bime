# -*- coding: utf-8 -*-
# فایل: api/py/legacy_scan.py
# «ورودِ بایگانی‌های قبلی»: از متنِ صفحه‌های اولِ چند PDF، شماره‌ی بیمه‌نامه، شماره‌ی شاسی و پلاک را پیدا می‌کند
# (برای پوشه‌های نامرتب که از نامِ فایل نمی‌شود فهمید مالِ کدام بیمه‌نامه‌اند)
#   python3 legacy_scan.py <list.json>      (list.json: ["/abs/a.pdf", ...])
# خروجی (آخرین خط): {"ok": true, "files": {"/abs/a.pdf": {"policies": [...], "vins": [...], "plates": [...], "kind": "policy|endorse|statement|other", "text": bool}}}
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from policy_bundle import out, load_fitz, normalize_text, parse_plate  # noqa: E402

POLICY_RX = re.compile(r'(\d{1,2}/\d{3,6}/\d{3,6}-\d{1,3}/1[34]\d{2}/\d{1,7})')
VIN_RX = re.compile(r'(?<![A-Z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Z0-9])')


def main():
    if len(sys.argv) < 2:
        out({"ok": False, "error": "usage"}); return
    fitz = load_fitz()
    if fitz is None:
        out({"ok": False, "error": "PyMuPDF نصب نیست."}); return
    files = json.load(open(sys.argv[1], encoding='utf-8'))
    res = {}
    for f in files:
        info = {"policies": [], "vins": [], "plates": [], "kind": "other", "text": False}
        try:
            d = fitz.open(f)
            t = ''
            for i in range(min(2, len(d))):
                t += (d.load_page(i).get_text() or '') + '\n'
            d.close()
            t = normalize_text(t)
            info["text"] = len(t.strip()) > 30
            info["policies"] = list(dict.fromkeys(POLICY_RX.findall(t)))[:5]
            info["vins"] = [v for v in dict.fromkeys(VIN_RX.findall(t.upper())) if re.search(r'[A-Z]', v) and re.search(r'\d', v)][:3]
            try:
                p = parse_plate(t)
                if p:
                    info["plates"] = [p]
            except Exception:
                pass
            if 'الحاقیه' in t[:1500]:
                info["kind"] = "endorse"
            elif re.search(r'اعلامیه\s*آخرین\s*وضعیت|سند\s*دریافت', t[:800]):
                info["kind"] = "statement"
            elif info["policies"] and re.search(r'بیمه\s*نامه|بیمه‌نامه', t[:1500]):
                info["kind"] = "policy"
        except Exception as e:
            info["error"] = str(e)[:120]
        res[f] = info
    out({"ok": True, "files": res})


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        out({"ok": False, "error": str(e)[:300]})
