#!/usr/bin/env bash
# ساختِ دوباره‌ی vendor-css/tailwind.css از روی کلاس‌هایی که در فایل‌های سایت به کار رفته (نیاز به Node/npm فقط روی سیستمِ توسعه، نه هاست)
set -e
cd "$(dirname "$0")/css"
npx --yes tailwindcss@3.4.17 -c tailwind.config.js -i input.css -o ../../vendor-css/tailwind.css --minify
echo "ok: vendor-css/tailwind.css"
