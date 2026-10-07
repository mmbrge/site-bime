# -*- coding: utf-8 -*-
# فایل: api/py/pdf_tools.py
# ابزارهای PDF برای صفحه‌ی «ابزارها» (api/tools_actions.php) با همان PyMuPDF که برای بیمه‌نامه‌ها استفاده می‌شود.
#   python3 pdf_tools.py <op> <outdir> <params.json> <file1.pdf> [file2.pdf ...]
#   op: merge | extract | split | compress | toimg | rotate | pages
#   pages: هر صفحه یک PDFِ جدا (p001.pdf, p002.pdf, ...) کنارِ هم در outdir — برای جدا کردنِ مدارکِ چندصفحه‌ای (api/_pdf_split.php)
# خروجی (stdout): JSON  {"ok": true, "file": "out.pdf", "name": "...", "pages": n, "size": bytes, "note": "..."}
import json
import os
import sys
import zipfile

try:
    import pymupdf as fitz  # PyMuPDF جدید
except Exception:  # نسخه‌های قدیمی‌تر
    import fitz


def out(o):
    sys.stdout.write(json.dumps(o, ensure_ascii=False))
    sys.stdout.flush()


def parse_ranges(spec, n):
    """«1-3, 5, 8-» => [0, 1, 2, 4, 7, ...] (شماره‌ی صفحه از ۱)"""
    spec = (spec or '').strip()
    fa = '۰۱۲۳۴۵۶۷۸۹'
    spec = ''.join(str(fa.index(c)) if c in fa else c for c in spec).replace('،', ',').replace('–', '-')
    if not spec:
        return list(range(n))
    pages = []
    for part in spec.split(','):
        part = part.strip()
        if not part:
            continue
        if '-' in part:
            a, b = part.split('-', 1)
            a = int(a) if a.strip() else 1
            b = int(b) if b.strip() else n
            if a > b:
                a, b = b, a
            pages.extend(range(a - 1, b))
        else:
            pages.append(int(part) - 1)
    pages = [p for p in pages if 0 <= p < n]
    if not pages:
        raise ValueError('صفحه‌ای در این بازه نیست (فایل %d صفحه دارد).' % n)
    return pages


def save_pdf(doc, path):
    try:
        doc.save(path, garbage=4, deflate=True, clean=True)
    except Exception:
        doc.save(path, garbage=3, deflate=True)


def compress(doc, quality, dpi):
    """عکس‌های داخلِ PDF با کیفیت/وضوحِ کمتر دوباره ذخیره می‌شوند"""
    done = False
    if hasattr(doc, 'rewrite_images'):
        try:
            doc.rewrite_images(dpi_threshold=int(dpi * 1.3), dpi_target=int(dpi), quality=int(quality), lossy=True, lossless=True, bitonal=True, color=True, gray=True)
            done = True
        except TypeError:
            try:
                doc.rewrite_images(dpi_threshold=int(dpi * 1.3), dpi_target=int(dpi), quality=int(quality))
                done = True
            except Exception:
                done = False
        except Exception:
            done = False
    if done:
        return
    # نسخه‌ی قدیمی‌ترِ PyMuPDF: هر عکسِ بزرگ کوچک و JPEG می‌شود
    seen = set()
    for page in doc:
        for img in page.get_images(full=True):
            xref = img[0]
            if xref in seen:
                continue
            seen.add(xref)
            try:
                pix = fitz.Pixmap(doc, xref)
                if pix.width * pix.height < 250000:
                    continue
                if pix.alpha or pix.n - pix.alpha > 3:
                    pix = fitz.Pixmap(fitz.csRGB, pix)
                shrink = 0
                while (pix.width >> (shrink + 1)) >= 1200:
                    shrink += 1
                if shrink:
                    pix.shrink(shrink)
                try:
                    data = pix.tobytes('jpeg', jpg_quality=int(quality))
                except TypeError:
                    data = pix.tobytes('jpeg')
                page.replace_image(xref, stream=data)
            except Exception:
                continue


def main():
    if len(sys.argv) < 5:
        out({'ok': False, 'error': 'ورودی کامل نیست.'})
        return
    op, outdir, pjson = sys.argv[1], sys.argv[2], sys.argv[3]
    files = sys.argv[4:]
    try:
        params = json.load(open(pjson, encoding='utf-8'))
    except Exception:
        params = {}
    base = (params.get('base') or 'خروجی').strip() or 'خروجی'
    os.makedirs(outdir, exist_ok=True)
    try:
        docs = [fitz.open(f) for f in files]
    except Exception as e:
        out({'ok': False, 'error': 'یکی از فایل‌ها PDFِ سالم نیست.', 'debug': str(e)[:200]})
        return
    for d in docs:
        if getattr(d, 'needs_pass', False):
            out({'ok': False, 'error': 'فایلِ رمزدار را نمی‌شود پردازش کرد؛ اول رمزش را بردارید.'})
            return

    try:
        if op == 'merge':
            res = fitz.open()
            for d in docs:
                res.insert_pdf(d)
            path = os.path.join(outdir, 'out.pdf')
            save_pdf(res, path)
            out({'ok': True, 'file': 'out.pdf', 'name': base + ' (ادغام).pdf', 'pages': len(res), 'size': os.path.getsize(path)})
        elif op == 'extract':
            d = docs[0]
            pages = parse_ranges(params.get('pages'), len(d))
            res = fitz.open()
            for p in pages:
                res.insert_pdf(d, from_page=p, to_page=p)
            path = os.path.join(outdir, 'out.pdf')
            save_pdf(res, path)
            out({'ok': True, 'file': 'out.pdf', 'name': base + ' (صفحه‌های انتخابی).pdf', 'pages': len(res), 'size': os.path.getsize(path)})
        elif op == 'split':
            d = docs[0]
            pages = parse_ranges(params.get('pages'), len(d))
            path = os.path.join(outdir, 'out.zip')
            with zipfile.ZipFile(path, 'w', zipfile.ZIP_STORED) as z:
                for p in pages:
                    one = fitz.open()
                    one.insert_pdf(d, from_page=p, to_page=p)
                    z.writestr('%s - صفحه %d.pdf' % (base, p + 1), one.tobytes(garbage=3, deflate=True))
            out({'ok': True, 'file': 'out.zip', 'name': base + ' (صفحه‌به‌صفحه).zip', 'pages': len(pages), 'size': os.path.getsize(path)})
        elif op == 'compress':
            d = docs[0]
            before = os.path.getsize(files[0])
            q = max(20, min(95, int(params.get('quality') or 60)))
            dpi = max(50, min(300, int(params.get('dpi') or 110)))
            compress(d, q, dpi)
            path = os.path.join(outdir, 'out.pdf')
            save_pdf(d, path)
            after = os.path.getsize(path)
            if after >= before:   # کوچک‌تر نشد: همان فایلِ اصلی
                import shutil
                shutil.copyfile(files[0], path)
                after = before
            out({'ok': True, 'file': 'out.pdf', 'name': base + ' (کم‌حجم).pdf', 'pages': len(d), 'size': after, 'before': before})
        elif op == 'toimg':
            d = docs[0]
            pages = parse_ranges(params.get('pages'), len(d))
            dpi = max(50, min(300, int(params.get('dpi') or 150)))
            fmt = 'png' if params.get('format') == 'png' else 'jpg'
            zoom = dpi / 72.0
            mat = fitz.Matrix(zoom, zoom)
            if len(pages) == 1:
                pix = d.load_page(pages[0]).get_pixmap(matrix=mat, alpha=False)
                path = os.path.join(outdir, 'out.' + fmt)
                pix.save(path)
                out({'ok': True, 'file': 'out.' + fmt, 'name': '%s - صفحه %d.%s' % (base, pages[0] + 1, fmt), 'pages': 1, 'size': os.path.getsize(path)})
            else:
                path = os.path.join(outdir, 'out.zip')
                with zipfile.ZipFile(path, 'w', zipfile.ZIP_STORED) as z:
                    for p in pages:
                        pix = d.load_page(p).get_pixmap(matrix=mat, alpha=False)
                        z.writestr('%s - صفحه %d.%s' % (base, p + 1, fmt), pix.tobytes('png' if fmt == 'png' else 'jpeg'))
                out({'ok': True, 'file': 'out.zip', 'name': base + ' (عکسِ صفحه‌ها).zip', 'pages': len(pages), 'size': os.path.getsize(path)})
        elif op == 'pages':
            d = docs[0]
            n = len(d)
            mx = max(1, min(200, int(params.get('max') or 60)))
            if n > mx:
                out({'ok': False, 'error': 'فایل %d صفحه دارد؛ بیشتر از %d صفحه جدا نمی‌شود.' % (n, mx), 'pages': n})
                return
            files_out = []
            thumbs = []
            if not params.get('count_only') and (n > 1 or params.get('force')):
                for p in range(n):
                    one = fitz.open()
                    one.insert_pdf(d, from_page=p, to_page=p)
                    name = 'p%03d.pdf' % (p + 1)
                    save_pdf(one, os.path.join(outdir, name))
                    files_out.append(name)
                    if params.get('thumbs'):   # پیش‌نمایشِ کوچک برای پنجره‌ی انتخابِ نوعِ هر صفحه
                        try:
                            pg = d.load_page(p)
                            z = 720.0 / max(pg.rect.width, pg.rect.height, 1)
                            pix = pg.get_pixmap(matrix=fitz.Matrix(z, z), alpha=False)
                            pix.save(os.path.join(outdir, 'p%03d.png' % (p + 1)))
                            thumbs.append('p%03d.png' % (p + 1))
                        except Exception:
                            pass
            out({'ok': True, 'pages': n, 'files': files_out, 'thumbs': thumbs})
        elif op == 'rotate':
            d = docs[0]
            pages = parse_ranges(params.get('pages'), len(d))
            ang = int(params.get('angle') or 90)
            if ang not in (90, 180, 270):
                ang = 90
            for p in pages:
                pg = d.load_page(p)
                pg.set_rotation((pg.rotation + ang) % 360)
            path = os.path.join(outdir, 'out.pdf')
            save_pdf(d, path)
            out({'ok': True, 'file': 'out.pdf', 'name': base + ' (چرخیده).pdf', 'pages': len(d), 'size': os.path.getsize(path)})
        else:
            out({'ok': False, 'error': 'عملیاتِ نامعتبر.'})
    except ValueError as e:
        out({'ok': False, 'error': str(e)})
    except Exception as e:
        out({'ok': False, 'error': 'پردازشِ PDF ممکن نشد.', 'debug': str(e)[:300]})


if __name__ == '__main__':
    main()
