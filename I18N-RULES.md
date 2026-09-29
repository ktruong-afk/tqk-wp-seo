# TQK WP SEO — Quy tắc bắt buộc: Đa ngôn ngữ (i18n first)

> GHI NHỚ trước khi tạo bất kỳ file plugin nào: plugin LUÔN đa ngôn ngữ.

1. Mọi string hiển thị cho user PHẢI qua hàm dịch với text-domain duy nhất `tqk-wpseo`:
   `__()`, `_e()`, `esc_html__()`, `esc_attr__()`, `_x()`, `_n()`.
2. `tqk-wpseo.php` đã có `Text Domain: tqk-wpseo`, `Domain Path: /languages` và
   `load_plugin_textdomain('tqk-wpseo', ...)` trên hook `plugins_loaded`.
3. Thư mục `/languages/` chứa `tqk-wpseo.pot` + `tqk-wpseo-vi.po` / `tqk-wpseo-vi.mo` (tên phải có tiền tố `tqk-wpseo-`, nếu không `load_plugin_textdomain` không nạp). Build: `python tools/i18n-build.py` (dịch thêm trong `tools/i18n-vi-extra.json`).
4. Comment code giữ tiếng Anh. Label UI phải dịch được.
5. Check trước khi tạo file PHP mới: text-domain + escape + i18n.
6. DoD mỗi module:
   - [ ] Không còn hard-coded string
   - [ ] `.pot` cập nhật (`wp i18n make-pot`)
   - [ ] Đổi Site Language → UI đổi theo

Ví dụ:

```php
__('SEO Health', 'tqk-wpseo');
esc_html__('Run SEO Scan', 'tqk-wpseo');
printf(esc_html__('Score: %d/100', 'tqk-wpseo'), 86);
```

Chi tiết kỹ thuật đầy đủ: xem `docs/pushed/01-technical-spec.md`
và RAG helper `tqk-wpseo` (http://127.0.0.1:3456).
