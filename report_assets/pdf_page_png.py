# فایل: report_assets/pdf_page_png.py
# یک صفحه از PDF را به PNG تبدیل می‌کند (برای «خروجی دقیق» در ویرایشگرِ قالب گزارش)
# اجرا: python3 pdf_page_png.py in.pdf page_index zoom out.png
import sys
try:
    import fitz
except Exception:
    import pymupdf as fitz
doc = fitz.open(sys.argv[1])
page = doc[int(sys.argv[2])]
z = float(sys.argv[3])
page.get_pixmap(matrix=fitz.Matrix(z, z), alpha=False).save(sys.argv[4])
print('{"ok": true}')
