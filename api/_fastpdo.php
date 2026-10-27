<?php
// فایل: api/_fastpdo.php
// PDO با «حافظه‌ی ساختارِ دیتابیس»: کدهای سایت در هر درخواست ده‌ها بار می‌پرسند «این ستون/جدول هست؟»
// (SHOW COLUMNS … LIKE، CREATE TABLE IF NOT EXISTS، SELECT ستون … LIMIT 0) تا اگر نبود خودکار بسازند.
// جوابِ «هست» یک بار از دیتابیس خوانده و داخلِ خودِ دیتابیس (system_settings: schema_cache_v1) نگه داشته می‌شود؛
// درخواست‌های بعدی به‌جای ده‌ها پرس‌وجو فقط یک ردیف می‌خوانند.
//  - جوابِ «نیست» هیچ‌وقت نگه داشته نمی‌شود (پس ساختنِ خودکار مثلِ قبل انجام می‌شود)
//  - با هر ALTER/DROP/RENAME روی یک جدول، حافظه‌ی همان جدول پاک می‌شود؛ با بازگردانیِ پشتیبان همه‌ی حافظه
//  - هر ورودی حداکثر ۱۲ ساعت معتبر است
class SitePDO extends PDO {
    private $sc = null;          // ['k' => [at, value]]
    private $scDirty = false;
    const SC_KEY = 'schema_cache_v1';
    const SC_TTL = 43200;

    private function scLoad() {
        if ($this->sc !== null) return;
        $this->sc = [];
        try {
            $st = parent::prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $st->execute([self::SC_KEY]);
            $j = json_decode((string)$st->fetchColumn(), true);
            if (is_array($j)) { $now = time(); foreach ($j as $k => $v) if (is_array($v) && ($v[0] ?? 0) > $now - self::SC_TTL) $this->sc[$k] = $v; }
        } catch (Throwable $e) {}
        register_shutdown_function([$this, 'scSave']);
    }
    public function scSave() {
        if (!$this->scDirty) return;
        $this->scDirty = false;
        try {
            if ($this->inTransaction()) return;
            parent::prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([self::SC_KEY, json_encode($this->sc, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {}
    }
    private function scGet($k) { $this->scLoad(); return isset($this->sc[$k]) ? $this->sc[$k][1] : null; }
    private function scPut($k, $v) { $this->scLoad(); $this->sc[$k] = [time(), $v]; $this->scDirty = true; }
    private function scDropTable($t) {
        $this->scLoad();
        $t = strtolower($t);
        foreach (array_keys($this->sc) as $k) if (strpos($k, '|' . $t . '|') !== false) { unset($this->sc[$k]); $this->scDirty = true; }
    }
    public function scClear() { $this->sc = []; $this->scDirty = true; }

    private static function tname($s) { return strtolower(trim($s, "` \t")); }

    #[\ReturnTypeWillChange]
    public function query($statement, $mode = null, ...$args) {
        $sql = trim((string)$statement);
        // SHOW COLUMNS FROM t LIKE 'c'  /  SHOW COLUMNS FROM t
        if (stripos($sql, 'SHOW COLUMNS FROM') === 0 && preg_match('/^SHOW\s+COLUMNS\s+FROM\s+(`?[A-Za-z0-9_]+`?)(?:\s+LIKE\s+\'([^\']*)\')?\s*;?$/i', $sql, $m)) {
            $k = 'sc|' . self::tname($m[1]) . '|' . strtolower($m[2] ?? '*');
            $hit = $this->scGet($k);
            if ($hit !== null) return new SitePDOCached($hit);
            $st = $mode === null ? parent::query($statement) : parent::query($statement, $mode, ...$args);
            if ($st && $mode === null) {
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if ($rows) $this->scPut($k, $rows);   // فقط «هست»
                return new SitePDOCached($rows);
            }
            return $st;
        }
        // SELECT col FROM t LIMIT 0 (پرسیدنِ وجودِ ستون)
        if (stripos($sql, 'SELECT') === 0 && preg_match('/^SELECT\s+[`A-Za-z0-9_,\s]+\s+FROM\s+(`?[A-Za-z0-9_]+`?)\s+LIMIT\s+0\s*$/i', $sql, $m)) {
            $k = 'l0|' . self::tname($m[1]) . '|' . md5(strtolower($sql));
            if ($this->scGet($k) !== null) return new SitePDOCached([]);
            $st = $mode === null ? parent::query($statement) : parent::query($statement, $mode, ...$args);
            if ($st) $this->scPut($k, 1);
            return $st;
        }
        return $mode === null ? parent::query($statement) : parent::query($statement, $mode, ...$args);
    }

    #[\ReturnTypeWillChange]
    public function exec($statement) {
        $sql = ltrim((string)$statement);
        if (stripos($sql, 'CREATE TABLE IF NOT EXISTS') === 0 && preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+(`?[A-Za-z0-9_]+`?)/i', $sql, $m)) {
            $k = 'ct|' . self::tname($m[1]) . '|';
            if ($this->scGet($k) !== null) return 0;
            $r = parent::exec($statement);
            $this->scPut($k, 1);
            return $r;
        }
        if (preg_match('/^(ALTER|DROP|RENAME|TRUNCATE)\s+TABLE\s+(?:IF\s+EXISTS\s+)?(`?[A-Za-z0-9_]+`?)/i', $sql, $m)) {
            try { return parent::exec($statement); } finally { $this->scDropTable(self::tname($m[2])); }
        }
        return parent::exec($statement);
    }
}

// نتیجه‌ی نگه‌داشته‌شده، با همان رفتارِ PDOStatement برای fetch/fetchColumn/fetchAll/foreach
class SitePDOCached implements IteratorAggregate {
    private $rows; private $i = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch($mode = null, ...$a) {
        if (!isset($this->rows[$this->i])) return false;
        $r = $this->rows[$this->i++];
        return $mode === PDO::FETCH_NUM ? array_values($r) : ($mode === PDO::FETCH_BOTH ? array_merge($r, array_values($r)) : $r);
    }
    public function fetchColumn($col = 0) { $r = $this->fetch(PDO::FETCH_NUM); return $r === false ? false : ($r[$col] ?? false); }
    public function fetchAll($mode = null, ...$a) {
        $out = [];
        while (($r = $this->fetch($mode === PDO::FETCH_COLUMN ? PDO::FETCH_NUM : $mode)) !== false) $out[] = $mode === PDO::FETCH_COLUMN ? ($r[$a[0] ?? 0] ?? null) : $r;
        return $out;
    }
    public function rowCount() { return count($this->rows); }
    public function columnCount() { return $this->rows ? count($this->rows[0]) : 0; }
    public function closeCursor() { return true; }
    public function setFetchMode(...$a) { return true; }
    #[\ReturnTypeWillChange]
    public function getIterator() { return new ArrayIterator(array_slice($this->rows, $this->i)); }
}
