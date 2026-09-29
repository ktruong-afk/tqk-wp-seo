# TQK WP SEO — Website Audit & Optimization Engine

All-in-one SEO plugin for WordPress that audits every page **like a real visitor**: it opens the page at 4 screen sizes (1440 / 1280 / 768 / 375), takes snapshots, scores the display, measures every image against its real box, checks the real HTML for malware and cloaking, and builds a prioritised action plan. AI apps can connect over MCP; nothing is written without the owner's approval.

Plugin SEO trọn gói cho WordPress: audit từng trang trên 4 màn hình như khách thật, chụp ảnh, chấm điểm hiển thị, đo ảnh theo khung thật, soi mã độc trên HTML thật và lập kế hoạch sửa. Kết nối app AI qua MCP, mọi thay đổi đều phải được duyệt.

- Requirements: WordPress 6.2+, PHP 8.0+
- Docs: [Technical reference (VI + EN)](docs/TECHNICAL.md) · [Giới thiệu](docs/GIOI-THIEU.md)
- Pro features (automatic image optimization, audit of every post) ship as the separate **TQK WP SEO Pro** add-on.

## Development

```
php tests/php/s0-smoke.php        # one suite; run every tests/php/s*.php
python tools/i18n-build.py        # rebuild translations
python tools/build-release.py     # dist/tqk-wpseo-<version>.zip
```

## Author

Trương Quang Khải · 0364 999 916 · [facebook.com/Unk.khai](https://www.facebook.com/Unk.khai/)
