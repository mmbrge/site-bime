import os
import sys
import json
import random
import uuid
import jdatetime
from datetime import datetime
from sqlalchemy import (
    create_engine, Column, Integer, String, Float, Date, DateTime, Text, ForeignKey, func, text, Boolean, or_, event
)
from sqlalchemy.orm import declarative_base, sessionmaker, relationship

def _resolve_app_dir():
    if getattr(sys, 'frozen', False):
        return getattr(sys, '_MEIPASS', os.path.dirname(sys.executable))
    return os.path.dirname(os.path.abspath(__file__))

def _resolve_app_data_dir():
    """پوشه ثابت ذخیره‌سازی اطلاعات و تنظیمات برنامه، مستقل از محل نصب یا اجرای فایل exe."""
    target = os.path.join("C:\\", "Bime Ba Ma", "مالی ماموت - کسر از حقوق")
    try:
        os.makedirs(target, exist_ok=True)
        return target
    except Exception:
        return _resolve_app_dir()

BASE_DIR = _resolve_app_data_dir()
SETTINGS_FILE = os.path.join(BASE_DIR, "settings.json")

def load_settings():
    if not os.path.exists(SETTINGS_FILE): return {}
    with open(SETTINGS_FILE, "r", encoding="utf-8") as f: return json.load(f)

def en_digits(text_str):
    text_str = str(text_str)
    for p, a, e in zip("۰۱۲۳۴۵۶۷۸۹", "٠١٢٣٤٥٦٧٨٩", "0123456789"):
        text_str = text_str.replace(p, e).replace(a, e)
    return text_str

settings = load_settings()
database_path = os.path.join(BASE_DIR, settings.get("database_path", "MammutERP_Data/data/mammut.db"))
_db_dir = os.path.dirname(database_path)
if _db_dir:
    os.makedirs(_db_dir, exist_ok=True)

DATABASE_URL = "sqlite:///" + database_path
engine = create_engine(DATABASE_URL, echo=False, connect_args={"check_same_thread": False})

@event.listens_for(engine, "connect")
def _enable_sqlite_fk(dbapi_connection, connection_record):
    try:
        cursor = dbapi_connection.cursor()
        cursor.execute("PRAGMA foreign_keys=ON")
        cursor.close()
    except Exception:
        pass

SessionLocal = sessionmaker(bind=engine)
Base = declarative_base()

class Company(Base):
    __tablename__ = "companies"
    id = Column(Integer, primary_key=True)
    name = Column(String(255), nullable=False)
    code = Column(String(100))
    phone = Column(String(100))
    created_date = Column(DateTime, default=datetime.now)
    policies = relationship("Policy", back_populates="company")

class Contact(Base):
    __tablename__ = "contacts"
    id = Column(Integer, primary_key=True)
    company_id = Column(Integer, ForeignKey("companies.id"))
    name = Column(String(255))
    position = Column(String(255))
    phone = Column(String(100))

class InsurancePeriod(Base):
    __tablename__ = "insurance_periods"
    id = Column(Integer, primary_key=True)
    title = Column(String(100))
    start_date = Column(Date)
    end_date = Column(Date)

class ImportBatch(Base):
    __tablename__ = "import_batches"
    id = Column(Integer, primary_key=True)
    file_name = Column(String(255))
    period_name = Column(String(100))
    status = Column(String(50), default="processing")
    created_date = Column(DateTime, default=datetime.now)

class ImportReview(Base):
    __tablename__ = "import_reviews"
    id = Column(Integer, primary_key=True)
    batch_id = Column(Integer)
    row_number = Column(Integer)
    raw_data = Column(Text)
    confidence_score = Column(Float)
    reason = Column(Text)
    status = Column(String(50), default="pending")

class Policy(Base):
    __tablename__ = "policies"
    id = Column(Integer, primary_key=True)
    company_id = Column(Integer, ForeignKey("companies.id"))
    source_batch_id = Column(Integer)
    policy_number = Column(String(100))
    contract_number = Column(String(100))
    insured_name = Column(String(255))
    premium_amount = Column(Float, default=0)
    issue_date = Column(String(50))
    national_code = Column(String(50))
    plate = Column(String(100))
    personnel_code = Column(String(50))
    policy_type = Column(String(50))
    contract_company = Column(String(255))
    period_name = Column(String(100))
    is_invoiced = Column(Boolean, default=False)
    invoice_number = Column(String(100), nullable=True)
    company = relationship("Company", back_populates="policies")

class Receipt(Base):
    __tablename__ = "receipts"
    id = Column(Integer, primary_key=True)
    receipt_type = Column(String(50))
    amount = Column(Float)
    payment_date = Column(String(50))
    description = Column(Text)
    file_path = Column(String(1000))
    target = Column(String(50))

class Installment(Base):
    __tablename__ = "installments"
    id = Column(Integer, primary_key=True)
    tracking_id = Column(String(20), unique=True)
    policy_id = Column(Integer, ForeignKey("policies.id"))
    installment_number = Column(Integer)
    amount = Column(Float)
    paid_amount_us = Column(Float, default=0)
    paid_amount_pasargad = Column(Float, default=0)
    due_date = Column(String(50))
    status_us = Column(String(50), default="open")
    status_pasargad = Column(String(50), default="open")
    receipt_us_id = Column(Integer, ForeignKey("receipts.id"), nullable=True)
    receipt_pasargad_id = Column(Integer, ForeignKey("receipts.id"), nullable=True)
    policy = relationship("Policy")

class PaymentLog(Base):
    __tablename__ = "payment_logs"
    id = Column(Integer, primary_key=True)
    installment_id = Column(Integer, ForeignKey("installments.id"))
    receipt_id = Column(Integer, ForeignKey("receipts.id"))
    target = Column(String(50))
    amount = Column(Float)
    paid_at = Column(DateTime, default=datetime.now)
    registered_by = Column(String(100))

class Invoice(Base):
    __tablename__ = "invoices"
    id = Column(Integer, primary_key=True)
    company_id = Column(Integer, nullable=True)
    invoice_number = Column(String(100))
    company_name = Column(String(255), nullable=True)
    period_name = Column(String(100), nullable=True)
    total_policies = Column(Integer, default=0)
    total_amount = Column(Float, default=0)
    created_date = Column(String(50))
    folder_path = Column(String(1000), nullable=True)
    status = Column(String(50), default="open")

def init_database():
    Base.metadata.create_all(engine)
    migrations = [
        "ALTER TABLE installments ADD COLUMN paid_amount_us FLOAT DEFAULT 0",
        "ALTER TABLE installments ADD COLUMN paid_amount_pasargad FLOAT DEFAULT 0",
        "ALTER TABLE installments ADD COLUMN status_us VARCHAR(50) DEFAULT 'open'",
        "ALTER TABLE installments ADD COLUMN status_pasargad VARCHAR(50) DEFAULT 'open'",
        "ALTER TABLE installments ADD COLUMN tracking_id VARCHAR(20)",
        "ALTER TABLE installments ADD COLUMN receipt_us_id INTEGER",
        "ALTER TABLE installments ADD COLUMN receipt_pasargad_id INTEGER",
        "ALTER TABLE receipts ADD COLUMN target VARCHAR(50)",
        "ALTER TABLE policies ADD COLUMN is_invoiced BOOLEAN DEFAULT 0",
        "ALTER TABLE policies ADD COLUMN invoice_number VARCHAR(100)",
        "ALTER TABLE policies ADD COLUMN personnel_code VARCHAR(50)",
        "ALTER TABLE invoices ADD COLUMN company_name VARCHAR(255)",
        "ALTER TABLE invoices ADD COLUMN period_name VARCHAR(100)",
        "ALTER TABLE invoices ADD COLUMN total_policies INTEGER DEFAULT 0",
        "ALTER TABLE invoices ADD COLUMN total_amount FLOAT DEFAULT 0",
        "ALTER TABLE invoices ADD COLUMN created_date VARCHAR(50)",
        "ALTER TABLE invoices ADD COLUMN folder_path VARCHAR(1000)"
    ]
    with engine.connect() as conn:
        for m in migrations:
            try:
                conn.execute(text(m))
                conn.commit()
            except Exception:
                pass

def get_session(): return SessionLocal()

def _is_valid_shamsi_date(y, m, d):
    if not (1 <= m <= 12): return False
    if m <= 6: max_day = 31
    elif m <= 11: max_day = 30
    else:
        is_leap = (y % 33) in (1, 5, 9, 13, 17, 22, 26, 30)
        max_day = 30 if is_leap else 29
    return 1 <= d <= max_day

def _generate_unique_tracking_id(db, max_attempts=10):
    for _ in range(max_attempts):
        t_id = str(random.randint(1000000000, 9999999999))
        exists = db.query(Installment.id).filter(Installment.tracking_id == t_id).first()
        if not exists: return t_id
    return uuid.uuid4().hex[:16].upper()

class CompanyManager:
    @staticmethod
    def get_or_create(name):
        db = get_session()
        try:
            comp = db.query(Company).filter(Company.name == name).first()
            if not comp:
                comp = Company(name=name)
                db.add(comp); db.commit(); db.refresh(comp)
            return comp
        finally:
            db.close()

class PolicyManager:
    @staticmethod
    def create(company_id, policy_number, insured_name, premium_amount, contract_number=None, policy_type=None, plate=None, national_code=None, issue_date=None, contract_company=None, personnel_code=None, period_name=None, source_batch_id=None):
        db = get_session()
        try:
            exists = db.query(Policy).filter(Policy.policy_number == policy_number).first()
            if exists:
                raise Exception("این شماره بیمه نامه قبلاً ثبت شده است")
            policy = Policy(
                company_id=company_id, policy_number=policy_number, insured_name=insured_name,
                premium_amount=premium_amount, contract_number=contract_number, policy_type=policy_type,
                plate=plate, national_code=national_code, issue_date=issue_date,
                contract_company=contract_company, personnel_code=personnel_code,
                period_name=period_name, source_batch_id=source_batch_id, is_invoiced=False
            )
            db.add(policy); db.commit(); db.refresh(policy)
            return policy
        finally:
            db.close()

class InstallmentManager:
    @staticmethod
    def calculate(amount, count, method="mamoot"):
        if amount <= 0: raise Exception("مبلغ نامعتبر است")
        if count == 0: return [{"number": 1, "amount": int(amount)}]

        if method == "normal":
            base = amount // count
            rem = amount % count
            res = []
            for i in range(1, count + 1):
                val = base + (1 if i <= rem else 0)
                res.append({"number": i, "amount": int(val)})
            return res

        if count <= 1: return [{"number": 1, "amount": int(amount)}]

        base_floor = (int(amount / count) // 1000) * 1000
        if base_floor == 0:
            base = amount // count
            rem = amount % count
            res = []
            for i in range(1, count + 1):
                val = base + (1 if i <= rem else 0)
                res.append({"number": i, "amount": int(val)})
            return res

        rem_total = amount - (base_floor * count)
        first_pay = round((base_floor + rem_total) / 1000) * 1000
        first_pay = min(first_pay, int(amount))
        first_pay = max(first_pay, base_floor)

        remaining = amount - first_pay
        other_pay = round((remaining / (count - 1)) / 1000) * 1000 if count > 1 else 0
        other_pay = max(0, int(other_pay))

        res = [{"number": 1, "amount": int(first_pay)}]
        for i in range(2, count + 1):
            res.append({"number": i, "amount": other_pay})

        total = sum(x["amount"] for x in res)
        diff = amount - total
        if diff != 0:
            res[-1]["amount"] += int(diff)

        for item in res:
            if item["amount"] < 0: item["amount"] = 0
        return res

    @staticmethod
    def calculate_due_date(issue_date, months_to_add):
        if not issue_date: return None
        try:
            y, m, d = map(int, str(issue_date).split()[0].replace("-", "/").split("/"))
            m += months_to_add
            while m > 12:
                m -= 12; y += 1
            if not _is_valid_shamsi_date(y, m, d):
                if m <= 6: d = min(d, 31)
                elif m <= 11: d = min(d, 30)
                else:
                    is_leap = (y % 33) in (1, 5, 9, 13, 17, 22, 26, 30)
                    d = min(d, 30 if is_leap else 29)
            return f"{y:04d}/{m:02d}/{d:02d}"
        except Exception:
            return None

    @staticmethod
    def create(policy_id, amount, count, method="mamoot", issue_date=None):
        db = get_session()
        created = []
        try:
            installments = InstallmentManager.calculate(amount, count, method)
            for item in installments:
                months = 0 if count == 0 else item["number"]
                due_date = InstallmentManager.calculate_due_date(issue_date, months)
                t_id = _generate_unique_tracking_id(db)
                inst = Installment(
                    tracking_id=t_id, policy_id=policy_id,
                    installment_number=item["number"], amount=item["amount"],
                    paid_amount_us=0, paid_amount_pasargad=0,
                    due_date=due_date, status_us="open", status_pasargad="open"
                )
                db.add(inst); created.append(inst)
            db.commit()
            for inst in created: db.refresh(inst)
            return created
        except Exception:
            db.rollback()
            raise
        finally:
            db.close()

    @staticmethod
    def get_all_with_policies():
        db = get_session()
        try:
            return db.query(Installment, Policy).join(Policy).all()
        finally:
            db.close()

    @staticmethod
    def get_by_id(inst_id):
        db = get_session()
        try:
            iid = int(inst_id)
            return db.query(Installment, Policy).join(Policy).filter(Installment.id == iid).first()
        except (TypeError, ValueError):
            return None
        finally:
            db.close()

    @staticmethod
    def register_payment(payments_data, target, method, notes, files_str, registered_by=None):
        db = get_session()
        try:
            valid_payments = []
            for inst_id, pay_amt in payments_data:
                try:
                    amt = float(pay_amt)
                    if amt > 0:
                        valid_payments.append((int(inst_id), amt))
                except Exception:
                    pass

            if not valid_payments:
                return {"updated": 0, "applied": [], "rejected": []}

            applied_payments = []
            rejected = []

            for inst_id, pay_amount in valid_payments:
                inst = db.query(Installment).filter(Installment.id == inst_id).first()
                if not inst:
                    rejected.append((inst_id, pay_amount, "شناسه قسط یافت نشد"))
                    continue

                if target == "pasargad":
                    available = float(inst.paid_amount_us or 0.0) - float(inst.paid_amount_pasargad or 0.0)
                    if pay_amount > available + 0.5:
                        rejected.append((inst_id, pay_amount,
                            f"مانده دریافتی کارگزاری فقط {available:,.0f} ریال است"))
                        continue

                applied_payments.append((inst, pay_amount))

            if not applied_payments:
                return {"updated": 0, "applied": [], "rejected": rejected}

            total_pay = sum(x[1] for x in applied_payments)
            receipt = Receipt(
                receipt_type=method,
                amount=total_pay,
                payment_date=jdatetime.date.today().strftime('%Y/%m/%d'),
                description=notes,
                file_path=files_str,
                target=target
            )
            db.add(receipt)
            db.flush()

            updated = 0
            applied_ids = []
            for inst, pay_amount in applied_payments:
                if target == "us":
                    current_paid = float(inst.paid_amount_us or 0.0)
                    inst.paid_amount_us = current_paid + pay_amount
                    inst.receipt_us_id = receipt.id
                    if inst.paid_amount_us >= inst.amount - 0.5:
                        inst.paid_amount_us = inst.amount
                        inst.status_us = "paid"
                    elif inst.paid_amount_us > 0:
                        inst.status_us = "partial"
                elif target == "pasargad":
                    current_paid = float(inst.paid_amount_pasargad or 0.0)
                    inst.paid_amount_pasargad = current_paid + pay_amount
                    inst.receipt_pasargad_id = receipt.id
                    if inst.paid_amount_pasargad >= inst.amount - 0.5:
                        inst.paid_amount_pasargad = inst.amount
                        inst.status_pasargad = "paid"
                    elif inst.paid_amount_pasargad > 0:
                        inst.status_pasargad = "partial"

                db.add(PaymentLog(
                    installment_id=inst.id, receipt_id=receipt.id, target=target,
                    amount=pay_amount, paid_at=datetime.now(),
                    registered_by=registered_by or "کاربر سیستم"
                ))
                updated += 1
                applied_ids.append((inst.id, pay_amount))

            db.commit()
            return {"updated": updated, "applied": applied_ids, "rejected": rejected}
        except Exception:
            db.rollback()
            raise
        finally:
            db.close()

    @staticmethod
    def get_outstanding(target=None):
        db = get_session()
        try:
            q = db.query(Installment, Policy).join(Policy)
            status_us = func.coalesce(Installment.status_us, "open")
            status_pas = func.coalesce(Installment.status_pasargad, "open")
            if target == "us":
                q = q.filter(status_us != "paid")
            elif target == "pasargad":
                q = q.filter(status_pas != "paid")
            else:
                q = q.filter(or_(status_us != "paid", status_pas != "paid"))
            return q.order_by(Installment.due_date.asc()).all()
        finally:
            db.close()

    @staticmethod
    def get_payment_history(installment_id):
        db = get_session()
        try:
            iid = int(installment_id)
            return db.query(PaymentLog).filter(PaymentLog.installment_id == iid).order_by(PaymentLog.paid_at.asc()).all()
        except (TypeError, ValueError):
            return []
        finally:
            db.close()

class InvoiceManager:
    @staticmethod
    def get_all():
        db = get_session()
        try:
            return db.query(Invoice).all()
        finally:
            db.close()

class DashboardManager:
    @staticmethod
    def get_summary(filter_type="همه دوره‌ها"):
        db = get_session()
        try:
            q_comp = db.query(Company); q_pol = db.query(Policy)
            q_inst = db.query(Installment); q_inv = db.query(Invoice)

            if filter_type == "سال جاری":
                y = str(jdatetime.date.today().year)
                q_pol = q_pol.filter(Policy.issue_date.startswith(y))
            elif filter_type == "دوره اخیر":
                latest_pol = db.query(Policy).order_by(Policy.issue_date.desc()).first()
                if latest_pol and latest_pol.period_name:
                    q_pol = q_pol.filter(Policy.period_name == latest_pol.period_name)

            companies = q_comp.count()
            policies = q_pol.count()
            total_premium = q_pol.with_entities(func.sum(Policy.premium_amount)).scalar() or 0
            installments = q_inst.count()
            open_invoices = q_inv.filter(Invoice.status == "issued").count()

            chart_data_raw = db.query(Policy.period_name, func.sum(Policy.premium_amount)).group_by(Policy.period_name).all()
            months_order = {"فروردین": 1, "اردیبهشت": 2, "خرداد": 3, "تیر": 4, "مرداد": 5, "شهریور": 6,
                            "مهر": 7, "آبان": 8, "آذر": 9, "دی": 10, "بهمن": 11, "اسفند": 12}

            def sort_key(item):
                if not item[0]: return (0, 0)
                parts = str(item[0]).split()
                if len(parts) >= 2: return (int(en_digits(parts[1])), months_order.get(parts[0], 0))
                return (0, 0)

            chart_data = sorted(chart_data_raw, key=sort_key)
            return {"companies": companies, "policies": policies, "premium": total_premium,
                    "installments": installments, "open_invoices": open_invoices, "chart_data": chart_data}
        finally:
            db.close()

    @staticmethod
    def get_alerts():
        db = get_session()
        try:
            today_str = jdatetime.date.today().strftime('%Y/%m/%d')
            near_str = (jdatetime.date.today() + jdatetime.timedelta(days=30)).strftime('%Y/%m/%d')
            status_us = func.coalesce(Installment.status_us, "open")

            overdue = db.query(Installment, Policy).join(Policy).filter(
                status_us != 'paid', Installment.due_date < today_str
            ).order_by(Installment.due_date.asc()).all()
            upcoming = db.query(Installment, Policy).join(Policy).filter(
                status_us != 'paid', Installment.due_date >= today_str, Installment.due_date <= near_str
            ).order_by(Installment.due_date.asc()).all()
            unpaid = db.query(Installment, Policy).join(Policy).filter(
                status_us != 'paid'
            ).order_by(Installment.due_date.asc()).all()

            return {"overdue": overdue, "upcoming": upcoming, "unpaid": unpaid}
        finally:
            db.close()