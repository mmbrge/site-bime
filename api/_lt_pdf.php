<?php
// فایل: api/_lt_pdf.php
// کلاسِ PDFِ نامه‌ها (فقط وقتی PDF ساخته می‌شود بارگذاری می‌شود): سربرگ پشتِ صفحه و پانویس/شماره‌ی صفحه
// پوشه‌ی موقتِ جدا برای TCPDF (TCPDF موقعِ پاک‌سازی هر تصویری را که مسیرش با این پوشه شروع شود پاک می‌کند)
if (!defined('K_PATH_CACHE')) {
    $ltCache = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/';
    if (!is_dir($ltCache)) @mkdir($ltCache, 0775, true);
    define('K_PATH_CACHE', is_dir($ltCache) ? $ltCache : rtrim(sys_get_temp_dir(), '/') . '/');
}
require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
class LtPdf extends TCPDF {
    public $ltBg = null; public $ltBgFirst = false; public $ltFooter = ''; public $ltPageNo = true;
    public function Header() {
        if ($this->ltBg && (!$this->ltBgFirst || $this->getPage() === 1)) {
            $bm = $this->getBreakMargin(); $auto = $this->getAutoPageBreak();
            $this->SetAutoPageBreak(false, 0);
            $this->Image($this->ltBg, 0, 0, 210, 297, '', '', '', false, 200, '', false, false, 0);
            $this->SetAutoPageBreak($auto, $bm);
            $this->setPageMark();
        }
    }
    public function Footer() {
        $this->SetFont('vazir', '', 8);
        $this->SetTextColor(100, 116, 139);
        if ($this->ltFooter !== '') { $this->SetXY(15, 287); $this->setRTL(true); $this->Cell(180, 5, $this->ltFooter, 0, 0, 'C'); $this->setRTL(false); }
        if ($this->ltPageNo) { $this->SetXY(15, 291); $this->setRTL(true); $this->Cell(180, 4, 'صفحه‌ی ' . lt_fa($this->getPage()), 0, 0, 'C'); $this->setRTL(false); }
    }
}
