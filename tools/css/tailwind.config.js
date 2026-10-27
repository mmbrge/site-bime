// پیکربندیِ ساختِ vendor-css/tailwind.css (همان Tailwind 3.4.17ی که قبلاً از cdn.tailwindcss.com در مرورگر ساخته می‌شد).
// بعد از اضافه کردنِ کلاس‌های Tailwindِ تازه در PHP/JS: bash tools/build-css.sh  و بالا بردنِ ?v= در صفحه‌ها
module.exports = {
  content: {
    relative: true,
    files: ['../../*.php', '../../*.js', '../../api/**/*.php', '../../company-portal/**/*.{php,js}', '../../webapp/**/*.{html,js}',
            '../../errors/**/*.html', '../../tmpl_invoice/**/*.{php,html}'],
  },
  // کلاس‌هایی که با رنگِ متغیر ساخته می‌شوند (مثلِ `bg-${color}-50`)
  safelist: [
    { pattern: /^(bg|text|border|from|to|via|ring)-(slate|gray|zinc|red|rose|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink)-(50|100|200|300|400|500|600|700|800|900)$/ },
  ],
  theme: { extend: { fontFamily: { sans: ['Vazir', 'Tahoma', 'sans-serif'] }, colors: { brand: { dark: '#1e293b', light: '#f8fafc', accent: '#00d2ff' } } } },
};
