# فایل: api/py/heic_convert.py
# تبدیلِ عکسِ HEIC/HEIF (آیفون) به PNG برای ذخیره در بایگانی.
# اجرا: python3 heic_convert.py <ورودی> <خروجیِ png>     خروجی: آخرین خط JSON {"ok": true, "w": .., "h": ..}
# نیاز: pip install pillow-heif   (Pillow هم لازم است)
import json
import sys


def main():
    if len(sys.argv) < 3:
        print(json.dumps({'ok': False, 'error': 'usage: heic_convert.py <in> <out.png>'}))
        return
    src, dst = sys.argv[1], sys.argv[2]
    try:
        from PIL import Image, ImageOps
        import pillow_heif
        pillow_heif.register_heif_opener()
    except Exception as e:
        print(json.dumps({'ok': False, 'error': 'pillow-heif نصب نیست: ' + str(e)[:120]}, ensure_ascii=False))
        return
    try:
        im = Image.open(src)
        im = ImageOps.exif_transpose(im)   # جهتِ عکس (عمودی/افقی) همان‌طور که در گوشی دیده می‌شود
        # عکسِ ۱۲ مگاپیکسلیِ آیفون به‌صورتِ PNG خیلی حجیم می‌شود؛ ضلعِ بزرگ حداکثر ۳۲۰۰ پیکسل (مثلِ بقیه‌ی عکس‌های سایت)
        if max(im.size) > 3200:
            r = 3200 / max(im.size)
            im = im.resize((max(1, round(im.width * r)), max(1, round(im.height * r))), Image.LANCZOS)
        if im.mode not in ('RGB', 'RGBA', 'L', 'LA'):
            im = im.convert('RGBA' if 'A' in im.getbands() else 'RGB')
        im.save(dst, format='PNG', optimize=True)
        print(json.dumps({'ok': True, 'w': im.width, 'h': im.height}))
    except Exception as e:
        print(json.dumps({'ok': False, 'error': str(e)[:200]}, ensure_ascii=False))


if __name__ == '__main__':
    main()
