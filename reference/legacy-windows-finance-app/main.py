import sys
import os
import json
import jdatetime
import platform
import getpass
import re
from datetime import datetime

from PySide6.QtWidgets import (
    QApplication, QWidget, QLabel, QPushButton, QVBoxLayout,
    QHBoxLayout, QFrame, QFileDialog, QSpinBox, QRadioButton,
    QMessageBox, QTableWidget, QTableWidgetItem, QStackedWidget,
    QTabWidget, QDialog, QLineEdit, QCheckBox, QHeaderView, QComboBox, QMenu, QGridLayout,
    QProgressBar
)
from PySide6.QtGui import QFontDatabase, QFont, QColor
from PySide6.QtCore import Qt, QTimer, Signal, QThread
from matplotlib.backends.backend_qtagg import FigureCanvasQTAgg
from matplotlib.figure import Figure
from matplotlib.ticker import FuncFormatter
import matplotlib.pyplot as plt

from database import init_database, DashboardManager, InstallmentManager, get_session, Policy, Invoice
from excel_manager import ExcelEngine, setup_directories, DATA_ROOT, BackupManager

def _resolve_app_dir():
    """پوشه‌ای که فایل اجرایی (exe) یا اسکریپت در آن قرار دارد؛ برای پیدا کردن منابع همراه برنامه مثل فونت‌ها."""
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

BASE_DIR = _resolve_app_dir()
APP_DATA_DIR = _resolve_app_data_dir()
SETTINGS_FILE = os.path.join(APP_DATA_DIR, "settings.json")

try: SYS_USER = getpass.getuser()
except: SYS_USER = "کاربر سیستم"

_BIDI_CTRL = ('\u202A','\u202B','\u202C','\u202D','\u202E','\u200E','\u200F','\u2066','\u2067','\u2068','\u2069')

def load_settings():
    if not os.path.exists(SETTINGS_FILE): return {}
    with open(SETTINGS_FILE, "r", encoding="utf-8") as f: return json.load(f)

def save_settings(data):
    with open(SETTINGS_FILE, "w", encoding="utf-8") as f: json.dump(data, f, ensure_ascii=False, indent=4)

def to_persian_digits(value):
    text = str(value)
    for e, p in zip("0123456789", "۰۱۲۳۴۵۶۷۸۹"): text = text.replace(e, p)
    return text

def en_digits(text_str):
    t = str(text_str)
    return t.translate(str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789"))

def format_number(value):
    try:
        val = float(value)
        return to_persian_digits(f"{val:,.0f}")
    except:
        return to_persian_digits(value)

def format_plate_for_display(plate):
    if not plate: return ""
    p = str(plate).strip()
    for m in _BIDI_CTRL:
        p = p.replace(m, '')
    if not p: return ""
    # کلمه «ایران» باید پیش از اعمال جهت LTR برعکس نوشته شود تا پس از رندر، به‌صورت صحیح «ایران» نمایش داده شود
    p = p.replace("ایران", "ناریا")
    return f"\u202D{p}\u202C"

def get_status_color(status_text):
    is_dark = load_settings().get("dark_mode", True)
    s = str(status_text)
    if "تسویه" in s:
        return QColor(40, 85, 40) if is_dark else QColor(180, 240, 180)
    if "پاسارگاد" in s and "پرداخت شده" in s:
        return QColor(30, 70, 110) if is_dark else QColor(190, 225, 255)
    if "آماده پرداخت به پاسارگاد" in s:
        return QColor(60, 90, 40) if is_dark else QColor(220, 240, 200)
    if "پرداخت شده" in s:
        return QColor(110, 90, 30) if is_dark else QColor(255, 245, 200)
    if "مانده دار" in s:
        return QColor(110, 90, 30) if is_dark else QColor(255, 235, 190)
    if "پرداخت نشده" in s or "آماده پرداخت" in s:
        return QColor(110, 50, 50) if is_dark else QColor(255, 200, 200)
    return None

def format_input_live(text, line_edit):
    clean = re.sub(r'[^\d]', '', en_digits(text))
    if not clean: return
    try:
        num = int(clean)
        formatted = to_persian_digits(f"{num:,}")
        if line_edit.text() != formatted:
            line_edit.blockSignals(True)
            line_edit.setText(formatted)
            line_edit.blockSignals(False)
    except:
        line_edit.blockSignals(True)
        line_edit.setText(line_edit.text()[:-1])
        line_edit.blockSignals(False)

def clear_layout(layout):
    if layout is not None:
        while layout.count():
            item = layout.takeAt(0)
            widget = item.widget()
            if widget is not None: widget.deleteLater()
            else: clear_layout(item.layout())

def align_table_items(table):
    for i in range(table.rowCount()):
        for j in range(table.columnCount()):
            item = table.item(i, j)
            if item:
                item.setTextAlignment(Qt.AlignCenter)

def setup_live_search(search_input, table):
    def filter_table():
        text = search_input.text().lower()
        for row in range(table.rowCount()):
            match = False
            for col in range(table.columnCount()):
                item = table.item(row, col)
                if item and text in item.text().lower(): match = True; break
            table.setRowHidden(row, not match)
    search_input.textChanged.connect(filter_table)

class DateInputWidget(QWidget):
    changed = Signal()
    def __init__(self, placeholder=""):
        super().__init__()
        layout = QHBoxLayout(self)
        layout.setContentsMargins(0, 0, 0, 0)
        layout.setSpacing(2)
        self.d = QComboBox(); self.d.addItems(["روز"] + [f"{i:02d}" for i in range(1, 32)])
        self.m = QComboBox(); self.m.addItems(["ماه"] + [f"{i:02d}" for i in range(1, 13)])
        self.y = QLineEdit(); self.y.setPlaceholderText("سال (مثل 1405)"); self.y.setFixedWidth(80)
        layout.addWidget(self.d); layout.addWidget(self.m); layout.addWidget(self.y)
        self.d.currentIndexChanged.connect(self.changed.emit)
        self.m.currentIndexChanged.connect(self.changed.emit)
        self.y.textChanged.connect(self.changed.emit)
        
    def text(self):
        d = self.d.currentText(); m = self.m.currentText(); y = en_digits(self.y.text())
        if d != "روز" and m != "ماه" and len(y) == 4: return f"{y}/{m}/{d}"
        if m != "ماه" and len(y) == 4: return f"{y}/{m}"
        if len(y) == 4: return y
        return ""

class CopyableTableWidget(QTableWidget):
    def __init__(self, editable=False, *args, **kwargs):
        super().__init__(*args, **kwargs)
        if not editable: self.setEditTriggers(QTableWidget.NoEditTriggers)
        self.setContextMenuPolicy(Qt.CustomContextMenu)
        self.customContextMenuRequested.connect(self.show_context_menu)
        self.setLayoutDirection(Qt.RightToLeft)

    def show_context_menu(self, pos):
        menu = QMenu(self)
        copy_action = menu.addAction("کپی سلول‌های انتخابی (Ctrl+C)")
        action = menu.exec_(self.mapToGlobal(pos))
        if action == copy_action: self.copy_selection()

    def copy_selection(self):
        selection = self.selectedIndexes()
        if not selection: return
        rows = sorted(list(set(index.row() for index in selection)))
        columns = sorted(list(set(index.column() for index in selection)))
        table_str = ""
        for r in rows:
            row_data = [self.item(r, c).text() if self.item(r, c) else "" for c in columns]
            table_str += "\t".join(row_data) + "\n"
        QApplication.clipboard().setText(table_str.strip())

    def keyPressEvent(self, event):
        if event.modifiers() == Qt.ControlModifier and event.key() == Qt.Key_C: self.copy_selection()
        else: super().keyPressEvent(event)

class PaymentDialog(QDialog):
    def __init__(self, installments_data, target="us", company_name="مشتریان", parent=None):
        super().__init__(parent)
        self.target = target; self.company_name = company_name; self.installments_data = installments_data 
        self.setWindowTitle(f"ثبت پرداختی - " + ("کارگزاری (ما)" if target == "us" else "بیمه پاسارگاد"))
        self.resize(1000, 550); self.selected_files = []
        self.setLayoutDirection(Qt.RightToLeft)
        self.build()

    def build(self):
        layout = QVBoxLayout(self)
        self.table = CopyableTableWidget(editable=False)
        self.table.setColumnCount(8)
        self.table.setHorizontalHeaderLabels(["انتخاب", "بیمه‌گذار", "شماره بیمه", "قسط", "تاریخ صدور", "مبلغ کل قسط", "مانده", "پرداختی شما"])
        layout.addWidget(self.table)
        
        self.table.setRowCount(len(self.installments_data))
        self.rows_data = []
        
        for i, (inst, pol) in enumerate(self.installments_data):
            paid = (inst.paid_amount_us or 0) if self.target == "us" else (inst.paid_amount_pasargad or 0)
            remain = max(0.0, inst.amount - paid)
            
            chk = QCheckBox(); chk.setChecked(True); chk.stateChanged.connect(self.update_total)
            chk_w = QWidget(); l = QHBoxLayout(chk_w); l.addWidget(chk); l.setAlignment(Qt.AlignCenter); l.setContentsMargins(0,0,0,0)
            self.table.setCellWidget(i, 0, chk_w)
            
            vals = [pol.insured_name, pol.policy_number, str(inst.installment_number), pol.issue_date, format_number(inst.amount), format_number(remain)]
            for col, val in enumerate(vals, start=1): self.table.setItem(i, col, QTableWidgetItem(to_persian_digits(val)))
            
            pay_input = QLineEdit(to_persian_digits(f"{int(remain):,}"))
            pay_input.textChanged.connect(lambda t, le=pay_input: [format_input_live(t, le), self.update_total()])
            self.rows_data.append((inst.id, chk, pay_input, inst, pol))
            self.table.setCellWidget(i, 7, pay_input)
            
        align_table_items(self.table); self.table.resizeColumnsToContents()
        self.total_lbl = QLabel(); self.total_lbl.setStyleSheet("font-weight:bold; font-size:14px; color:#4caf50;")
        self.update_total(); layout.addWidget(self.total_lbl)

        form_layout = QHBoxLayout()
        self.method_cb = QComboBox(); self.method_cb.addItems(["نقد/کارت", "چک", "فیش بانکی", "تهاتر/سایر"])
        self.notes_input = QLineEdit(); self.notes_input.setPlaceholderText("توضیحات (اختیاری)")
        self.files_lbl = QLabel("عکسی انتخاب نشده")
        self.files_btn = QPushButton("انتخاب فیش / عکس"); self.files_btn.clicked.connect(self.select_files)
        form_layout.addWidget(QLabel("روش:")); form_layout.addWidget(self.method_cb)
        form_layout.addWidget(self.notes_input); form_layout.addWidget(self.files_btn); form_layout.addWidget(self.files_lbl)
        layout.addLayout(form_layout)
        
        btn_box = QHBoxLayout()
        save_btn = QPushButton("ثبت نهایی پرداختی"); save_btn.clicked.connect(self.accept)
        cancel_btn = QPushButton("انصراف"); cancel_btn.clicked.connect(self.reject)
        btn_box.addWidget(save_btn); btn_box.addWidget(cancel_btn)
        layout.addLayout(btn_box)

    def select_files(self):
        files, _ = QFileDialog.getOpenFileNames(self, "انتخاب فیش", "", "Images/PDF (*.png *.jpg *.jpeg *.pdf)")
        if files: self.selected_files = files; self.files_lbl.setText(to_persian_digits(f"{len(files)} فایل انتخاب شد"))

    def update_total(self):
        total = 0
        for inst_id, chk, inp, _, _ in self.rows_data:
            if chk.isChecked():
                inp.setEnabled(True)
                try: 
                    clean_val = re.sub(r'[^\d]', '', en_digits(inp.text()))
                    total += float(clean_val) if clean_val else 0.0
                except: pass
            else: inp.setEnabled(False)
        self.total_lbl.setText(f"جمع کل پرداختی انتخاب شده: {format_number(total)}")

    def get_payment_data(self):
        data = []
        for inst_id, chk, inp, inst, pol in self.rows_data:
            if chk.isChecked():
                try:
                    clean_val = re.sub(r'[^\d]', '', en_digits(inp.text()))
                    val = float(clean_val) if clean_val else 0.0
                    if val > 0: 
                        data.append((int(inst_id), val))
                except: pass
        
        period_name = None
        if self.installments_data:
            _, first_pol = self.installments_data[0]
            period_name = first_pol.period_name or "نامشخص"
        
        files_str = ExcelEngine.save_receipt_files(
            self.selected_files,
            company_name=self.company_name,
            period_name=period_name,
            target=self.target
        )
        return data, self.method_cb.currentText(), self.notes_input.text(), files_str

class DashboardPage(QWidget):
    def __init__(self, main_window):
        super().__init__()
        self.main_window = main_window
        self.main_layout = QVBoxLayout(self)
        self.filter_cb = QComboBox()
        self.filter_cb.addItems(["همه دوره‌ها", "سال جاری", "دوره اخیر"])
        self.filter_cb.currentIndexChanged.connect(self.refresh)
        
        self.alert_type_cb = QComboBox()
        self.alert_type_cb.addItems(["اقساط شرکت‌ها (گروهی)", "اقساط پرسنل (تکی)"])
        self.alert_type_cb.currentIndexChanged.connect(self.refresh)
        
        self.fig = Figure(figsize=(5, 2.5)); self.canvas = FigureCanvasQTAgg(self.fig)
        self.canvas.setStyleSheet("background-color: transparent;")
        self.build_static_ui()
        self.refresh()

    def card(self, title, value):
        f = QFrame(); f.setFrameShape(QFrame.StyledPanel); l = QVBoxLayout(f)
        t = QLabel(title); t.setAlignment(Qt.AlignCenter)
        v = QLabel(str(value)); v.setAlignment(Qt.AlignCenter); v.setStyleSheet("font-size:28px; font-weight:bold;")
        l.addWidget(t); l.addWidget(v); return f

    def build_static_ui(self):
        top_lay = QHBoxLayout()
        left_stats = QVBoxLayout()
        header = QHBoxLayout()
        header.addWidget(QLabel("نمایش آمار بر اساس:")); header.addWidget(self.filter_cb); header.addStretch()
        left_stats.addLayout(header)
        
        self.stats_grid = QGridLayout()
        left_stats.addLayout(self.stats_grid)
        top_lay.addLayout(left_stats, 1)

        chart_lay = QVBoxLayout()
        lbl = QLabel("روند فروش دوره‌های مالی:"); lbl.setStyleSheet("font-weight:bold;")
        chart_lay.addWidget(lbl)

        self.chart_card = QFrame()
        self.chart_card.setObjectName("chartCard")
        chart_card_lay = QVBoxLayout(self.chart_card)
        chart_card_lay.setContentsMargins(14, 14, 14, 14)
        chart_card_lay.addWidget(self.canvas)
        chart_lay.addWidget(self.chart_card)

        top_lay.addLayout(chart_lay, 2)
        
        self.main_layout.addLayout(top_lay, 1)

        al_header = QHBoxLayout()
        alert_lbl = QLabel("هشدارها (دوبار کلیک روی ردیف برای انتقال):")
        alert_lbl.setStyleSheet("font-weight:bold; margin-top: 10px;")
        al_header.addWidget(alert_lbl); al_header.addStretch(); al_header.addWidget(QLabel("نوع نمایش:")); al_header.addWidget(self.alert_type_cb)
        
        self.main_layout.addLayout(al_header)
        self.alerts_tabs = QTabWidget()
        self.main_layout.addWidget(self.alerts_tabs, 2)

    def refresh(self):
        data = DashboardManager.get_summary(self.filter_cb.currentText())
        clear_layout(self.stats_grid)
        self.stats_grid.addWidget(self.card("شرکت‌ها", format_number(data["companies"])), 0, 0)
        self.stats_grid.addWidget(self.card("بیمه‌ها", format_number(data["policies"])), 0, 1)
        self.stats_grid.addWidget(self.card("مبلغ کل", format_number(data["premium"])), 1, 0)
        self.stats_grid.addWidget(self.card("تعداد اقساط", format_number(data["installments"])), 1, 1)

        self.fig.clear()
        is_dark = load_settings().get("dark_mode", True)
        card_bg = "#242424" if is_dark else "#ffffff"
        border_col = "#3d3d3d" if is_dark else "#e2e8f0"
        text_col = "#d1d5db" if is_dark else "#334155"
        grid_col = "#3a3a3a" if is_dark else "#e5e7eb"
        line_col = "#4caf50"

        self.chart_card.setStyleSheet(
            f"QFrame#chartCard {{ background-color: {card_bg}; border-radius: 14px; border: 1px solid {border_col}; }}"
        )
        self.fig.patch.set_facecolor(card_bg)
        ax = self.fig.add_subplot(111)
        ax.set_facecolor(card_bg)
        plt.rcParams['font.family'] = load_settings().get("font_family", "Vazir")
        
        plot_data = data.get("chart_data", [])
        if plot_data:
            periods = [x[0] for x in plot_data]; amounts = [x[1] for x in plot_data]
            x_idx = range(len(periods))
            ax.plot(x_idx, amounts, marker='o', linestyle='-', markersize=7, color=line_col, linewidth=2.5,
                    markerfacecolor=card_bg, markeredgecolor=line_col, markeredgewidth=2, zorder=3)
            ax.fill_between(x_idx, amounts, color=line_col, alpha=0.15, zorder=2)
            ax.set_xticks(list(x_idx)); ax.set_xticklabels(periods)
            def y_fmt(x, pos): return format_number(x)
            ax.yaxis.set_major_formatter(FuncFormatter(y_fmt))
            ax.grid(True, axis='y', linestyle='--', linewidth=0.7, color=grid_col, alpha=0.7, zorder=0)
            for spine in ax.spines.values(): spine.set_visible(False)
            ax.tick_params(colors=text_col, labelsize=9)
            for label in ax.get_xticklabels() + ax.get_yticklabels(): label.set_color(text_col)
        else:
            for spine in ax.spines.values(): spine.set_visible(False)
            ax.set_xticks([]); ax.set_yticks([])
        self.fig.tight_layout(); self.canvas.draw()

        self.alerts_tabs.clear()
        alerts = DashboardManager.get_alerts()
        is_company = self.alert_type_cb.currentIndex() == 0
        
        def create_personnel_table(alert_data):
            table = CopyableTableWidget(editable=False)
            table.setColumnCount(8)
            table.setHorizontalHeaderLabels(["بیمه‌گذار", "شماره بیمه", "قسط", "شناسه قسط", "مبلغ قسط", "مانده", "وضعیت", "سررسید"])
            table.setRowCount(len(alert_data))
            for i, (inst, pol) in enumerate(alert_data):
                rem = inst.amount - (inst.paid_amount_us or 0)
                stat_txt = "مانده دار" if (inst.paid_amount_us or 0) > 0 else "پرداخت نشده"
                vals = [pol.insured_name, pol.policy_number, str(inst.installment_number), inst.tracking_id, format_number(inst.amount), format_number(rem), stat_txt, inst.due_date]
                for col, val in enumerate(vals): table.setItem(i, col, QTableWidgetItem(to_persian_digits(val)))
            align_table_items(table); table.resizeColumnsToContents()
            table.cellDoubleClicked.connect(lambda r, c: self.main_window.navigate_to_installment(table.item(r, 3).text()))
            return table

        def create_company_table(alert_data):
            grouped = {}
            for inst, pol in alert_data:
                comp = pol.contract_company or "نامشخص"
                key = (comp, pol.period_name or "نامشخص", inst.installment_number)
                if key not in grouped: grouped[key] = {"count": 0, "amount": 0, "paid": 0, "due": inst.due_date}
                grouped[key]["count"] += 1; grouped[key]["amount"] += inst.amount; grouped[key]["paid"] += (inst.paid_amount_us or 0)
            
            table = CopyableTableWidget(editable=False)
            table.setColumnCount(8)
            table.setHorizontalHeaderLabels(["شرکت/سازمان", "دوره مالی", "شماره قسط", "تعداد بیمه", "مبلغ کل", "مانده", "وضعیت", "سررسید"])
            table.setRowCount(len(grouped))
            for i, (key, info) in enumerate(sorted(grouped.items(), key=lambda x: en_digits(x[1]["due"]) if x[1]["due"] else "")):
                comp, per, num = key
                rem = info["amount"] - info["paid"]
                stat_txt = "مانده دار" if info["paid"] > 0 else "پرداخت نشده"
                vals = [comp, per, str(num), str(info["count"]), format_number(info["amount"]), format_number(rem), stat_txt, info["due"]]
                for col, val in enumerate(vals): table.setItem(i, col, QTableWidgetItem(to_persian_digits(val)))
            align_table_items(table); table.resizeColumnsToContents()

            def on_company_dblclick(r, c, tbl=table):
                comp_item = tbl.item(r, 0)
                per_item = tbl.item(r, 1)
                num_item = tbl.item(r, 2)
                if not (comp_item and per_item and num_item):
                    return
                self.main_window.navigate_company_to_group(
                    comp_item.text().strip(),
                    en_digits(per_item.text()).strip(),
                    en_digits(num_item.text()).strip()
                )

            table.cellDoubleClicked.connect(on_company_dblclick)
            return table

        builder = create_company_table if is_company else create_personnel_table
        self.alerts_tabs.addTab(builder(alerts["upcoming"]), "نزدیک سررسید (۱ ماهه)")
        self.alerts_tabs.addTab(builder(alerts["overdue"]), "سررسید گذشته")
        self.alerts_tabs.addTab(builder(alerts["unpaid"]), "کل مانده دارها")

class ImportPage(QWidget):
    def __init__(self):
        super().__init__()
        self.file = None; self.preview_result = None; self.engine = None
        self.build()

    def build(self):
        layout = QVBoxLayout(self)
        title = QLabel("ورود اطلاعات Excel")
        title.setStyleSheet("font-size:22px; font-weight:bold;")
        layout.addWidget(title)

        row_tools = QHBoxLayout()
        self.file_btn = QPushButton("انتخاب فایل Excel"); self.file_btn.clicked.connect(self.select_file)
        row_tools.addWidget(self.file_btn)
        
        row_tools.addWidget(QLabel("تعداد اقساط (۰ = نقد):"))
        self.installments = QSpinBox(); self.installments.setRange(0, 36)
        self.installments.setMinimumWidth(80); self.installments.setMaximumWidth(120); self.installments.setAlignment(Qt.AlignCenter)
        self.installments.setValue(load_settings().get("default_installments", 9))
        row_tools.addWidget(self.installments)

        self.normal = QRadioButton("تقسیم بر کل"); self.mamoot = QRadioButton("تقسیم ماموتی"); self.mamoot.setChecked(True)
        row_tools.addWidget(self.normal); row_tools.addWidget(self.mamoot)

        self.preview_btn = QPushButton("پیش‌نمایش"); self.preview_btn.clicked.connect(self.preview)
        row_tools.addWidget(self.preview_btn)

        self.add_row_btn = QPushButton("➕ افزودن ردیف"); self.add_row_btn.clicked.connect(self.add_table_row)
        self.del_row_btn = QPushButton("❌ حذف ردیف"); self.del_row_btn.clicked.connect(self.delete_table_row)
        row_tools.addWidget(self.add_row_btn); row_tools.addWidget(self.del_row_btn)

        self.commit_btn = QPushButton("ثبت نهایی"); self.commit_btn.clicked.connect(self.commit); self.commit_btn.setEnabled(False)
        row_tools.addWidget(self.commit_btn)
        layout.addLayout(row_tools)

        self.table = CopyableTableWidget(editable=True)
        self.table.setColumnCount(12)
        self.table.setHorizontalHeaderLabels([
            "ردیف", "نام بیمه گذار", "شماره بیمه نامه", "طرف قرارداد", "حق بیمه", 
            "نوع بیمه", "شماره انتظامی", "کد ملی", "کد پرسنلی", "تاریخ صدور", "دوره مالی", "وضعیت"
        ])
        layout.addWidget(self.table)

    def add_table_row(self):
        row_idx = self.table.rowCount()
        self.table.insertRow(row_idx)
        today_s = jdatetime.date.today().strftime('%Y/%m/%d')
        defaults = [to_persian_digits(row_idx + 1), "", "", "", "0", "ثالث", "", "", "", today_s, "", "تایید شده"]
        for col, val in enumerate(defaults):
            item = QTableWidgetItem(to_persian_digits(val))
            self.table.setItem(row_idx, col, item)
        align_table_items(self.table)
        self.commit_btn.setEnabled(True)

    def delete_table_row(self):
        selected_rows = sorted(list(set(index.row() for index in self.table.selectedIndexes())), reverse=True)
        if not selected_rows:
            if self.table.rowCount() > 0:
                self.table.removeRow(self.table.rowCount() - 1)
        else:
            for r in selected_rows:
                self.table.removeRow(r)
        for r in range(self.table.rowCount()):
            self.table.setItem(r, 0, QTableWidgetItem(to_persian_digits(r + 1)))
        align_table_items(self.table)
        if self.table.rowCount() == 0:
            self.commit_btn.setEnabled(False)

    def select_file(self):
        file, _ = QFileDialog.getOpenFileName(self, "انتخاب Excel", "", "Excel (*.xlsx)")
        if file: self.file = file; self.file_btn.setText(os.path.basename(file))

    def preview(self):
        if not self.file: return QMessageBox.warning(self, "خطا", "ابتدا فایل را انتخاب کنید")
        settings = load_settings()
        self.engine = ExcelEngine(self.installments.value(), "normal" if self.normal.isChecked() else "mamoot", settings.get("target_contracts", []))
        self.preview_result = self.engine.preview(self.file, settings.get("period_start_day", 25))
        
        self.table.setRowCount(len(self.preview_result["rows"]))
        for i, item in enumerate(self.preview_result["rows"]):
            c = item["clean_data"]
            vals = [
                i + 1, c["insured_name"], c["policy_number"], c["contract_company"], 
                format_number(c["premium_amount"]), c["policy_type"], format_plate_for_display(c["plate"]), 
                c["national_code"], c.get("personnel_code", ""), c["issue_date"], c["period_name"], "تایید شده"
            ]
            for col, val in enumerate(vals): 
                self.table.setItem(i, col, QTableWidgetItem(to_persian_digits(val)))
        align_table_items(self.table); self.table.resizeColumnsToContents()
        self.commit_btn.setEnabled(True)

    def commit(self):
        approved_data = []
        for i in range(self.table.rowCount()):
            status_item = self.table.item(i, 11)
            if status_item and "تایید شده" in status_item.text():
                row_dict = {}
                row_dict["insured_name"] = self.table.item(i, 1).text() if self.table.item(i, 1) else ""
                row_dict["policy_number"] = en_digits(self.table.item(i, 2).text()) if self.table.item(i, 2) else ""
                row_dict["contract_company"] = self.table.item(i, 3).text() if self.table.item(i, 3) else ""
                
                try:
                    prem_clean = en_digits(self.table.item(i, 4).text().replace(',', '').replace('،', '')) if self.table.item(i, 4) else "0"
                    row_dict["premium_amount"] = float(prem_clean)
                except Exception:
                    row_dict["premium_amount"] = 0.0

                row_dict["policy_type"] = self.table.item(i, 5).text() if self.table.item(i, 5) else "ثالث"
                
                plate_raw = self.table.item(i, 6).text() if self.table.item(i, 6) else ""
                for m in _BIDI_CTRL: plate_raw = plate_raw.replace(m, '')
                row_dict["plate"] = plate_raw.replace('ناریا', 'ایران')

                row_dict["national_code"] = en_digits(self.table.item(i, 7).text()) if self.table.item(i, 7) else ""
                row_dict["personnel_code"] = en_digits(self.table.item(i, 8).text()) if self.table.item(i, 8) else ""
                row_dict["issue_date"] = en_digits(self.table.item(i, 9).text()) if self.table.item(i, 9) else ""
                row_dict["period_name"] = en_digits(self.table.item(i, 10).text()) if self.table.item(i, 10) else ""
                row_dict["contract_number"] = ""
                approved_data.append(row_dict)
                
        if not approved_data: return QMessageBox.warning(self, "خطا", "داده تایید شده ای برای ثبت وجود ندارد.")
        try:
            if not self.engine:
                settings = load_settings()
                self.engine = ExcelEngine(self.installments.value(), "normal" if self.normal.isChecked() else "mamoot", settings.get("target_contracts", []))
            
            res = self.engine.commit_data(approved_data, filename=os.path.basename(self.file) if self.file else "Manual_Entry.xlsx")
        except Exception as e:
            return QMessageBox.critical(self, "خطا در ثبت", f"خطا هنگام ثبت داده‌ها:\n{str(e)}")

        base_msg = f"ثبت شده: {to_persian_digits(res['created'])}\nکد: Batch_{res['batch_id']}\nبایگانی انجام شد."
        sync_err = res.get("sync_error")
        if sync_err:
            QMessageBox.warning(self, "ثبت شد (با هشدار اکسل)", base_msg + f"\n\n⚠️ خطا در به‌روزرسانی اکسل جامع:\n{sync_err}")
        else:
            QMessageBox.information(self, "ثبت انجام شد", base_msg)

        self.file = None; self.file_btn.setText("انتخاب فایل Excel")
        self.table.setRowCount(0); self.commit_btn.setEnabled(False); self.preview_result = None

class PersonnelTableWidget(CopyableTableWidget):
    def __init__(self, personnel_page, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.personnel_page = personnel_page

    def show_context_menu(self, pos):
        menu = QMenu(self)
        copy_action = menu.addAction("کپی سلول‌های انتخابی (Ctrl+C)")
        menu.addSeparator()
        folder_action = menu.addAction("📂 رفتن به پوشه")
        pay_action = menu.addAction("💳 رفتن به پرداخت در اقساط شرکت‌ها")
        
        action = menu.exec_(self.mapToGlobal(pos))
        if action == copy_action: self.copy_selection()
        elif action == folder_action:
            row = self.currentRow()
            if row >= 0:
                current_tab = "us" if self.personnel_page.tabs.currentIndex() == 0 else ("pasargad" if self.personnel_page.tabs.currentIndex() == 1 else "settled")
                self.personnel_page.open_folder(current_tab, row)
        elif action == pay_action:
            row = self.currentRow()
            if row >= 0: self.personnel_page.navigate_to_company_row(row)

class InstallmentsPersonnelPage(QWidget):
    def __init__(self, main_window=None):
        super().__init__()
        self.main_window = main_window
        self.tabs_ui = {}
        self.build()

    def build(self):
        layout = QVBoxLayout(self)
        self.tabs = QTabWidget()
        self.tab_us = QWidget(); self.tab_pasargad = QWidget(); self.tab_settled = QWidget()
        
        self.tabs.addTab(self.tab_us, "واریز به کارگزاری (بدهکار به ما)")
        self.tabs.addTab(self.tab_pasargad, "پرداختی به پاسارگاد (بدهکار به پاسارگاد)")
        self.tabs.addTab(self.tab_settled, "تسویه نهایی")
        
        self.build_tab(self.tab_us, "us")
        self.build_tab(self.tab_pasargad, "pasargad")
        self.build_tab_settled(self.tab_settled)
        layout.addWidget(self.tabs)
        
    def build_tab(self, tab, target):
        layout = QVBoxLayout(tab)
        f_lay = QHBoxLayout()
        search_inp = QLineEdit(); search_inp.setPlaceholderText("جستجو در همه ستون‌ها...")
        d_from = DateInputWidget(); d_to = DateInputWidget()
        status_cb = QComboBox()
        status_cb.addItems(["همه پرداخت‌ها", "پرداخت نشده", "مانده دار", "پرداخت شده", "نزدیک‌ترین سررسیدها"])
        inv_status_cb = QComboBox(); inv_status_cb.addItems(["همه صورتحساب‌ها", "بدون صورتحساب", "دارای صورتحساب"])
        f_lay.addWidget(search_inp); f_lay.addWidget(QLabel("از سررسید:")); f_lay.addWidget(d_from)
        f_lay.addWidget(QLabel("تا سررسید:")); f_lay.addWidget(d_to); f_lay.addWidget(status_cb); f_lay.addWidget(inv_status_cb)
        layout.addLayout(f_lay)
        
        t_lay = QHBoxLayout()
        refresh = QPushButton("بروزرسانی لیست"); refresh.clicked.connect(self.load_data)
        info_lbl = QLabel("💡 راهنما: جهت ثبت پرداخت، روی ردیف قسط کلیک راست کرده و «رفتن به پرداخت در اقساط شرکت‌ها» را انتخاب نمایید.")
        info_lbl.setStyleSheet("color: #64748b; font-size: 11px;")
        t_lay.addWidget(refresh); t_lay.addWidget(info_lbl); t_lay.addStretch()
        layout.addLayout(t_lay)
        
        table = PersonnelTableWidget(self, editable=False)
        table.setColumnCount(19)
        table.setHorizontalHeaderLabels(["شناسه ۱۰ رقمی", "بیمه‌گذار", "کد ملی", "کد پرسنلی", "پلاک", "شماره بیمه", "نوع بیمه", "قرارداد", "شرکت", "دوره مالی", "قسط", "تاریخ صدور", "مبلغ کل", "پرداخت شده", "مانده", "سررسید", "تاخیر(روز)", "وضعیت", "وضعیت صورتحساب"])
        table.setSelectionBehavior(QTableWidget.SelectRows); table.setSelectionMode(QTableWidget.ExtendedSelection)
        table.cellDoubleClicked.connect(lambda r, c: self.open_folder(target, r))
        layout.addWidget(table)
        
        self.tabs_ui[target] = {"search": search_inp, "from": d_from, "to": d_to, "status": status_cb, "inv_status": inv_status_cb, "table": table}
        filter_func = lambda: self.apply_filters(target)
        search_inp.textChanged.connect(filter_func); d_from.changed.connect(filter_func); d_to.changed.connect(filter_func); status_cb.currentIndexChanged.connect(filter_func); inv_status_cb.currentIndexChanged.connect(filter_func)
        
    def build_tab_settled(self, tab):
        layout = QVBoxLayout(tab)
        t_lay = QHBoxLayout()
        search_inp = QLineEdit(); search_inp.setPlaceholderText("جستجو...")
        refresh = QPushButton("بروزرسانی لیست"); refresh.clicked.connect(self.load_data)
        t_lay.addWidget(search_inp); t_lay.addWidget(refresh); t_lay.addStretch()
        layout.addLayout(t_lay)
        
        self.settled_table = PersonnelTableWidget(self, editable=False)
        self.settled_table.setColumnCount(17)
        self.settled_table.setHorizontalHeaderLabels(["شناسه ۱۰ رقمی", "بیمه‌گذار", "کد ملی", "کد پرسنلی", "پلاک", "شماره بیمه", "قرارداد", "شرکت", "دوره مالی", "قسط", "تاریخ صدور", "مبلغ کل", "مبلغ تسویه شده", "سررسید", "وضعیت", "صورتحساب", "شناسه"])
        self.settled_table.hideColumn(16)
        self.settled_table.cellDoubleClicked.connect(lambda r, c: self.open_folder("settled", r))
        
        self.tabs_ui["settled"] = {"search": search_inp, "table": self.settled_table}
        search_inp.textChanged.connect(lambda: self.apply_filters("settled"))
        layout.addWidget(self.settled_table)

    def navigate_to_company_row(self, row):
        current_tab_idx = self.tabs.currentIndex()
        if current_tab_idx == 2:
            QMessageBox.information(self, "توجه", "این قسط تسویه شده است.")
            return

        active_table = self.tabs_ui["us"]["table"] if current_tab_idx == 0 else self.tabs_ui["pasargad"]["table"]
        comp_item = active_table.item(row, 8)
        per_item = active_table.item(row, 9)
        num_item = active_table.item(row, 10)
        if not (comp_item and per_item and num_item): return

        comp = comp_item.text().strip()
        per = en_digits(per_item.text()).strip()
        try: inst_num = int(en_digits(num_item.text()).strip())
        except ValueError: return

        if not (self.main_window and hasattr(self.main_window, 'inst_company_btn')): return

        self.main_window.pages.setCurrentWidget(self.main_window.inst_comp_page)
        comp_page = self.main_window.inst_comp_page
        target_key = "us" if current_tab_idx == 0 else "pasargad"
        comp_page.tabs.setCurrentIndex(current_tab_idx)

        ui = comp_page.tabs_ui[target_key]
        ui["search"].blockSignals(True); ui["search"].clear(); ui["search"].blockSignals(False)
        for key in ("from", "to"):
            w = ui[key]
            w.blockSignals(True)
            w.d.setCurrentIndex(0); w.m.setCurrentIndex(0); w.y.clear()
            w.blockSignals(False)
        ui["status"].blockSignals(True); ui["status"].setCurrentIndex(0); ui["status"].blockSignals(False)
        ui["inv_status"].blockSignals(True); ui["inv_status"].setCurrentIndex(0); ui["inv_status"].blockSignals(False)

        comp_page.load_data()
        comp_page.apply_filters(target_key)

        comp_table = ui["table"]
        comp_norm = comp.replace('\u200c', '').strip()
        found_row = -1
        for r in range(comp_table.rowCount()):
            c_item = comp_table.item(r, 0)
            p_item = comp_table.item(r, 1)
            n_item = comp_table.item(r, 2)
            if not (c_item and p_item and n_item): continue
            row_comp = c_item.text().replace('\u200c', '').strip()
            row_per = en_digits(p_item.text()).replace('\u200c', '').strip()
            try: row_num = int(en_digits(n_item.text()).strip())
            except ValueError: continue
            if row_comp == comp_norm and row_per == per and row_num == inst_num:
                found_row = r
                break

        if found_row < 0:
            QMessageBox.warning(self, "یافت نشد", f"ردیف برای شرکت «{comp}» در دوره «{per}» قسط «{to_persian_digits(inst_num)}» یافت نشد.")
            return

        comp_table.clearSelection()
        comp_table.selectRow(found_row)
        comp_table.scrollToItem(comp_table.item(found_row, 0), QTableWidget.PositionAtCenter)
        comp_table.setFocus()
        QTimer.singleShot(200, lambda: comp_page.process_group_payment(target_key))

    def apply_filters(self, target):
        ui = self.tabs_ui[target]
        table = ui["table"]
        search_txt = ui["search"].text().lower()
        d_from = ui.get("from").text() if "from" in ui else ""
        d_to = ui.get("to").text() if "to" in ui else ""
        status = ui.get("status").currentText() if "status" in ui else "همه پرداخت‌ها"
        inv_status = ui.get("inv_status").currentText() if "inv_status" in ui else "همه صورتحساب‌ها"
        
        today_str = jdatetime.date.today().strftime('%Y/%m/%d')
        near_str = (jdatetime.date.today() + jdatetime.timedelta(days=30)).strftime('%Y/%m/%d')
        due_col = 15 if target != "settled" else 13
        stat_col = 17 if target != "settled" else 14
        inv_col = 18 if target != "settled" else 15
        
        for row in range(table.rowCount()):
            show = True
            if search_txt:
                match = False
                for col in range(table.columnCount()):
                    it = table.item(row, col)
                    if it and search_txt in it.text().lower(): match = True; break
                if not match: show = False
            if show and target != "settled" and status != "همه پرداخت‌ها" and status != "نزدیک‌ترین سررسیدها":
                it = table.item(row, stat_col)
                if it:
                    cell_txt = it.text()
                    if status == "پرداخت شده":
                        if "پرداخت شده" not in cell_txt: show = False
                    elif status == "مانده دار":
                        if "مانده دار" not in cell_txt: show = False
                    elif status == "پرداخت نشده":
                        if "پرداخت نشده" not in cell_txt and "آماده پرداخت" not in cell_txt: show = False
                    else:
                        if cell_txt != status: show = False
            if show and inv_status != "همه صورتحساب‌ها":
                it = table.item(row, inv_col)
                if it and it.text() != inv_status: show = False
            due_it = table.item(row, due_col)
            due_val = en_digits(due_it.text()) if due_it else ""
            if show and target != "settled" and status == "نزدیک‌ترین سررسیدها":
                if not due_val or not (today_str <= due_val <= near_str): show = False
            if show and d_from and due_val < d_from: show = False
            if show and d_to and due_val > d_to: show = False
            table.setRowHidden(row, not show)

    def open_folder(self, target, row):
        table = self.tabs_ui[target]["table"]
        col_comp = 8 if target != "settled" else 7
        col_per = 9 if target != "settled" else 8
        comp = table.item(row, col_comp).text()
        per = en_digits(table.item(row, col_per).text())
        year = per.split()[1] if " " in per else "نامشخص"
        comp_safe = "".join(c for c in comp if c.isalnum() or c in (' ', '-', '_')).strip()
        path = os.path.join(DATA_ROOT, f"بایگانی سالانه/{year}/{per}/{comp_safe}")
        os.makedirs(path, exist_ok=True)
        if platform.system() == "Windows": os.startfile(os.path.normpath(path))
        else: QMessageBox.information(self, "خطا", "پلتفرم ویندوز نیست.")

    def load_data(self):
        data = InstallmentManager.get_all_with_policies()
        us_rows = []; pas_rows = []; set_rows = []
        for inst, pol in data:
            paid_us = inst.paid_amount_us or 0.0
            paid_pas = inst.paid_amount_pasargad or 0.0
            
            if paid_pas >= inst.amount - 0.5:
                set_rows.append((inst, pol))
            else:
                if paid_us >= inst.amount - 0.5:
                    pas_rows.append((inst, pol))
                else:
                    us_rows.append((inst, pol))

        self.populate_table(self.tabs_ui["us"]["table"], us_rows, "us")
        self.populate_table(self.tabs_ui["pasargad"]["table"], pas_rows, "pasargad")
        self.populate_settled(self.tabs_ui["settled"]["table"], set_rows)

    def populate_table(self, table, rows_data, target):
        table.setRowCount(len(rows_data))
        for i, (inst, pol) in enumerate(rows_data):
            paid = (inst.paid_amount_us or 0.0) if target == "us" else (inst.paid_amount_pasargad or 0.0)
            remain = max(0.0, inst.amount - paid)
            
            if target == "us":
                stat = inst.status_us
                status_txt = "پرداخت شده" if stat == "paid" else ("مانده دار" if stat == "partial" else "پرداخت نشده")
            else:
                stat = inst.status_pasargad
                status_txt = "پرداخت شده به پاسارگاد" if stat == "paid" else ("مانده دار پاسارگاد" if stat == "partial" else "آماده پرداخت به پاسارگاد")
            
            inv_txt = "دارای صورتحساب" if pol.is_invoiced else "بدون صورتحساب"
            
            delay = 0
            if inst.due_date and ((target == "us" and inst.status_us != "paid") or (target == "pasargad" and inst.status_pasargad != "paid")):
                try:
                    delay = (jdatetime.date.today() - jdatetime.datetime.strptime(inst.due_date, '%Y/%m/%d').date()).days
                except: pass
            
            vals = [inst.tracking_id, pol.insured_name, pol.national_code, pol.personnel_code or "",
                    format_plate_for_display(pol.plate),
                    pol.policy_number, pol.policy_type, pol.contract_number,
                    pol.contract_company or "نامشخص", pol.period_name or "نامشخص", 
                    str(inst.installment_number), pol.issue_date, format_number(inst.amount), format_number(paid), 
                    format_number(remain), inst.due_date, format_number(delay) if delay > 0 else "0", status_txt, inv_txt]
            
            status_color = get_status_color(status_txt)
            for col, val in enumerate(vals):
                item = QTableWidgetItem(to_persian_digits(val))
                if col == 17 and status_color:
                    item.setBackground(status_color)
                table.setItem(i, col, item)
            
            table.item(i, 0).setData(Qt.UserRole, inst.id) 
        align_table_items(table); table.resizeColumnsToContents()
        
    def populate_settled(self, table, rows_data):
        table.setRowCount(len(rows_data))
        for i, (inst, pol) in enumerate(rows_data):
            inv_txt = "دارای صورتحساب" if pol.is_invoiced else "بدون صورتحساب"
            vals = [inst.tracking_id, pol.insured_name, pol.national_code, pol.personnel_code or "",
                    format_plate_for_display(pol.plate),
                    pol.policy_number, pol.contract_number, pol.contract_company or "نامشخص",
                    pol.period_name or "نامشخص", str(inst.installment_number), pol.issue_date,
                    format_number(inst.amount), format_number(inst.amount), inst.due_date,
                    "تسویه کامل", inv_txt, str(inst.id)]
            
            settled_color = get_status_color("تسویه کامل")
            for col, val in enumerate(vals):
                item = QTableWidgetItem(to_persian_digits(val))
                if col == 14 and settled_color:
                    item.setBackground(settled_color)
                table.setItem(i, col, item)
            table.item(i, 0).setData(Qt.UserRole, inst.id)
        align_table_items(table); table.resizeColumnsToContents()

class InstallmentsCompanyPage(QWidget):
    def __init__(self, main_window=None):
        super().__init__()
        self.main_window = main_window
        self.tabs_ui = {}
        self.build()

    def get_group_due_date(self, period_name, inst_number):
        months = ["فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"]
        try:
            m_name, y_str = period_name.split()
            m_idx = months.index(m_name) + 1; y = int(en_digits(y_str)); m_idx += inst_number
            while m_idx > 12: m_idx -= 12; y += 1
            return f"{y:04d}/{m_idx:02d}/15"
        except: return ""

    def build(self):
        layout = QVBoxLayout(self)
        self.tabs = QTabWidget()
        self.tab_us = QWidget(); self.tab_pasargad = QWidget(); self.tab_settled = QWidget()
        
        self.tabs.addTab(self.tab_us, "واریز به کارگزاری (بدهکار به ما)")
        self.tabs.addTab(self.tab_pasargad, "پرداختی به پاسارگاد (بدهکار به پاسارگاد)")
        self.tabs.addTab(self.tab_settled, "تسویه نهایی شرکت‌ها")
        
        self.build_tab(self.tab_us, "us", "ثبت پرداختی گروهی شرکت")
        self.build_tab(self.tab_pasargad, "pasargad", "ثبت پرداختی گروهی به پاسارگاد")
        self.build_tab_settled(self.tab_settled)
        layout.addWidget(self.tabs)
        
    def build_tab(self, tab, target, btn_text):
        layout = QVBoxLayout(tab)
        f_lay = QHBoxLayout()
        search_inp = QLineEdit(); search_inp.setPlaceholderText("جستجو در همه ستون‌ها...")
        d_from = DateInputWidget(); d_to = DateInputWidget()
        status_cb = QComboBox(); status_cb.addItems(["همه پرداخت‌ها", "پرداخت نشده", "مانده دار", "پرداخت شده", "نزدیک‌ترین سررسیدها"])
        inv_status_cb = QComboBox(); inv_status_cb.addItems(["همه صورتحساب‌ها", "بدون صورتحساب", "دارای صورتحساب"])
        f_lay.addWidget(search_inp); f_lay.addWidget(QLabel("از سررسید:")); f_lay.addWidget(d_from)
        f_lay.addWidget(QLabel("تا سررسید:")); f_lay.addWidget(d_to); f_lay.addWidget(status_cb); f_lay.addWidget(inv_status_cb)
        layout.addLayout(f_lay)
        
        t_lay = QHBoxLayout()
        refresh = QPushButton("بروزرسانی"); refresh.clicked.connect(self.load_data)
        pay_btn = QPushButton(btn_text); pay_btn.clicked.connect(lambda: self.process_group_payment(target))
        t_lay.addWidget(refresh); t_lay.addWidget(pay_btn); t_lay.addStretch()
        layout.addLayout(t_lay)
        
        table = CopyableTableWidget(editable=False)
        table.setColumnCount(10)
        table.setHorizontalHeaderLabels(["شرکت/سازمان", "دوره مالی", "شماره قسط", "تعداد بیمه", "سررسید شرکتی", "مبلغ کل قسط", "پرداخت شده", "مانده", "وضعیت پرداختی", "صورتحساب"])
        table.setSelectionBehavior(QTableWidget.SelectRows); table.setSelectionMode(QTableWidget.SingleSelection)
        table.cellDoubleClicked.connect(lambda r, c: self.open_folder(target, r))
        layout.addWidget(table)
        
        self.tabs_ui[target] = {"search": search_inp, "from": d_from, "to": d_to, "status": status_cb, "inv_status": inv_status_cb, "table": table}
        filter_func = lambda: self.apply_filters(target)
        search_inp.textChanged.connect(filter_func); d_from.changed.connect(filter_func); d_to.changed.connect(filter_func); status_cb.currentIndexChanged.connect(filter_func); inv_status_cb.currentIndexChanged.connect(filter_func)
        
    def build_tab_settled(self, tab):
        layout = QVBoxLayout(tab)
        t_lay = QHBoxLayout()
        search_inp = QLineEdit(); search_inp.setPlaceholderText("جستجو...")
        refresh = QPushButton("بروزرسانی"); refresh.clicked.connect(self.load_data)
        t_lay.addWidget(search_inp); t_lay.addWidget(refresh); t_lay.addStretch()
        layout.addLayout(t_lay)
        
        self.settled_table = CopyableTableWidget(editable=False)
        self.settled_table.setColumnCount(9)
        self.settled_table.setHorizontalHeaderLabels(["شرکت/سازمان", "دوره مالی", "شماره قسط", "تعداد بیمه", "سررسید شرکتی", "مبلغ کل", "مبلغ تسویه شده", "وضعیت", "صورتحساب"])
        self.settled_table.cellDoubleClicked.connect(lambda r, c: self.open_folder("settled", r))
        layout.addWidget(self.settled_table)
        
        self.tabs_ui["settled"] = {"search": search_inp, "table": self.settled_table}
        search_inp.textChanged.connect(lambda: self.apply_filters("settled"))

    def apply_filters(self, target):
        ui = self.tabs_ui[target]
        table = ui["table"]
        search_txt = ui["search"].text().lower()
        d_from = ui.get("from").text() if "from" in ui else ""
        d_to = ui.get("to").text() if "to" in ui else ""
        status = ui.get("status").currentText() if "status" in ui else "همه پرداخت‌ها"
        inv_status = ui.get("inv_status").currentText() if "inv_status" in ui else "همه صورتحساب‌ها"

        today_str = jdatetime.date.today().strftime('%Y/%m/%d')
        near_str = (jdatetime.date.today() + jdatetime.timedelta(days=30)).strftime('%Y/%m/%d')
        due_col = 4
        stat_col = 8 if target != "settled" else 7
        inv_col = 9 if target != "settled" else 8

        for row in range(table.rowCount()):
            show = True
            if search_txt:
                match = False
                for col in range(table.columnCount()):
                    it = table.item(row, col)
                    if it and search_txt in it.text().lower(): match = True; break
                if not match: show = False

            if show and target != "settled" and status != "همه پرداخت‌ها" and status != "نزدیک‌ترین سررسیدها":
                it = table.item(row, stat_col)
                if it:
                    cell_txt = it.text()
                    if status == "پرداخت شده":
                        if "پرداخت شده" not in cell_txt: show = False
                    elif status == "مانده دار":
                        if "مانده دار" not in cell_txt: show = False
                    elif status == "پرداخت نشده":
                        if "پرداخت نشده" not in cell_txt and "آماده پرداخت" not in cell_txt: show = False
                    else:
                        if cell_txt != status: show = False

            if show and inv_status != "همه صورتحساب‌ها":
                it = table.item(row, inv_col)
                if it and it.text() != inv_status: show = False
            due_it = table.item(row, due_col)
            due_val = en_digits(due_it.text()) if due_it else ""
            if show and target != "settled" and status == "نزدیک‌ترین سررسیدها":
                if not due_val or not (today_str <= due_val <= near_str): show = False
            if show and d_from and due_val < d_from: show = False
            if show and d_to and due_val > d_to: show = False
            table.setRowHidden(row, not show)

    def open_folder(self, target, row):
        table = self.tabs_ui[target]["table"]
        comp = table.item(row, 0).text()
        per = en_digits(table.item(row, 1).text())
        year = per.split()[1] if " " in per else "نامشخص"
        comp_safe = "".join(c for c in comp if c.isalnum() or c in (' ', '-', '_')).strip()
        path = os.path.join(DATA_ROOT, f"بایگانی سالانه/{year}/{per}/{comp_safe}")
        os.makedirs(path, exist_ok=True)
        if platform.system() == "Windows": os.startfile(os.path.normpath(path))
        else: QMessageBox.information(self, "خطا", "پلتفرم ویندوز نیست.")

    def load_data(self):
        data = InstallmentManager.get_all_with_policies()
        us_grps = {}; pas_grps = {}; set_grps = {}
        for inst, pol in data:
            comp = (pol.contract_company or "نامشخص").strip()
            per = en_digits(pol.period_name or "نامشخص").strip()
            num = inst.installment_number
            key = (comp, per, num)
            is_inv = pol.is_invoiced
            paid_us = inst.paid_amount_us or 0.0
            paid_pas = inst.paid_amount_pasargad or 0.0

            if paid_pas >= inst.amount - 0.5:
                if key not in set_grps: set_grps[key] = {"count": 0, "amount": 0.0, "paid": 0.0, "due": inst.due_date, "invoiced": True}
                set_grps[key]["count"] += 1; set_grps[key]["amount"] += inst.amount; set_grps[key]["paid"] += paid_pas
                if not is_inv: set_grps[key]["invoiced"] = False
            else:
                if paid_us >= inst.amount - 0.5:
                    if key not in pas_grps: pas_grps[key] = {"count": 0, "amount": 0.0, "paid": 0.0, "due": inst.due_date, "invoiced": True}
                    pas_grps[key]["count"] += 1; pas_grps[key]["amount"] += inst.amount; pas_grps[key]["paid"] += paid_pas
                    if not is_inv: pas_grps[key]["invoiced"] = False
                else:
                    if key not in us_grps: us_grps[key] = {"count": 0, "amount": 0.0, "paid": 0.0, "due": inst.due_date, "invoiced": True}
                    us_grps[key]["count"] += 1; us_grps[key]["amount"] += inst.amount; us_grps[key]["paid"] += paid_us
                    if not is_inv: us_grps[key]["invoiced"] = False

        self.populate_group(self.tabs_ui["us"]["table"], us_grps, target="us")
        self.populate_group(self.tabs_ui["pasargad"]["table"], pas_grps, target="pasargad")
        self.populate_group(self.tabs_ui["settled"]["table"], set_grps, target="settled", is_settled=True)

    def populate_group(self, table, groups, target="us", is_settled=False):
        table.setRowCount(len(groups))
        for i, (key, info) in enumerate(groups.items()):
            comp, per, num = key
            due = self.get_group_due_date(per, num)
            inv_txt = "دارای صورتحساب" if info["invoiced"] else "بدون صورتحساب"
            rem = max(0.0, info["amount"] - info["paid"])

            if is_settled:
                status_txt = "تسویه کامل"
            elif target == "us":
                if info["paid"] > 0 and rem > 0: status_txt = "مانده دار"
                elif rem <= 0: status_txt = "پرداخت شده"
                else: status_txt = "پرداخت نشده"
            elif target == "pasargad":
                if info["paid"] > 0 and rem > 0: status_txt = "مانده دار پاسارگاد"
                elif rem <= 0: status_txt = "پرداخت شده به پاسارگاد"
                else: status_txt = "آماده پرداخت به پاسارگاد"
            else:
                status_txt = "نامشخص"

            if is_settled:
                vals = [comp, per, str(num), str(info["count"]), due, format_number(info["amount"]), format_number(info["paid"]), status_txt, inv_txt]
            else:
                vals = [comp, per, str(num), str(info["count"]), due, format_number(info["amount"]), format_number(info["paid"]), format_number(rem), status_txt, inv_txt]

            status_color = get_status_color(status_txt)
            status_col = 7 if is_settled else 8
            for col, val in enumerate(vals):
                item = QTableWidgetItem(to_persian_digits(val))
                if col == status_col and status_color:
                    item.setBackground(status_color)
                table.setItem(i, col, item)
            table.item(i, 0).setData(Qt.UserRole, key)
        align_table_items(table); table.resizeColumnsToContents()

    def process_group_payment(self, target):
        table = self.tabs_ui[target]["table"]
        selected = table.selectedItems()
        if not selected: return QMessageBox.warning(self, "خطا", "لطفا یک ردیف را انتخاب کنید.")
        
        row = selected[0].row()
        group_key = table.item(row, 0).data(Qt.UserRole) if table.item(row, 0) else None
        if group_key: comp_name, per_name, inst_num = group_key
        else:
            comp_name = table.item(row, 0).text().strip()
            per_name = en_digits(table.item(row, 1).text().strip())
            inst_num = int(en_digits(table.item(row, 2).text()))
        
        all_data = InstallmentManager.get_all_with_policies()
        target_insts = []
        for inst, pol in all_data:
            c = (pol.contract_company or "نامشخص").strip()
            p = en_digits(pol.period_name or "نامشخص").strip()
            if c == comp_name and p == per_name and inst.installment_number == inst_num:
                if target == "us":
                    if (inst.paid_amount_us or 0.0) < inst.amount - 0.5:
                        target_insts.append((inst, pol))
                elif target == "pasargad":
                    if (inst.paid_amount_us or 0.0) >= inst.amount - 0.5 and (inst.paid_amount_pasargad or 0.0) < inst.amount - 0.5:
                        target_insts.append((inst, pol))
                
        if not target_insts: return QMessageBox.warning(self, "خطا", "قسطی برای تسویه این شرکت در این مرحله یافت نشد.")
        dialog = PaymentDialog(target_insts, target, comp_name, self)
        if dialog.exec():
            payments, method, notes, files_str = dialog.get_payment_data()
            if payments:
                try:
                    result = InstallmentManager.register_payment(payments, target, method, notes, files_str)
                except Exception as e:
                    return QMessageBox.critical(self, "خطا در ثبت پرداخت", f"خطا هنگام ثبت در دیتابیس:\n{str(e)}")

                excel_error = None
                if result.get("applied"):
                    applied_data = []
                    applied_ids = {inst_id for inst_id, _ in result["applied"]}
                    try:
                        refreshed = InstallmentManager.get_all_with_policies()
                        for inst, pol in refreshed:
                            if inst.id in applied_ids:
                                for aid, amt in result["applied"]:
                                    if aid == inst.id:
                                        applied_data.append((inst, pol, amt))
                                        break
                        if applied_data:
                            log_result = ExcelEngine.export_payment_log(comp_name, applied_data, target, method, notes, files_str)
                            if log_result and not log_result.get("success"):
                                excel_error = log_result.get("error")
                    except Exception as e:
                        excel_error = str(e)

                if result.get("rejected"):
                    msg = "⚠️ موارد زیر به دلیل مغایرت با قوانین رد شدند:\n\n"
                    for inst_id, amt, reason in result["rejected"]:
                        msg += f"• مبلغ {format_number(amt)} ریال:\n  {reason}\n\n"
                    QMessageBox.warning(self, "هشدار پرداخت", msg)

                if result.get("updated", 0) > 0:
                    if excel_error:
                        QMessageBox.warning(self, "ثبت موفق (با هشدار اکسل)",
                            f"✅ {to_persian_digits(result['updated'])} قسط در دیتابیس ثبت شد.\n\n"
                            f"⚠️ اما خطا در به‌روزرسانی اکسل:\n{excel_error}")
                    else:
                        QMessageBox.information(self, "موفق",
                            f"✅ {to_persian_digits(result['updated'])} قسط با موفقیت ثبت شد و در فایل‌های اکسل ذخیره گردید.")

                try:
                    self.load_data()
                    if self.main_window:
                        if hasattr(self.main_window, 'inst_pers_page'):
                            self.main_window.inst_pers_page.load_data()
                        if hasattr(self.main_window, 'dashboard_page'):
                            self.main_window.dashboard_page.refresh()
                        if hasattr(self.main_window, 'comprehensive_page'):
                            self.main_window.comprehensive_page.load_data()
                except Exception as e:
                    pass

class ComprehensiveInfoPage(QWidget):
    def __init__(self, main_window=None):
        super().__init__()
        self.main_window = main_window
        self.all_data = []
        self.build()

    def build(self):
        layout = QVBoxLayout(self)

        header = QHBoxLayout()
        title = QLabel("اطلاعات جامع اقساط")
        title.setStyleSheet("font-size:22px; font-weight:bold;")
        header.addWidget(title)
        header.addStretch()
        header.addWidget(QLabel("نوع نمایش:"))
        self.mode_cb = QComboBox()
        self.mode_cb.addItems(["اقساط پرسنل (تکی)", "اقساط شرکت‌ها (گروهی)"])
        self.mode_cb.currentIndexChanged.connect(self.on_mode_change)
        header.addWidget(self.mode_cb)
        layout.addLayout(header)

        f_lay = QHBoxLayout()
        self.search_inp = QLineEdit(); self.search_inp.setPlaceholderText("جستجو در همه ستون‌ها...")
        self.d_from = DateInputWidget(); self.d_to = DateInputWidget()
        self.status_cb = QComboBox()
        self.status_cb.addItems([
            "همه وضعیت‌ها", "پرداخت نشده", "مانده دار",
            "پرداخت شده به کارگزاری", "تسویه کامل"
        ])
        self.inv_status_cb = QComboBox()
        self.inv_status_cb.addItems(["همه صورتحساب‌ها", "بدون صورتحساب", "دارای صورتحساب"])
        refresh = QPushButton("بروزرسانی"); refresh.clicked.connect(self.load_data)

        f_lay.addWidget(self.search_inp)
        f_lay.addWidget(QLabel("از سررسید:")); f_lay.addWidget(self.d_from)
        f_lay.addWidget(QLabel("تا سررسید:")); f_lay.addWidget(self.d_to)
        f_lay.addWidget(self.status_cb)
        f_lay.addWidget(self.inv_status_cb)
        f_lay.addWidget(refresh)
        layout.addLayout(f_lay)

        self.stack = QStackedWidget()

        self.pers_table = CopyableTableWidget(editable=False)
        self.pers_table.setColumnCount(19)
        self.pers_table.setHorizontalHeaderLabels([
            "شناسه ۱۰ رقمی", "بیمه‌گذار", "کد ملی", "کد پرسنلی", "پلاک", "شماره بیمه",
            "نوع بیمه", "قرارداد", "شرکت", "دوره مالی", "قسط",
            "تاریخ صدور", "مبلغ کل", "پرداختی کارگزاری", "پرداختی پاسارگاد",
            "مانده کل", "سررسید", "وضعیت نهایی", "وضعیت صورتحساب"
        ])
        self.pers_table.setSelectionBehavior(QTableWidget.SelectRows)
        self.pers_table.setSelectionMode(QTableWidget.SingleSelection)
        self.pers_table.cellDoubleClicked.connect(self.on_pers_double_click)
        self.stack.addWidget(self.pers_table)

        self.comp_table = CopyableTableWidget(editable=False)
        self.comp_table.setColumnCount(11)
        self.comp_table.setHorizontalHeaderLabels([
            "شرکت/سازمان", "دوره مالی", "شماره قسط", "تعداد بیمه",
            "مبلغ کل", "پرداختی کارگزاری", "پرداختی پاسارگاد", "مانده کل",
            "سررسید شرکتی", "وضعیت نهایی", "وضعیت صورتحساب"
        ])
        self.comp_table.setSelectionBehavior(QTableWidget.SelectRows)
        self.comp_table.setSelectionMode(QTableWidget.SingleSelection)
        self.comp_table.cellDoubleClicked.connect(self.on_comp_double_click)
        self.stack.addWidget(self.comp_table)

        layout.addWidget(self.stack)

        self.search_inp.textChanged.connect(self.apply_filters)
        self.d_from.changed.connect(self.apply_filters)
        self.d_to.changed.connect(self.apply_filters)
        self.status_cb.currentIndexChanged.connect(self.apply_filters)
        self.inv_status_cb.currentIndexChanged.connect(self.apply_filters)

    def on_mode_change(self):
        self.stack.setCurrentIndex(self.mode_cb.currentIndex())
        self.apply_filters()

    def load_data(self):
        self.all_data = InstallmentManager.get_all_with_policies()
        self.populate_personnel()
        self.populate_company()
        self.apply_filters()

    def populate_personnel(self):
        rows = self.all_data
        self.pers_table.setRowCount(len(rows))
        for i, (inst, pol) in enumerate(rows):
            paid_us = inst.paid_amount_us or 0.0
            paid_pas = inst.paid_amount_pasargad or 0.0
            rem_total = max(0.0, inst.amount - paid_pas)
            stat_final = self._calc_final_status(inst.amount, paid_us, paid_pas)
            inv_txt = "دارای صورتحساب" if pol.is_invoiced else "بدون صورتحساب"

            vals = [
                inst.tracking_id, pol.insured_name, pol.national_code, pol.personnel_code or "",
                format_plate_for_display(pol.plate),
                pol.policy_number, pol.policy_type, pol.contract_number,
                pol.contract_company or "نامشخص", pol.period_name or "نامشخص",
                str(inst.installment_number), pol.issue_date,
                format_number(inst.amount), format_number(paid_us), format_number(paid_pas),
                format_number(rem_total), inst.due_date, stat_final, inv_txt
            ]

            status_color = get_status_color(stat_final)
            for col, val in enumerate(vals):
                item = QTableWidgetItem(to_persian_digits(val))
                if col == 17 and status_color:
                    item.setBackground(status_color)
                self.pers_table.setItem(i, col, item)

            self.pers_table.item(i, 0).setData(Qt.UserRole, inst.id)
        align_table_items(self.pers_table)
        self.pers_table.resizeColumnsToContents()

    def populate_company(self):
        groups = {}
        for inst, pol in self.all_data:
            comp = (pol.contract_company or "نامشخص").strip()
            per = en_digits(pol.period_name or "نامشخص").strip()
            num = inst.installment_number
            key = (comp, per, num)
            if key not in groups:
                groups[key] = {"count": 0, "amount": 0.0, "paid_us": 0.0, "paid_pas": 0.0, "invoiced_all": True}
            g = groups[key]
            g["count"] += 1
            g["amount"] += inst.amount or 0.0
            g["paid_us"] += (inst.paid_amount_us or 0.0)
            g["paid_pas"] += (inst.paid_amount_pasargad or 0.0)
            if not pol.is_invoiced:
                g["invoiced_all"] = False

        sorted_items = sorted(groups.items(), key=lambda x: (x[0][0], x[0][1], x[0][2]))
        self.comp_table.setRowCount(len(sorted_items))

        for i, ((comp, per, num), g) in enumerate(sorted_items):
            rem_total = max(0.0, g["amount"] - g["paid_pas"])
            stat_final = self._calc_final_status(g["amount"], g["paid_us"], g["paid_pas"])
            inv_txt = "دارای صورتحساب" if g["invoiced_all"] else "بدون صورتحساب"
            due = self._get_group_due_date(per, num)

            vals = [
                comp, per, str(num), str(g["count"]),
                format_number(g["amount"]), format_number(g["paid_us"]),
                format_number(g["paid_pas"]), format_number(rem_total),
                due, stat_final, inv_txt
            ]

            status_color = get_status_color(stat_final)
            for col, val in enumerate(vals):
                item = QTableWidgetItem(to_persian_digits(val))
                if col == 9 and status_color:
                    item.setBackground(status_color)
                self.comp_table.setItem(i, col, item)

            self.comp_table.item(i, 0).setData(Qt.UserRole, (comp, per, num))
        align_table_items(self.comp_table)
        self.comp_table.resizeColumnsToContents()

    def _calc_final_status(self, amount, paid_us, paid_pas):
        if paid_pas >= amount - 0.5: return "تسویه کامل"
        if paid_us >= amount - 0.5: return "پرداخت شده به کارگزاری"
        if paid_us > 0: return "مانده دار"
        return "پرداخت نشده"

    def _get_group_due_date(self, period_name, inst_number):
        months = ["فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور",
                  "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"]
        try:
            m_name, y_str = period_name.split()
            m_idx = months.index(m_name) + 1
            y = int(en_digits(y_str))
            m_idx += inst_number
            while m_idx > 12:
                m_idx -= 12; y += 1
            return f"{y:04d}/{m_idx:02d}/15"
        except: return ""

    def apply_filters(self):
        self._apply_table_filters(self.pers_table, due_col=16, status_col=17, inv_col=18)
        self._apply_table_filters(self.comp_table, due_col=8, status_col=9, inv_col=10)

    def _apply_table_filters(self, table, due_col, status_col, inv_col):
        search_txt = self.search_inp.text().lower()
        d_from = self.d_from.text()
        d_to = self.d_to.text()
        status_sel = self.status_cb.currentText()
        inv_sel = self.inv_status_cb.currentText()

        for row in range(table.rowCount()):
            show = True
            if search_txt:
                match = False
                for col in range(table.columnCount()):
                    it = table.item(row, col)
                    if it and search_txt in it.text().lower(): match = True; break
                if not match: show = False
            if show and status_sel != "همه وضعیت‌ها":
                it = table.item(row, status_col)
                if it and it.text() != status_sel: show = False
            if show and inv_sel != "همه صورتحساب‌ها":
                it = table.item(row, inv_col)
                if it and it.text() != inv_sel: show = False
            due_it = table.item(row, due_col)
            due_val = en_digits(due_it.text()) if due_it else ""
            if show and d_from and due_val < d_from: show = False
            if show and d_to and due_val > d_to: show = False
            table.setRowHidden(row, not show)

    def on_pers_double_click(self, row, col):
        it = self.pers_table.item(row, 0)
        if not it: return
        try:
            inst_id = it.data(Qt.UserRole)
            data = InstallmentManager.get_by_id(inst_id)
            if not data: return
            inst, pol = data
            comp = pol.contract_company or "نامشخص"
            per = en_digits(pol.period_name or "نامشخص")
            year = per.split()[1] if " " in per else "نامشخص"
            comp_safe = "".join(c for c in comp if c.isalnum() or c in (' ', '-', '_')).strip()
            path = os.path.join(DATA_ROOT, f"بایگانی سالانه/{year}/{per}/{comp_safe}")
            os.makedirs(path, exist_ok=True)
            if platform.system() == "Windows":
                os.startfile(os.path.normpath(path))
        except Exception: pass

    def on_comp_double_click(self, row, col):
        comp_item = self.comp_table.item(row, 0)
        per_item = self.comp_table.item(row, 1)
        num_item = self.comp_table.item(row, 2)
        if not (comp_item and per_item and num_item): return
        if self.main_window:
            self.main_window.navigate_company_to_group(
                comp_item.text().strip(),
                en_digits(per_item.text()).strip(),
                en_digits(num_item.text()).strip()
            )

class BackupWorker(QThread):
    progress = Signal(int, str)
    finished_ok = Signal(dict)
    failed = Signal(str)

    def __init__(self, mode, scope=None, params=None, dest_path=None, zip_path=None):
        super().__init__()
        self.mode = mode
        self.scope = scope
        self.params = params or {}
        self.dest_path = dest_path
        self.zip_path = zip_path

    def run(self):
        try:
            def cb(pct, msg):
                self.progress.emit(pct, msg)
            if self.mode == "export":
                result = BackupManager.export_backup(self.scope, self.params, self.dest_path, progress_cb=cb)
            else:
                result = BackupManager.import_backup(self.zip_path, progress_cb=cb)
            self.finished_ok.emit(result)
        except Exception as e:
            self.failed.emit(str(e))

class FinancialSettingsPage(QWidget):
    def __init__(self):
        super().__init__()
        self.build()

    def build(self):
        layout = QVBoxLayout(self)
        title = QLabel("تنظیمات مالی سیستم")
        title.setStyleSheet("font-size:22px; font-weight:bold; margin-bottom: 15px;")
        layout.addWidget(title)

        box = QFrame()
        box.setFrameShape(QFrame.StyledPanel)
        box_l = QVBoxLayout(box)

        form_l = QHBoxLayout()
        form_l.addWidget(QLabel("روز بستن دوره مالی (روز مرجع در هر ماه):"))
        self.cutoff_spin = QSpinBox()
        self.cutoff_spin.setRange(1, 31)
        self.cutoff_spin.setFixedWidth(130)
        self.cutoff_spin.setMinimumHeight(32)
        self.cutoff_spin.setAlignment(Qt.AlignCenter)
        current_day = load_settings().get("period_start_day", 25)
        self.cutoff_spin.setValue(int(current_day))
        form_l.addWidget(self.cutoff_spin)
        form_l.addStretch()
        box_l.addLayout(form_l)

        desc = QLabel(
            "💡 نحوه عملکرد دوره مالی:\n"
            "با تنظیم روز مالی روی عدد مشخص (مثلاً ۲۵):\n"
            "• تمامی بیمه‌نامه‌های صادر شده از ۲۶م ماه قبل تا ۲۵م این ماه، در دوره مالی این ماه قرار می‌گیرند.\n"
            "  مثال: بازه 1405/05/26 الی 1405/06/25 متعلق به دوره مالی «شهریور 1405» خواهد بود.\n"
            "• بازه 1405/06/26 الی 1405/07/25 متعلق به دوره مالی «مهر 1405» خواهد بود."
        )
        desc.setStyleSheet("color: #64748b; font-size: 13px; line-height: 1.8; margin-top: 10px;")
        box_l.addWidget(desc)

        save_btn = QPushButton("💾 ذخیره تنظیمات مالی")
        save_btn.setFixedWidth(180)
        save_btn.clicked.connect(self.save_settings)
        box_l.addWidget(save_btn)

        layout.addWidget(box)
        layout.addSpacing(15)
        layout.addWidget(self.build_backup_box())
        layout.addStretch()

    def build_backup_box(self):
        box = QFrame()
        box.setFrameShape(QFrame.StyledPanel)
        box_l = QVBoxLayout(box)

        title = QLabel("پشتیبان‌گیری و انتقال اطلاعات (Export / Import)")
        title.setStyleSheet("font-size:18px; font-weight:bold; margin-bottom: 8px;")
        box_l.addWidget(title)

        cols_lay = QHBoxLayout()
        cols_lay.addWidget(self.build_export_panel(), 1)
        cols_lay.addWidget(self.build_import_panel(), 1)
        box_l.addLayout(cols_lay)
        return box

    def build_export_panel(self):
        panel = QFrame()
        panel.setFrameShape(QFrame.StyledPanel)
        lay = QVBoxLayout(panel)

        lbl = QLabel("📤 خروجی گرفتن (Export)")
        lbl.setStyleSheet("font-weight:bold; font-size:15px;")
        lay.addWidget(lbl)

        date_lay = QHBoxLayout()
        date_lay.addWidget(QLabel("از تاریخ:"))
        self.exp_from = DateInputWidget()
        date_lay.addWidget(self.exp_from)
        date_lay.addWidget(QLabel("تا تاریخ:"))
        self.exp_to = DateInputWidget()
        date_lay.addWidget(self.exp_to)
        lay.addLayout(date_lay)
        self.exp_from.changed.connect(self.on_export_date_changed)
        self.exp_to.changed.connect(self.on_export_date_changed)

        per_lay = QHBoxLayout()
        per_lay.addWidget(QLabel("یا انتخاب یک دوره مالی مشخص:"))
        self.exp_period_cb = QComboBox()
        self.exp_period_cb.addItem("بدون انتخاب دوره خاص")
        try:
            periods = BackupManager.get_available_periods()
        except Exception:
            periods = []
        self.exp_period_cb.addItems(periods)
        self.exp_period_cb.currentIndexChanged.connect(self.on_export_period_changed)
        per_lay.addWidget(self.exp_period_cb)
        lay.addLayout(per_lay)

        quick_lay = QHBoxLayout()
        btn_all = QPushButton("📦 کل دوره (همه اطلاعات)")
        btn_all.clicked.connect(self.use_full_export_range)
        btn_month = QPushButton("🗓 یک ماه اخیر")
        btn_month.clicked.connect(self.use_last_month_range)
        quick_lay.addWidget(btn_all); quick_lay.addWidget(btn_month)
        lay.addLayout(quick_lay)

        self.exp_scope_lbl = QLabel("حالت انتخاب‌شده: 📦 کل اطلاعات سیستم")
        self.exp_scope_lbl.setStyleSheet("color: #64748b; font-size: 12px; margin-top: 4px;")
        lay.addWidget(self.exp_scope_lbl)

        self.exp_btn = QPushButton("📤 خروجی گرفتن (Export)")
        self.exp_btn.setStyleSheet("background-color: #4caf50; color: white; font-weight:bold; padding: 8px;")
        self.exp_btn.clicked.connect(self.start_export)
        lay.addWidget(self.exp_btn)

        self.exp_progress = QProgressBar(); self.exp_progress.setRange(0, 100); self.exp_progress.setValue(0)
        self.exp_progress.setVisible(False)
        lay.addWidget(self.exp_progress)

        self.exp_status = QLabel("")
        self.exp_status.setStyleSheet("color: #64748b; font-size: 12px;")
        lay.addWidget(self.exp_status)
        lay.addStretch()
        return panel

    def build_import_panel(self):
        panel = QFrame()
        panel.setFrameShape(QFrame.StyledPanel)
        lay = QVBoxLayout(panel)

        lbl = QLabel("📥 ورود اطلاعات (Import)")
        lbl.setStyleSheet("font-weight:bold; font-size:15px;")
        lay.addWidget(lbl)

        info = QLabel(
            "💡 با انتخاب یک فایل پشتیبان (Zip) که از همین برنامه خروجی گرفته شده،\n"
            "تمامی اطلاعات آن (بیمه‌نامه‌ها، اقساط، پرداختی‌ها، بایگانی سالانه، اکسل‌ها و صورتحساب‌ها)\n"
            "به سیستم فعلی اضافه می‌شود، بدون حذف اطلاعات موجود."
        )
        info.setStyleSheet("color: #64748b; font-size: 12px; line-height: 1.6;")
        info.setWordWrap(True)
        lay.addWidget(info)

        file_lay = QHBoxLayout()
        self.imp_file_btn = QPushButton("انتخاب فایل پشتیبان (zip)...")
        self.imp_file_btn.clicked.connect(self.select_import_file)
        self.imp_file_lbl = QLabel("فایلی انتخاب نشده")
        self.imp_file_lbl.setStyleSheet("color: #64748b;")
        file_lay.addWidget(self.imp_file_btn); file_lay.addWidget(self.imp_file_lbl); file_lay.addStretch()
        lay.addLayout(file_lay)
        self.import_file_path = None

        self.imp_btn = QPushButton("📥 شروع ورود اطلاعات (Import)")
        self.imp_btn.setStyleSheet("background-color: #2f7dd1; color: white; font-weight:bold; padding: 8px;")
        self.imp_btn.setEnabled(False)
        self.imp_btn.clicked.connect(self.start_import)
        lay.addWidget(self.imp_btn)

        self.imp_progress = QProgressBar(); self.imp_progress.setRange(0, 100); self.imp_progress.setValue(0)
        self.imp_progress.setVisible(False)
        lay.addWidget(self.imp_progress)

        self.imp_status = QLabel("")
        self.imp_status.setStyleSheet("color: #64748b; font-size: 12px;")
        lay.addWidget(self.imp_status)
        lay.addStretch()
        return panel

    # ---------------------------- Export helpers ----------------------------
    def on_export_date_changed(self):
        if self.exp_from.text() or self.exp_to.text():
            self.exp_period_cb.blockSignals(True); self.exp_period_cb.setCurrentIndex(0); self.exp_period_cb.blockSignals(False)
            self.exp_scope_lbl.setText("حالت انتخاب‌شده: 🗓 بازه تاریخ سفارشی")

    def on_export_period_changed(self, idx):
        if idx > 0:
            self._clear_date_widget(self.exp_from); self._clear_date_widget(self.exp_to)
            self.exp_scope_lbl.setText(f"حالت انتخاب‌شده: 📅 دوره «{self.exp_period_cb.currentText()}»")
        else:
            self.exp_scope_lbl.setText("حالت انتخاب‌شده: 📦 کل اطلاعات سیستم")

    def _clear_date_widget(self, widget):
        widget.blockSignals(True)
        widget.d.setCurrentIndex(0); widget.m.setCurrentIndex(0); widget.y.clear()
        widget.blockSignals(False)

    def _set_date_widget(self, widget, jdate):
        widget.blockSignals(True)
        widget.d.setCurrentText(f"{jdate.day:02d}")
        widget.m.setCurrentText(f"{jdate.month:02d}")
        widget.y.setText(str(jdate.year))
        widget.blockSignals(False)

    def use_full_export_range(self):
        self._clear_date_widget(self.exp_from); self._clear_date_widget(self.exp_to)
        self.exp_period_cb.blockSignals(True); self.exp_period_cb.setCurrentIndex(0); self.exp_period_cb.blockSignals(False)
        self.exp_scope_lbl.setText("حالت انتخاب‌شده: 📦 کل اطلاعات سیستم")

    def use_last_month_range(self):
        today = jdatetime.date.today()
        start = today - jdatetime.timedelta(days=30)
        self.exp_period_cb.blockSignals(True); self.exp_period_cb.setCurrentIndex(0); self.exp_period_cb.blockSignals(False)
        self._set_date_widget(self.exp_from, start)
        self._set_date_widget(self.exp_to, today)
        self.exp_scope_lbl.setText("حالت انتخاب‌شده: 🗓 یک ماه اخیر")

    def _resolve_export_scope(self):
        if self.exp_period_cb.currentIndex() > 0:
            return "period", {"period_name": self.exp_period_cb.currentText()}
        d_from = self.exp_from.text(); d_to = self.exp_to.text()
        if d_from or d_to:
            return "range", {"date_from": d_from, "date_to": d_to}
        return "all", {}

    def start_export(self):
        scope, params = self._resolve_export_scope()
        default_name = f"MammutERP_Backup_{jdatetime.date.today().strftime('%Y%m%d')}.zip"
        path, _ = QFileDialog.getSaveFileName(self, "ذخیره فایل پشتیبان", default_name, "Zip Archive (*.zip)")
        if not path: return
        if not path.lower().endswith(".zip"): path += ".zip"

        self.exp_progress.setVisible(True); self.exp_progress.setValue(0)
        self.exp_status.setText("در حال آماده‌سازی...")
        self.exp_btn.setEnabled(False)

        self.exp_worker = BackupWorker("export", scope=scope, params=params, dest_path=path)
        self.exp_worker.progress.connect(self._on_export_progress)
        self.exp_worker.finished_ok.connect(self._on_export_done)
        self.exp_worker.failed.connect(self._on_export_failed)
        self.exp_worker.start()

    def _on_export_progress(self, pct, msg):
        self.exp_progress.setValue(pct); self.exp_status.setText(msg)

    def _on_export_done(self, result):
        self.exp_btn.setEnabled(True)
        self.exp_status.setText("✅ خروجی با موفقیت ذخیره شد.")
        QMessageBox.information(self, "موفق",
            "فایل پشتیبان با موفقیت ایجاد شد.\n"
            f"شرکت‌ها: {to_persian_digits(result.get('companies', 0))}\n"
            f"بیمه‌نامه‌ها: {to_persian_digits(result.get('policies', 0))}\n"
            f"اقساط: {to_persian_digits(result.get('installments', 0))}\n"
            f"صورتحساب‌ها: {to_persian_digits(result.get('invoices', 0))}")

    def _on_export_failed(self, err):
        self.exp_btn.setEnabled(True)
        self.exp_status.setText("❌ خطا در خروجی گرفتن")
        QMessageBox.critical(self, "خطا", f"خطا در تهیه فایل پشتیبان:\n{err}")

    # ---------------------------- Import helpers ----------------------------
    def select_import_file(self):
        path, _ = QFileDialog.getOpenFileName(self, "انتخاب فایل پشتیبان", "", "Zip Archive (*.zip)")
        if path:
            self.import_file_path = path
            self.imp_file_lbl.setText(os.path.basename(path))
            self.imp_btn.setEnabled(True)

    def start_import(self):
        if not self.import_file_path: return
        confirm = QMessageBox.question(
            self, "تایید ورود اطلاعات",
            "تمامی اطلاعات موجود در فایل پشتیبان (بدون حذف اطلاعات فعلی سیستم) اضافه می‌شود.\n"
            "این عملیات ممکن است بسته به حجم داده‌ها زمان‌بر باشد. ادامه می‌دهید؟",
            QMessageBox.Yes | QMessageBox.No
        )
        if confirm != QMessageBox.Yes: return

        self.imp_progress.setVisible(True); self.imp_progress.setValue(0)
        self.imp_status.setText("در حال شروع...")
        self.imp_btn.setEnabled(False)

        self.imp_worker = BackupWorker("import", zip_path=self.import_file_path)
        self.imp_worker.progress.connect(self._on_import_progress)
        self.imp_worker.finished_ok.connect(self._on_import_done)
        self.imp_worker.failed.connect(self._on_import_failed)
        self.imp_worker.start()

    def _on_import_progress(self, pct, msg):
        self.imp_progress.setValue(pct); self.imp_status.setText(msg)

    def _on_import_done(self, result):
        self.imp_btn.setEnabled(True)
        if not result.get("success", True):
            self.imp_status.setText("❌ خطا")
            QMessageBox.critical(self, "خطا", result.get("error", "خطای نامشخص در ورود اطلاعات."))
            return
        self.imp_status.setText("✅ ورود اطلاعات با موفقیت انجام شد.")
        QMessageBox.information(self, "موفق",
            "ورود اطلاعات با موفقیت انجام شد.\n"
            f"بیمه‌نامه‌های جدید: {to_persian_digits(result.get('policies_created', 0))}\n"
            f"اقساط جدید: {to_persian_digits(result.get('installments_created', 0))}\n\n"
            "پیشنهاد می‌شود برنامه را مجدداً اجرا کنید تا تمامی تغییرات در همه بخش‌ها نمایش داده شود.")

    def _on_import_failed(self, err):
        self.imp_btn.setEnabled(True)
        self.imp_status.setText("❌ خطا در ورود اطلاعات")
        QMessageBox.critical(self, "خطا", f"خطا در ورود اطلاعات:\n{err}")

    def save_settings(self):
        s = load_settings()
        s["period_start_day"] = self.cutoff_spin.value()
        save_settings(s)
        QMessageBox.information(self, "موفق", f"تنظیمات مالی با موفقیت ذخیره شد.\nروز مرجع به {to_persian_digits(self.cutoff_spin.value())}ام ماه تغییر یافت.")

class InvoicePage(QWidget):
    def __init__(self):
        super().__init__()
        self.template_path = ""
        self.rows_data = []
        self.build()

    def build(self):
        layout = QVBoxLayout(self)
        title = QLabel("صدور صورتحساب هوشمند")
        title.setStyleSheet("font-size:24px; font-weight:bold; margin-bottom:10px;")
        layout.addWidget(title)
        
        controls = QGridLayout()
        self.type_r1 = QRadioButton("نوع ۱ (تجمعی کل شرکت‌ها)"); self.type_r1.setChecked(True)
        self.type_r2 = QRadioButton("نوع ۲ (تفصیلی یک شرکت)")
        self.type_r3 = QRadioButton("نوع ۳ (تجمیعی حقیقی)")
        
        self.type_r1.toggled.connect(self.toggle_type)
        self.type_r2.toggled.connect(self.toggle_type)
        self.type_r3.toggled.connect(self.toggle_type)
        
        radio_lay = QHBoxLayout()
        radio_lay.setSpacing(20)
        radio_lay.addWidget(self.type_r1)
        radio_lay.addWidget(self.type_r2)
        radio_lay.addWidget(self.type_r3)
        radio_lay.addStretch()
        controls.addLayout(radio_lay, 0, 0, 1, 3)

        self.per_cb = QComboBox()
        self.comp_cb = QComboBox(); self.comp_cb.setEnabled(False)
        self.custom_comp_input = QLineEdit(); self.custom_comp_input.setPlaceholderText("نام سازمان (برای نوع ۱ و ۳)")
        
        self.per_cb.currentIndexChanged.connect(self.update_companies)
        self.comp_cb.currentIndexChanged.connect(self.load_uninvoiced)
        
        controls.addWidget(QLabel("دوره مالی (اجباری):"), 1, 0); controls.addWidget(self.per_cb, 1, 1)
        controls.addWidget(QLabel("شرکت (نوع ۲):"), 2, 0); controls.addWidget(self.comp_cb, 2, 1)
        controls.addWidget(QLabel("نام سفارشی (نوع ۱ و ۳):"), 3, 0); controls.addWidget(self.custom_comp_input, 3, 1)

        self.inv_number = QLineEdit()
        yr = str(jdatetime.date.today().year)[-2:]
        db = get_session(); count = db.query(Invoice).count() + 1; db.close()
        self.inv_number.setText(f"SM55262-{yr}-{count:05d}")
        controls.addWidget(QLabel("شماره صورتحساب:"), 4, 0); controls.addWidget(self.inv_number, 4, 1)

        self.tpl_cb = QComboBox()
        controls.addWidget(QLabel("انتخاب قالب از سیستم:"), 5, 0)
        
        tpl_lay = QHBoxLayout()
        tpl_lay.addWidget(self.tpl_cb)
        self.tpl_btn = QPushButton("انتخاب از فایل...")
        self.tpl_btn.clicked.connect(self.select_template)
        tpl_lay.addWidget(self.tpl_btn)
        controls.addLayout(tpl_lay, 5, 1)
        
        layout.addLayout(controls)
        
        self.total_sel_lbl = QLabel("جمع کل انتخاب شده: ۰ ریال")
        self.total_sel_lbl.setStyleSheet("font-weight:bold; color:#4caf50; font-size:16px; margin-top:10px;")
        layout.addWidget(self.total_sel_lbl)

        self.table = CopyableTableWidget(editable=False)
        self.table.setColumnCount(7)
        self.table.setHorizontalHeaderLabels(["انتخاب", "شرکت", "دوره مالی", "شماره بیمه", "بیمه گذار", "حق بیمه", "تاریخ صدور"])
        layout.addWidget(self.table)
        
        self.gen_btn = QPushButton("صدور و ذخیره صورتحساب"); self.gen_btn.setStyleSheet("background-color: #4caf50; color: white; font-size:16px; padding: 10px;")
        self.gen_btn.clicked.connect(self.generate)
        layout.addWidget(self.gen_btn)
        self.refresh_periods()

    def refresh_periods(self):
        db = get_session()
        pers = list(set(p[0] for p in db.query(Policy.period_name).filter(Policy.is_invoiced == False).all() if p[0]))
        db.close()
        self.per_cb.blockSignals(True)
        self.per_cb.clear()
        self.per_cb.addItems(["انتخاب دوره مالی..."] + pers)
        self.per_cb.blockSignals(False)
        self.update_companies()
        self.load_templates()

    def update_companies(self):
        sel_per = self.per_cb.currentText()
        if self.per_cb.currentIndex() == 0:
            self.comp_cb.clear(); self.table.setRowCount(0); self.calc_live_total(); return
            
        db = get_session()
        q = db.query(Policy.contract_company).filter(Policy.is_invoiced == False)
        if sel_per != "انتخاب دوره مالی...": q = q.filter(Policy.period_name == sel_per)
        comps = list(set(p[0] for p in q.all() if p[0]))
        db.close()
        
        self.comp_cb.blockSignals(True)
        self.comp_cb.clear()
        self.comp_cb.addItems(["همه شرکت‌ها"] + comps)
        self.comp_cb.blockSignals(False)
        self.load_uninvoiced()

    def load_templates(self):
        self.tpl_cb.clear(); self.tpl_cb.addItem("انتخاب قالب...")
        tpl_dir = os.path.join(DATA_ROOT, "Templates")
        if os.path.exists(tpl_dir):
            for f in os.listdir(tpl_dir):
                if f.endswith(".docx"): self.tpl_cb.addItem(f)

    def toggle_type(self):
        is_type2 = self.type_r2.isChecked()
        self.comp_cb.setEnabled(is_type2)
        self.custom_comp_input.setEnabled(not is_type2)
        if not is_type2: 
            if self.comp_cb.count() > 0: self.comp_cb.setCurrentIndex(0)
        self.load_uninvoiced()

    def select_template(self):
        file, _ = QFileDialog.getOpenFileName(self, "انتخاب قالب Word", "", "Word Documents (*.docx)")
        if file:
            self.template_path = file
            self.tpl_cb.addItem(os.path.basename(file))
            self.tpl_cb.setCurrentIndex(self.tpl_cb.count() - 1)

    def calc_live_total(self):
        total = sum(pol.premium_amount for chk, pol in self.rows_data if chk.isChecked())
        self.total_sel_lbl.setText(f"جمع کل انتخاب شده: {format_number(total)} ریال")

    def load_uninvoiced(self):
        if self.per_cb.currentIndex() == 0:
            self.table.setRowCount(0); self.calc_live_total(); return
            
        db = get_session()
        q = db.query(Policy).filter(Policy.is_invoiced == False)
        if self.per_cb.currentText() != "انتخاب دوره مالی...": q = q.filter(Policy.period_name == self.per_cb.currentText())
        if self.type_r2.isChecked() and self.comp_cb.currentIndex() > 0: q = q.filter(Policy.contract_company == self.comp_cb.currentText())
        res = q.all(); db.close()

        self.table.setRowCount(len(res))
        self.rows_data = []
        for i, pol in enumerate(res):
            chk = QCheckBox(); chk.setChecked(True)
            chk.stateChanged.connect(self.calc_live_total)
            chk_w = QWidget(); l = QHBoxLayout(chk_w); l.addWidget(chk); l.setAlignment(Qt.AlignCenter); l.setContentsMargins(0,0,0,0)
            self.table.setCellWidget(i, 0, chk_w)
            
            vals = [pol.contract_company or "نامشخص", pol.period_name, pol.policy_number, pol.insured_name, format_number(pol.premium_amount), pol.issue_date]
            for col, val in enumerate(vals, start=1): self.table.setItem(i, col, QTableWidgetItem(to_persian_digits(val)))
            self.rows_data.append((chk, pol))
        align_table_items(self.table); self.table.resizeColumnsToContents()
        self.calc_live_total()

    def generate(self):
        tpl_name = self.tpl_cb.currentText()
        if self.tpl_cb.currentIndex() > 0: self.template_path = os.path.join(DATA_ROOT, "Templates", tpl_name)
        if not self.template_path or not os.path.exists(self.template_path): return QMessageBox.warning(self, "خطا", "ابتدا قالب Word را انتخاب کنید.")
        
        selected = [pol for chk, pol in self.rows_data if chk.isChecked()]
        if not selected: return QMessageBox.warning(self, "خطا", "هیچ بیمه نامه‌ای انتخاب نشده است.")

        total_prem = sum(p.premium_amount for p in selected)
        ctx = {
            "invoice_number": to_persian_digits(self.inv_number.text()),
            "date": to_persian_digits(jdatetime.date.today().strftime('%Y/%m/%d')),
            "total_premium": format_number(total_prem),
            "total_count": format_number(len(selected))
        }

        if self.type_r1.isChecked(): 
            inv_type = 1
            ctx["company_name"] = self.custom_comp_input.text() or "مشتریان"
            grouped = {}
            for p in selected:
                c = p.contract_company or "نامشخص"
                if c not in grouped: grouped[c] = {"company": c, "third_count": 0, "body_count": 0, "premium": 0, "date": p.issue_date}
                if p.policy_type == "ثالث": grouped[c]["third_count"] += 1
                elif p.policy_type == "بدنه": grouped[c]["body_count"] += 1
                grouped[c]["premium"] += p.premium_amount
            items = []
            for g in grouped.values():
                items.append({
                    "company": g["company"], "third_count": format_number(g["third_count"]), 
                    "body_count": format_number(g["body_count"]), "premium": format_number(g["premium"]), "date": to_persian_digits(g["date"])
                })
            ctx["items"] = items
        elif self.type_r2.isChecked(): 
            inv_type = 2
            ctx["company_name"] = self.comp_cb.currentText() if self.comp_cb.currentIndex()>0 else "نامشخص"
            items = []
            for p in selected:
                items.append({
                    "type": p.policy_type, "name": p.insured_name, "personnel_code": to_persian_digits(p.personnel_code or ""), 
                    "national_code": to_persian_digits(p.national_code), "premium": format_number(p.premium_amount), "date": to_persian_digits(p.issue_date)
                })
            ctx["items"] = items
        else: # نوع ۳: تجمیعی حقیقی
            inv_type = 3
            ctx["company_name"] = self.custom_comp_input.text() or "مشتریان"
            items = []
            for p in selected:
                items.append({
                    "name": p.insured_name,
                    "company": p.contract_company or "نامشخص",
                    "personnel_code": to_persian_digits(p.personnel_code or ""),
                    "national_code": to_persian_digits(p.national_code or ""),
                    "policy_number": to_persian_digits(p.policy_number or ""),
                    "date": to_persian_digits(p.issue_date or ""),
                    "premium": format_number(p.premium_amount)
                })
            ctx["items"] = items

        per_name = self.per_cb.currentText() if self.per_cb.currentIndex()>0 else "نامشخص"
        comp_name = ctx["company_name"]

        try:
            out_docx, out_pdf, save_dir = ExcelEngine.generate_invoice(inv_type, self.template_path, ctx, per_name, comp_name)
            
            db = get_session()
            inv = Invoice(
                invoice_number=en_digits(self.inv_number.text()), 
                company_name=comp_name, 
                period_name=per_name, 
                total_policies=len(selected), 
                total_amount=total_prem, 
                created_date=en_digits(jdatetime.date.today().strftime('%Y/%m/%d')), 
                folder_path=save_dir, 
                status="issued"
            )
            db.add(inv); db.flush()
            for p in selected:
                db_pol = db.query(Policy).filter(Policy.id == p.id).first()
                if db_pol: db_pol.is_invoiced = True; db_pol.invoice_number = inv.invoice_number
            db.commit(); db.close()

            try:
                ExcelEngine.sync_master_excel()
                sync_err = None
            except Exception as e:
                sync_err = str(e)

            msg = f"صورتحساب با موفقیت صادر شد:\nWord: {os.path.basename(out_docx)}"
            if out_pdf: msg += f"\nPDF: {os.path.basename(out_pdf)}"
            if sync_err:
                msg += f"\n\n⚠️ خطا در به‌روزرسانی اکسل جامع:\n{sync_err}"
                QMessageBox.warning(self, "صدور موفق (با هشدار اکسل)", msg)
            else:
                QMessageBox.information(self, "موفق", msg)
            
            self.refresh_periods()
            if platform.system() == "Windows": os.startfile(os.path.normpath(save_dir))
        except Exception as e:
            QMessageBox.critical(self, "خطا", f"خطا در صدور صورتحساب:\n{str(e)}")

class InvoicesListPage(QWidget):
    def __init__(self):
        super().__init__()
        self.build()

    def build(self):
        layout = QVBoxLayout(self)
        title = QLabel("صورتحساب‌های صادر شده")
        title.setStyleSheet("font-size:24px; font-weight:bold; margin-bottom:10px;")
        layout.addWidget(title)
        
        t_lay = QHBoxLayout()
        search_inp = QLineEdit(); search_inp.setPlaceholderText("جستجو...")
        refresh = QPushButton("بروزرسانی"); refresh.clicked.connect(self.load_data)
        t_lay.addWidget(search_inp); t_lay.addWidget(refresh); t_lay.addStretch()
        layout.addLayout(t_lay)
        
        self.table = CopyableTableWidget(editable=False)
        self.table.setColumnCount(8)
        self.table.setHorizontalHeaderLabels(["ردیف", "شماره صورتحساب", "دوره مالی", "شرکت / سازمان", "تعداد بیمه‌نامه", "مبلغ کل", "تاریخ صدور", "وضعیت"])
        self.table.setSelectionBehavior(QTableWidget.SelectRows); self.table.setSelectionMode(QTableWidget.SingleSelection)
        self.table.cellDoubleClicked.connect(self.open_folder)
        setup_live_search(search_inp, self.table)
        layout.addWidget(self.table)
        
    def load_data(self):
        db = get_session(); invs = db.query(Invoice).order_by(Invoice.id.desc()).all(); db.close()
        self.table.setRowCount(len(invs))
        for i, inv in enumerate(invs):
            vals = [str(i+1), inv.invoice_number, inv.period_name or "", inv.company_name or "", format_number(inv.total_policies), format_number(inv.total_amount), to_persian_digits(inv.created_date or ""), "صادر شده"]
            for col, val in enumerate(vals):
                item = QTableWidgetItem(to_persian_digits(val))
                self.table.setItem(i, col, item)
            self.table.item(i, 0).setData(Qt.UserRole, inv.folder_path)
        align_table_items(self.table); self.table.resizeColumnsToContents()

    def open_folder(self, row, col):
        path = self.table.item(row, 0).data(Qt.UserRole)
        if path and os.path.exists(path) and platform.system() == "Windows": os.startfile(os.path.normpath(path))
        else: QMessageBox.information(self, "خطا", "پوشه یافت نشد یا پلتفرم ویندوز نیست.")

class MainWindow(QWidget):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("MammutERP")
        self.resize(1400, 800)
        self.setLayoutDirection(Qt.RightToLeft)
        self.apply_theme()
        self.build()

    def apply_theme(self):
        is_dark = load_settings().get("dark_mode", True)
        if is_dark:
            self.setStyleSheet("""QWidget { background-color: #2b2b2b; color: #ffffff; } 
                QPushButton { background-color: #3b3b3b; border: 1px solid #555; border-radius: 5px; padding: 5px; } 
                QPushButton:hover { background-color: #4b4b4b; } 
                QTableWidget { background-color: #2b2b2b; color: #ffffff; gridline-color: #555; border: 1px solid #555;} 
                QHeaderView::section { background-color: #3b3b3b; color: #ffffff; border: 1px solid #555; padding:4px;} 
                QLineEdit, QComboBox { background-color: #3b3b3b; color: #ffffff; border: 1px solid #555; border-radius: 4px; padding: 4px;} 
                QSpinBox { background-color: #3b3b3b; color: #ffffff; border: 1px solid #555; border-radius: 4px; padding: 2px; }
                QRadioButton::indicator { width: 14px; height: 14px; border-radius: 7px; border: 1px solid #888; background: #3b3b3b; } 
                QRadioButton::indicator:checked { background: #4caf50; border: 3px solid #3b3b3b; outline: 1px solid #4caf50; }
                QPushButton[navBtn="true"] { text-align: center; }
                QPushButton[navBtn="true"]:hover { background-color: #565656; }
                QPushButton[navBtn="true"]:checked { background-color: #3d6b3d; border: 1px solid #4caf50; color: #ffffff; font-weight: bold; }
                QPushButton[navBtn="true"]:checked:hover { background-color: #457a45; }""")
        else:
            self.setStyleSheet("""QWidget { background-color: #f5f5f5; color: #2b2b2b; } 
                QPushButton { background-color: #ffffff; border: 1px solid #ccc; border-radius: 6px; padding: 6px; } 
                QPushButton:hover { background-color: #e0e0e0; } 
                QTableWidget { background-color: #ffffff; color: #2b2b2b; gridline-color: #cbd5e1; border: 1px solid #ccc;} 
                QHeaderView::section { background-color: #eaeaea; color: #2b2b2b; border: 1px solid #d3d3d3; padding:4px;} 
                QLineEdit, QComboBox { background-color: #ffffff; color: #2b2b2b; border: 1px solid #ccc; border-radius: 4px; padding: 4px;} 
                QSpinBox { background-color: #ffffff; color: #2b2b2b; border: 1px solid #ccc; border-radius: 4px; padding: 2px; }
                QRadioButton::indicator { width: 14px; height: 14px; border-radius: 7px; border: 1px solid #777; background: #fff; } 
                QRadioButton::indicator:checked { background: #4caf50; border: 3px solid #fff; outline: 1px solid #4caf50; }
                QPushButton[navBtn="true"] { text-align: center; }
                QPushButton[navBtn="true"]:hover { background-color: #d6d6d6; }
                QPushButton[navBtn="true"]:checked { background-color: #cdeccd; border: 1px solid #4caf50; color: #1b5e20; font-weight: bold; }
                QPushButton[navBtn="true"]:checked:hover { background-color: #bfe6bf; }""")

    def toggle_theme(self):
        settings = load_settings(); settings["dark_mode"] = not settings.get("dark_mode", True); save_settings(settings)
        self.apply_theme()
        if hasattr(self, 'dashboard_page'):
            self.dashboard_page.refresh()

    def build(self):
        main_layout = QHBoxLayout(self)
        sidebar = QVBoxLayout()
        logo = QLabel("MammutERP")
        logo.setAlignment(Qt.AlignCenter)
        logo.setStyleSheet("font-size:24px; font-weight:bold;")
        sidebar.addWidget(logo)

        profile_lay = QVBoxLayout()
        name_lbl = QLabel(SYS_USER)
        name_lbl.setAlignment(Qt.AlignCenter)
        name_lbl.setStyleSheet("font-size: 16px; font-weight: bold; margin-top:10px;")
        
        self.clock_lbl = QLabel()
        self.clock_lbl.setAlignment(Qt.AlignCenter)
        self.clock_lbl.setStyleSheet("font-size: 12px; color: #888; margin-bottom:10px;")
        profile_lay.addWidget(name_lbl); profile_lay.addWidget(self.clock_lbl)
        sidebar.addLayout(profile_lay)
        
        self.timer = QTimer(self); self.timer.timeout.connect(self.update_clock); self.timer.start(1000); self.update_clock()

        self.theme_btn = QPushButton("🌓   تغییر تم"); self.theme_btn.clicked.connect(self.toggle_theme)
        self.dashboard_btn = QPushButton("📊   داشبورد")
        self.import_btn = QPushButton("📥   ورود Excel")
        self.inst_personnel_btn = QPushButton("🧍   اقساط پرسنل (تکی)")
        self.inst_company_btn = QPushButton("🏢   اقساط شرکت‌ها (گروهی)")
        self.invoice_btn = QPushButton("🧾   صدور صورتحساب")
        self.invoice_list_btn = QPushButton("📋   صورتحساب‌های صادر شده")
        self.comprehensive_btn = QPushButton("🔎   اطلاعات جامع")
        self.settings_btn = QPushButton("⚙️   تنظیمات مالی")

        # دکمه‌های ناوبری منو (بدون دکمه تغییر تم) که حالت فعال/هاور دارند
        self.nav_buttons = [
            self.dashboard_btn, self.import_btn, self.inst_personnel_btn,
            self.inst_company_btn, self.invoice_btn, self.invoice_list_btn,
            self.comprehensive_btn, self.settings_btn
        ]
        for btn in self.nav_buttons:
            btn.setCheckable(True)
            btn.setProperty("navBtn", True)
            btn.setLayoutDirection(Qt.RightToLeft)

        for btn in [self.theme_btn] + self.nav_buttons:
            btn.setMinimumHeight(40); sidebar.addWidget(btn)
        sidebar.addStretch()
        main_layout.addLayout(sidebar, 1)

        main_content_lay = QVBoxLayout()
        self.pages = QStackedWidget()
        self.dashboard_page = DashboardPage(self); self.import_page = ImportPage()
        self.inst_pers_page = InstallmentsPersonnelPage(self); self.inst_comp_page = InstallmentsCompanyPage(self)
        self.invoice_page = InvoicePage(); self.invoices_list_page = InvoicesListPage()
        self.comprehensive_page = ComprehensiveInfoPage(self)
        self.financial_settings_page = FinancialSettingsPage()

        for p in [
            self.dashboard_page, self.import_page, self.inst_pers_page, 
            self.inst_comp_page, self.invoice_page, self.invoices_list_page, 
            self.comprehensive_page, self.financial_settings_page
        ]: self.pages.addWidget(p)
        main_content_lay.addWidget(self.pages)
        
        footer = QLabel("طراحی و توسعه : واحد IT (MMBehzadi)")
        footer.setAlignment(Qt.AlignCenter)
        footer.setStyleSheet("color: #94a3b8; font-size: 11px;")
        main_content_lay.addWidget(footer)
        
        main_layout.addLayout(main_content_lay, 5)

        self.dashboard_btn.clicked.connect(lambda: [self.dashboard_page.refresh(), self.pages.setCurrentWidget(self.dashboard_page)])
        self.import_btn.clicked.connect(lambda: self.pages.setCurrentWidget(self.import_page))
        self.inst_personnel_btn.clicked.connect(lambda: [self.inst_pers_page.load_data(), self.pages.setCurrentWidget(self.inst_pers_page)])
        self.inst_company_btn.clicked.connect(lambda: [self.inst_comp_page.load_data(), self.pages.setCurrentWidget(self.inst_comp_page)])
        self.invoice_btn.clicked.connect(lambda: [self.invoice_page.refresh_periods(), self.pages.setCurrentWidget(self.invoice_page)])
        self.invoice_list_btn.clicked.connect(lambda: [self.invoices_list_page.load_data(), self.pages.setCurrentWidget(self.invoices_list_page)])
        self.comprehensive_btn.clicked.connect(lambda: [self.comprehensive_page.load_data(), self.pages.setCurrentWidget(self.comprehensive_page)])
        self.settings_btn.clicked.connect(lambda: self.pages.setCurrentWidget(self.financial_settings_page))

        self.pages.currentChanged.connect(self.update_nav_active)
        self.update_nav_active(self.pages.currentIndex())

    def update_nav_active(self, index):
        for i, btn in enumerate(self.nav_buttons):
            btn.setChecked(i == index)

    def update_clock(self):
        now = datetime.now()
        jd = jdatetime.date.today()
        self.clock_lbl.setText(to_persian_digits(f"{jd.strftime('%Y/%m/%d')} - {now.strftime('%H:%M:%S')}"))

    def navigate_to_installment(self, tracking_id):
        self.inst_personnel_btn.click()
        self.inst_pers_page.tabs.setCurrentIndex(0)
        self.inst_pers_page.tabs_ui["us"]["search"].setText(tracking_id)

    def navigate_company_to_group(self, comp_name, period_name, inst_num_str):
        self.pages.setCurrentWidget(self.inst_comp_page)
        comp_page = self.inst_comp_page

        for key in ("us", "pasargad", "settled"):
            ui = comp_page.tabs_ui.get(key)
            if not ui: continue
            ui["search"].blockSignals(True); ui["search"].clear(); ui["search"].blockSignals(False)
            if "from" in ui:
                for fkey in ("from", "to"):
                    w = ui[fkey]
                    w.blockSignals(True)
                    w.d.setCurrentIndex(0); w.m.setCurrentIndex(0); w.y.clear()
                    w.blockSignals(False)
            if "status" in ui:
                ui["status"].blockSignals(True); ui["status"].setCurrentIndex(0); ui["status"].blockSignals(False)
            if "inv_status" in ui:
                ui["inv_status"].blockSignals(True); ui["inv_status"].setCurrentIndex(0); ui["inv_status"].blockSignals(False)

        comp_page.load_data()
        for key in ("us", "pasargad", "settled"):
            if key in comp_page.tabs_ui:
                comp_page.apply_filters(key)

        comp_norm = comp_name.replace('\u200c', '').strip()
        for idx, key in enumerate(("us", "pasargad", "settled")):
            ui = comp_page.tabs_ui.get(key)
            if not ui: continue
            tbl = ui["table"]
            for r in range(tbl.rowCount()):
                c_item = tbl.item(r, 0); p_item = tbl.item(r, 1); n_item = tbl.item(r, 2)
                if not (c_item and p_item and n_item): continue
                if (c_item.text().replace('\u200c', '').strip() == comp_norm
                        and en_digits(p_item.text()).replace('\u200c', '').strip() == period_name
                        and en_digits(n_item.text()).strip() == inst_num_str):
                    comp_page.tabs.setCurrentIndex(idx)
                    tbl.clearSelection()
                    tbl.selectRow(r)
                    tbl.scrollToItem(tbl.item(r, 0), QTableWidget.PositionAtCenter)
                    tbl.setFocus()
                    return

        QMessageBox.warning(self, "یافت نشد",
            f"ردیف «{comp_name}» - «{period_name}» - قسط {to_persian_digits(inst_num_str)} در هیچ‌کدام از تب‌ها یافت نشد.")

if __name__ == "__main__":
    setup_directories()
    init_database()
    app = QApplication(sys.argv)
    app.setLayoutDirection(Qt.RightToLeft)
    
    font_path = os.path.join(BASE_DIR, "Font")
    if os.path.exists(os.path.join(font_path, "Vazir-Medium.ttf")): QFontDatabase.addApplicationFont(os.path.join(font_path, "Vazir-Medium.ttf"))
    if os.path.exists(os.path.join(font_path, "Vazir-Bold.ttf")): QFontDatabase.addApplicationFont(os.path.join(font_path, "Vazir-Bold.ttf"))
    QApplication.setFont(QFont(load_settings().get("font_family", "Vazir"), 10))
    
    window = MainWindow()
    window.show()
    sys.exit(app.exec())