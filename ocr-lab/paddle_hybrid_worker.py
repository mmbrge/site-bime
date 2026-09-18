import os
import time
import json
import traceback
import fitz  # PyMuPDF
import mysql.connector
from paddleocr import PaddleOCR

# ایمپورت توابع پردازش متنی شما از context_processor.py
try:
    from context_processor import extract_insurance_data
except ImportError:
    print("خطا: فایل context_processor.py یافت نشد!")
    def extract_insurance_data(text): return {"error": "پرازنده یافت نشد"}

# مسیر سایت بر اساس ساختار هاست شما
SITE_ROOT = "/home/besiteir/mammut.bbama.ir"
QUEUE_PENDING = os.path.join(SITE_ROOT, "queue", "pending")
QUEUE_DONE = os.path.join(SITE_ROOT, "queue", "done")

# ----------------- دیتابیس -----------------
# رمز عبور را داخل کد ننویسید؛ قبل از اجرا با export DB_PASSWORD='...' ست کنید
DB_CONFIG = {
    "host": "localhost",
    "user": "besiteir_dbmu",
    "password": os.environ.get("DB_PASSWORD", ""),
    "database": "besiteir_dbm",
    "charset": "utf8mb4"
}

if not DB_CONFIG["password"]:
    raise RuntimeError("متغیر محیطی DB_PASSWORD تنظیم نشده است.")

# ----------------- راه‌اندازی موتور -----------------
print("در حال بارگذاری موتور هوش مصنوعی PaddleOCR...")
try:
    ocr_engine = PaddleOCR(use_angle_cls=True, lang='fa', show_log=False)
    print("موتور با موفقیت لود شد.")
except Exception as e:
    print(f"خطای بحرانی در لود موتور: {e}")

# ----------------- توابع پردازش -----------------
def process_with_ocr(img_path):
    """ خواندن تصویر با Paddle و ارسال متن به الگوریتم اصلی شما """
    result = ocr_engine.ocr(img_path, cls=True)
    raw_text = ""
    if result and result[0]:
        for line in result[0]:
            # خطوط خوانده شده را با اسپیس و اینتر به هم می‌چسبانیم
            raw_text += line[1][0] + " \n"
            
    # پاس دادن متن استخراج شده از عکس به توابع Regex شما!
    return extract_insurance_data(raw_text)

def process_file(file_path):
    ext = file_path.lower().split('.')[-1]
    
    if ext == 'pdf':
        doc = fitz.open(file_path)
        text = ""
        for page in doc:
            text += page.get_text()
            
        # سیستم هوشمند تشخیص نوع PDF
        if len(text.strip()) > 50: 
            print("--> PDF متنی تشخیص داده شد. استفاده از PyMuPDF...")
            return extract_insurance_data(text) 
        else:
            print("--> PDF اسکن‌شده تشخیص داده شد. تبدیل به عکس و ارسال به OCR...")
            # افزایش کیفیت رندر برای OCR بهتر
            pix = doc.load_page(0).get_pixmap(matrix=fitz.Matrix(2, 2))
            img_path = file_path + ".jpg"
            pix.save(img_path)
            data = process_with_ocr(img_path)
            os.remove(img_path)
            return data
            
    elif ext in ['jpg', 'jpeg', 'png']:
        print("--> تصویر تشخیص داده شد. استفاده از PaddleOCR...")
        return process_with_ocr(file_path)
    
    return {"error": "فرمت نامعتبر"}

# ----------------- حلقه اصلی (Worker) -----------------
print("ربات پایتون آماده دریافت فایل‌هاست...")
while True:
    db = None
    try:
        db = mysql.connector.connect(**DB_CONFIG)
        cursor = db.cursor(dictionary=True)
        
        # خواندن قدیمی‌ترین فایل در انتظار
        cursor.execute("SELECT id, file_path FROM processing_queue WHERE status = 'PENDING' ORDER BY uploaded_at ASC LIMIT 1")
        task = cursor.fetchone()
        
        if task:
            task_id = task['id']
            full_file_path = os.path.join(SITE_ROOT, task['file_path'])
            
            print(f"\n[+] شروع پردازش شناسه: {task_id}")
            
            if not os.path.exists(full_file_path):
                print(f"[-] فایل فیزیکی پیدا نشد: {full_file_path}")
                cursor.execute("UPDATE processing_queue SET status = 'FAILED' WHERE id = %s", (task_id,))
                db.commit()
                continue

            cursor.execute("UPDATE processing_queue SET status = 'PROCESSING' WHERE id = %s", (task_id,))
            db.commit()
            
            # انجام عملیات استخراج
            extracted_json = process_file(full_file_path)
            print(f"[*] نتایج استخراج: {extracted_json}")
            
            # انتقال فایل به پوشه done
            filename = os.path.basename(full_file_path)
            done_path = os.path.join(QUEUE_DONE, filename)
            os.rename(full_file_path, done_path)
            
            # ثبت در دیتابیس
            cursor.execute("""
                UPDATE processing_queue 
                SET status = 'DONE', extracted_data = %s, file_path = %s, processed_at = NOW() 
                WHERE id = %s
            """, (json.dumps(extracted_json, ensure_ascii=False), f"queue/done/{filename}", task_id))
            db.commit()
            print(f"[+] پردازش با موفقیت تمام شد.")
            
    except Exception as e:
        print(f"[!] خطای سرور: {e}")
        traceback.print_exc()
        try:
            if 'task_id' in locals() and db:
                cursor.execute("UPDATE processing_queue SET status = 'FAILED' WHERE id = %s", (task_id,))
                db.commit()
        except: pass
    finally:
        if db and db.is_connected():
            cursor.close()
            db.close()
            
    time.sleep(2)