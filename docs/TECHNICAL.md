# TQK WP SEO — Tài liệu kỹ thuật / Technical reference

**Phiên bản / Version:** TQK WP SEO 0.2.0 · TQK WP SEO Pro 0.1.0 · TQK Plugin Doctor 0.1.0
**Tác giả / Author:** Trương Quang Khải · 0364 999 916 · facebook.com/Unk.khai
**Yêu cầu / Requirements:** WordPress 6.2+, PHP 8.0+ (tested on PHP 8.2)

Mỗi mục có phần **Tiếng Việt** trước, **English** sau.
Each section is written in **Vietnamese** first, then **English**.

---

## 0. Bộ sản phẩm / Product family

| Plugin | Vai trò (VI) | Role (EN) | Giấy phép / License |
|---|---|---|---|
| `tqk-wpseo` | SEO trọn gói + Website Audit & Optimization Engine. Mọi chẩn đoán đều miễn phí. | All-in-one SEO + Website Audit & Optimization Engine. All diagnosis is free. | GPL |
| `tqk-wpseo-pro` | Add-on trả phí: tạo file ảnh tối ưu theo từng màn hình, Live Audit "Tất cả". Không chạy nếu thiếu plugin chính. | Paid add-on: per-screen optimized image files, Live Audit "All". Does nothing without the main plugin. | Proprietary |
| `tqk-plugin-doctor` | Plugin độc lập, nhẹ: lưu dấu vân tay mọi file plugin và phát hiện file bị chèn / sửa / xoá. | Standalone lightweight plugin: fingerprints every plugin file and detects injected / modified / deleted files. | GPL |

**Nguyên tắc / Principles**
- VI: Chẩn đoán luôn miễn phí; tự động hoá là Pro. Không bao giờ giấu lỗi để ép mua. Kiểm tra bằng script được gọi là "kiểm tra tự động", chỉ dùng chữ "AI" khi thật sự có AI. AI bên ngoài không được ghi dữ liệu nếu chưa được duyệt.
- EN: Diagnosis is always free; automation is Pro. Findings are never hidden to push a sale. Script checks are labelled "automatic check"; "AI" only means a real AI. Outside AI apps never write without approval.

---

## 1. Bảng điều khiển / Dashboard (`admin.php?page=tqk-wpseo`)

**VI**
- Hero có logo, dòng "Website Audit & Optimization Engine", giới thiệu ngắn và số hỗ trợ.
- 5 thẻ số liệu (trang đã audit, điểm hiển thị, gợi ý AI chờ duyệt, bảo mật…), mỗi thẻ dẫn tới trang liên quan.
- **Việc cần làm của site** + **Sửa nhanh**: các trang đã audit mà thiếu tiêu đề / mô tả / từ khoá / schema được liệt kê để sửa ngay tại chỗ (ô thiếu được tô sáng). Dữ liệu lấy từ báo cáo Live Audit đã lưu.
- **Lỗi toàn site**, **thẻ hướng dẫn** cho từng trang (mô tả + cách dùng + link), checklist thiết lập, thẻ tác giả / donate (ẩn được bằng filter `tqk_wpseo_show_author`).

**EN**
- Hero with logo, "Website Audit & Optimization Engine" kicker, short pitch and support number.
- 5 stat cards (audited pages, display score, AI suggestions awaiting approval, security…), each linking to its page.
- **Site to-do** + **Quick edit**: audited pages missing title / description / keyword / schema are listed with inline fields (missing ones highlighted). Data comes from stored Live Audit reports.
- **Site-wide issues**, one **guide card** per page (what + how + link), setup checklist, author / donate card (hide with the `tqk_wpseo_show_author` filter).

---

## 2. Modules (`tqk-wpseo-modules`)

**VI:** Bật / tắt từng nhóm tính năng: Sitemap & robots.txt, Schema & Breadcrumb, Content Analyzer, Site Scanner, Analytics (Google), Redirects, 404 Monitor, Link Tools, Image SEO, Role Manager, Instant Indexing (tắt mặc định), Local SEO, WooCommerce SEO, Local AI, MCP Tools. Module tắt thì trang của nó biến khỏi menu và code của nó không được nạp.

**EN:** Toggle each feature group (list above). A disabled module's page leaves the menu and its code is not loaded. Option: `tqk_wpseo_modules`.

---

## 3. Analytics website — Live Audit (`tqk-wpseo-site-analysis`)

### 3.1 Phạm vi audit / Audit scope
**VI**
- Bảng phạm vi: **Trang (page)** luôn audit toàn bộ; mỗi post type có 3 lựa chọn: *Bỏ qua* / *1 bài mẫu* (kiểm tra hệ thống SEO + schema của loại bài đó) / *Tất cả* (**Pro**). Taxonomy (trang danh mục, thẻ…) cũng có 3 lựa chọn tương tự.
- Post type có `show_ui = false` (ví dụ `op_block`) bị bỏ qua.
- Ước tính thời gian hiện ngay; ≤ 30 trang thì tự bật kiểm tra bảo mật từng trang.

**EN**
- Scope table: **Pages** are always fully audited; each post type offers *Skip* / *1 sample* / *All* (**Pro**). Taxonomy archives get the same three choices.
- Post types with `show_ui = false` are skipped.
- Time estimate shown live; security check per page auto-enabled for ≤ 30 pages.

### 3.2 Cách chạy / How it runs
**VI**
- Trình duyệt mở từng trang trong khung hiển thị (stage) ở 4 kích thước: **Desktop 1440 · Laptop 1280 · Tablet 768 · Mobile 375**, cuộn trang, chụp ảnh toàn trang (snapshot) và đo từng phần tử.
- Hàng đợi lưu trên server nên đóng tab rồi mở lại vẫn chạy tiếp. ID hàng đợi: số nguyên; `0` = trang chủ; số âm = `-term_id` (trang taxonomy).
- Điểm sau mỗi màn hình (ví dụ "Desktop 1440 · 88") là **điểm hiển thị /100** của màn đó: cỡ chữ, khoảng cách dòng, độ tương phản, tràn ngang, ảnh, bố cục.

**EN**
- The browser loads each page in a visible stage at 4 widths (1440 / 1280 / 768 / 375), scrolls, takes full-page snapshots and measures elements.
- Queue is server-side (resumes after closing the tab). IDs: int; `0` = home; negative = `-term_id` for taxonomy archives.
- The number after each screen is the **display score /100** for that screen (type size, line height, contrast, overflow, images, layout).

### 3.3 Kết quả / Results
**VI** — mỗi trang có:
- Kiểm tra SEO: title, description, canonical, noindex, Open Graph, Twitter, số H1 / H2, nhảy cấp heading, ảnh thiếu alt, số từ, schema.
- Kiểm tra chữ (typeCheck) theo từng màn: chữ chính nhỏ / to quá, heading quá to / nhỏ, line-height, độ dài dòng, tương phản (đề xuất màu đạt WCAG) kèm **CSS sửa sẵn** để copy.
- **Ảnh cần tối ưu**: *Kích thước gốc* · *Khung hiển thị* · *Nên resize về*; nút **PRO** ở cuối: "Tự tối ưu · resize · chuyển WebP".
- **Kế hoạch hành động** (action plan): ưu tiên 1 (sửa ngay) / 2 (nên sửa) / 3 (nên có), gom theo `code`.
- Tuỳ chọn **kiểm tra bảo mật trang** (mục 9).
- Báo cáo đầy đủ lưu dạng JSON (post meta `_tqk_wpseo_scan_report`, term meta cho taxonomy) + lịch sử 10 điểm gần nhất; ảnh chụp lưu trong kho riêng tư (mục 12).
- Nút **Push to MCP** từng dòng và **Push tất cả trang có lỗi**.

**EN** — per page: SEO facts; per-screen typography findings with ready CSS fixes and WCAG-passing colours; images to optimize (original size · rendered box · resize to) with a **PRO** button at the bottom; prioritised action plan (1 fix now / 2 should fix / 3 nice to have); optional page security check; full JSON report + 10-point score history; private snapshots; **Push to MCP** per row and **Push all pages with issues**.

### 3.4 Tối ưu ảnh / Image optimizer
**VI**
- Miễn phí: đo khung thật ở 1440 / 1280 / 768 / 375 (1x và 2x), liệt kê **các bước làm tay** chính xác (kích thước cần xuất, WebP, `srcset` / `sizes`).
- Pro: một cú bấm **Tạo file** → resize, cắt đúng vùng khách thấy (object-fit: cover), nén, chuyển WebP (quality 78; server không hỗ trợ WebP thì giữ định dạng gốc) vào `uploads/audit-pics/{id}-page/` (hoặc `/site/` cho ảnh dùng chung như logo). Kiểm tra xong mới **Kích hoạt**.
- Phục vụ (có trong bản free, nên gỡ Pro không làm hỏng site): qua `wp_calculate_image_srcset` / `_sizes`; ảnh không qua core thì output buffer thêm `srcset`; ảnh cần cắt khác nhau theo màn hình được bọc `<picture>`; ảnh nền dùng stylesheet ghi đè theo media query (selector `#id` hoặc `.elementor-element-*`).
- Ảnh gốc không bao giờ bị sửa. Tắt / xoá file luôn miễn phí.
- Cảnh báo xung đột nếu đang bật LiteSpeed Cache, EWWW, ShortPixel, Imagify, WebP Express, Smush…

**EN**
- Free: real boxes measured at 4 widths (1x/2x) and exact manual steps.
- Pro: one click **Generate** (resize, crop to the visible region, compress, WebP q78) into `uploads/audit-pics/…`, then **Activate** after review.
- Serving lives in the free plugin (removing Pro never breaks a site): core srcset filters, output-buffer fallback, `<picture>` art direction, per-breakpoint background override CSS.
- Originals never modified; deactivate / delete stay free; conflict warning for other optimizers.

---

## 4. Trong trình sửa bài / In the editor (TQK SEO box)

**VI**
- Ngay dưới tab: **Từ khoá chính, Tiêu đề SEO, Mô tả** đặt cạnh **khung xem trước Google** (giống kết quả thật: favicon, breadcrumb, ngày, sitelinks minh hoạ + hướng dẫn), đếm theo pixel, sửa là thấy ngay.
- Checkbox **"Giống tiêu đề trang"** cho tiêu đề SEO; checkbox **"Giống Google"** cho mạng xã hội.
- Xem trước **Facebook, Zalo, X**.
- **Scan trang** 2 chế độ: *Từng phân đoạn* (chậm, viền từng section, kiểm tra từng ảnh: hỏng, thiếu alt, quá lớn ×2.2 / mobile ×3, bị phóng to, thiếu width/height, nặng, chưa WebP, lazy sai chỗ, link rỗng; kết quả hiện ngay cạnh trang) và *Nhanh*.
- Hiện **báo cáo audit gần nhất** của trang (kể cả từ Live Audit / thanh công cụ) với xu hướng điểm.
- **Bộ JSON cho AI**: chọn từng bộ (nội dung, SEO, social, schema) hoặc *Tất cả*; bộ nào cũng mở đầu bằng **nội dung trang dạng chữ thuần** (bỏ tag, bỏ shortcode, tối đa 1000 từ). Dán câu trả lời JSON của AI vào để xem bảng so sánh, duyệt từng dòng rồi mới áp dụng.
- **Kết nối MCP** ngay trong bài: nút PUSH gửi trang (4 ảnh chụp + đánh giá) cho app AI.
- **Gợi ý AI chờ duyệt**: xem lời khuyên, kế hoạch và nội dung đề xuất; duyệt từng trường rồi mới ghi.

**EN**
- Keyword / SEO title / description sit right under the tabs next to a realistic **Google preview** (favicon, breadcrumb, date, sitelinks + guide), pixel counters, live update.
- "Same as page title" and social "Same as Google" checkboxes; **Facebook, Zalo, X** previews.
- **Scan** in two modes: *section by section* (slow, outlines each section, per-image checks, results beside the page) or *quick*.
- Last stored audit of the page with trend.
- **AI JSON sets** (content / SEO / social / schema / all), each starting with plain page text (no tags or shortcodes, max 1000 words); pasted replies open a review table — nothing is applied without approval.
- **MCP push** from the post; **AI suggestions** awaiting approval.

---

## 5. Thanh công cụ ngoài trang / Front-end toolbar

**VI:** Khi xem site (đã đăng nhập) có mục **TQK SEO** trên admin bar: huy hiệu điểm, *Audit trang này (4 màn hình)*, *Kế hoạch gần nhất*, *Sửa SEO trang này*, *Analytics website*. Audit chạy bằng cùng engine với Live Audit, mở kế hoạch trong ngăn bên phải, copy CSS được; bài / trang thì lưu kết quả (trang danh mục chỉ hiển thị, không lưu).

**EN:** The **TQK SEO** admin-bar node offers score badge, *Audit this page (4 screens)*, *Last plan*, *Edit SEO*, *Analytics website*. Same engine; plan opens in a side drawer; results saved for posts/pages (archives are shown, not stored).

---

## 6. Sitemap, Schema, Redirects, 404, Internal links

| Trang / Page | VI | EN |
|---|---|---|
| Sitemap | `sitemap_index.xml` theo post type, sửa robots.txt, `llms.txt`, gửi Google & Bing. | Per-type XML sitemap, robots.txt editor, llms.txt, submit to Google & Bing. |
| Schema | JSON-LD `@graph`: Organization / LocalBusiness, WebSite, Article, Product (bỏ `offers` với sản phẩm "Giá: Liên hệ"), FAQ, HowTo, Recipe, Course, Event…; mẫu theo post type + quy tắc. | JSON-LD graph, per-type templates and rules; products with "contact for price" get no fake offer. |
| Redirects | 301/302/307/410, tự redirect khi đổi slug, CSV, chống vòng lặp và 2 bước. | 301/302/307/410, auto-slug, CSV, loop and double-hop protection. |
| 404 Monitor | Ghi lỗi 404 + số lượt + nguồn, bỏ nhiễu (.map, wp-admin, wp-json), redirect 1 cú bấm. | Logs 404 with hits/referrer, ignores noise, one-click redirect. |
| Internal Links | Sơ đồ link nội bộ, trang mồ côi, link hỏng. | Link graph, orphans, broken links. |

---

## 7. Settings

**VI:** Hồ sơ doanh nghiệp (cho AI), ảnh chia sẻ mặc định, mẫu tiêu đề / mô tả (`%title%`, `%site_name%`, `%excerpt%`), noindex, sitemap, mẫu schema theo post type, nhà cung cấp AI (Ollama / LM Studio / Gemini), **License (Pro)**, thương hiệu báo cáo (white-label), Role Manager, Instant Indexing, **số ngày giữ file audit** (`audit_keep_days` 1/7/30, mặc định 1), **cho phép MCP ghi trực tiếp** (`mcp_direct_write`, mặc định tắt).

**EN:** Business profile, default share image, templates, indexability, sitemap, schema templates, AI provider, **Pro license box** (provided by the add-on when installed), report branding, roles, instant indexing, `audit_keep_days` (1/7/30, default 1), `mcp_direct_write` (default off).

---

## 8. Tools — Nhập dữ liệu, MCP / Import, MCP

**VI**
- **Nhập từ Yoast SEO / Rank Math** (`src/Tools/Importer.php`): tiêu đề, mô tả, từ khoá (Rank Math: từ khoá đầu = chính, còn lại = phụ), canonical, robots, Facebook / X, loại schema, SEO danh mục / thẻ, redirect; biến `%%title%%` / `%title%` được đổi sang mẫu của TQK. Mặc định chỉ điền ô trống.
- **Kết nối MCP**: endpoint, token, cấu hình sẵn cho Claude Code / Claude Desktop / Cursor.
- Xuất cài đặt, reset.

**EN:** Import from Yoast SEO and Rank Math (fills empty fields by default, optional overwrite); MCP card with endpoint, token and ready configs; settings export / reset.

### 8.1 MCP server
- Endpoint: `POST /wp-json/tqk-wpseo/v1/mcp` (Streamable HTTP, JSON-RPC 2.0). Auth: header `X-TQK-Token` or `Authorization: Bearer`. Notifications → HTTP 202.
- Methods: `initialize`, `ping`, `tools/list`, `tools/call`, `prompts/list`, `prompts/get`.
- Tools: `list_pushed_pages`, `get_page_report` (text + JPEG tiles, max 3 per screen, 2000 px), `get_page_content`, `submit_suggestions`, `apply_seo_fields` (becomes a suggestion unless `mcp_direct_write` is on), `mark_page_done`, `get_schema`, `list_orphan_pages`, `list_404`, `create_redirect`.
- Prompt: `review_page`.
- VI: App AI chỉ **đề xuất**; chủ site duyệt trong trình sửa bài hoặc ở Analytics website.

---

## 9. Bảo mật / Security

**VI**
- **Security scan** (trang riêng): so file core và plugin với checksum chính thức của WordPress.org, dò backdoor (eval + giải mã, assert / shell từ dữ liệu gửi lên, tạo admin trong code, dấu hiệu bản "nulled"…), file PHP trong uploads, link spam ẩn (cá cược, nổ hũ…), **cloaking** (Googlebot thấy trang khác khách). Chạy từng bước nhỏ, chỉ báo cáo, không xoá.
- **Kiểm tra trang thật** (PageCheck, trong audit): tải trang dưới 4 góc nhìn — admin, khách, khách đến từ Google (Referer), Googlebot — rồi so sánh. Phân loại từng script theo nguồn: plugin / theme / core / uploads (đáng ngờ) / đã biết / xấu / lạ; soi script inline và iframe ẩn. Kết luận: *sạch / cần xem / nhiễm*.

**EN**
- **Security scan**: core + plugin checksums from WordPress.org, backdoor signatures, PHP in uploads, hidden spam links, cloaking; stepwise, report-only.
- **Real page check** during audits: fetches the page as admin, visitor, Google-referred visitor and Googlebot, diffs them, classifies every script origin, flags inline scripts and hidden iframes; verdict clean / review / infected.

---

## 10. Dành cho thị trường Việt Nam / Vietnam-specific

- VI: Xem trước **Zalo**; kiểm tra địa chỉ theo **sắp xếp tỉnh 2025** (ví dụ Bình Dương → TP. Hồ Chí Minh, địa chỉ còn cấp huyện bị nhắc); schema sản phẩm "Giá: Liên hệ" không bịa giá; từ điển spam tiếng Việt; giao diện tiếng Việt đầy đủ (1087 chuỗi).
- EN: Zalo preview; 2025 province-merger address checks; honest schema for "contact for price" products; Vietnamese spam dictionary; full Vietnamese UI.

---

## 11. Bản Pro (add-on) / Pro add-on

**VI**
- Cài plugin `tqk-wpseo-pro` bên cạnh plugin chính → nhập license ở Settings → License.
- Bản free **không chứa mã Pro**. Plugin chính chỉ có "chỗ cắm" `TQK_WPSEO\Core\Pro`:
  - `Pro::register(ProAddon)` — add-on tự đăng ký trên `plugins_loaded`.
  - `Pro::active()` = đã cài add-on **và** license hợp lệ; filter `tqk_wpseo_is_pro` chỉ tắt được Pro, không bật được.
  - Tạo file ảnh qua filter `tqk_wpseo_image_generate`; ajax license qua action `tqk_wpseo_ajax_license`.
- License: kích hoạt / kiểm tra mỗi ngày, gia hạn 14 ngày nếu server license không liên lạc được, hết hạn / bị thu hồi → về free (file đã tạo vẫn được phục vụ).
- Mã nguồn trang của site Pro có dòng `<!-- TQK WP SEO PRO v… · fingerprint -->`; fingerprint = HMAC(tên miền, key), 12 ký tự, không lộ key.
- Server license: khai báo `define('TQK_WPSEO_LICENSE_API', 'https://…')`; API nhận `POST {action, key, site, version}` và trả `{valid, expires, message}`.

**EN**
- Install `tqk-wpseo-pro` next to the main plugin, enter the key in Settings → License.
- The free plugin holds **no Pro code**; it exposes `TQK_WPSEO\Core\Pro` (register / active / banner / settings_html), the `tqk_wpseo_image_generate` filter and the `tqk_wpseo_ajax_{name}` routes.
- License: daily check, 14-day offline grace, expiry → free (generated files keep being served). Page-source fingerprint = HMAC(host, key).

---

## 12. Dữ liệu & quyền riêng tư / Data & privacy

| Dữ liệu / Data | Nơi lưu / Where |
|---|---|
| Báo cáo trang / Page report | post meta `_tqk_wpseo_scan_report` (term meta cho taxonomy) |
| Ảnh chụp, JSON audit / Snapshots, audit JSON | `uploads/tqk-wpseo-private-{24 hex}/` — chặn bằng `.htaccess`, `web.config`, `index.php`; chỉ đọc qua admin-ajax `tqk_wpseo_file` (nonce + quyền `edit_posts`, `no-store`) |
| Dọn dẹp / Pruning | cron hằng ngày + lần mở màn hình plugin đầu tiên trong ngày, theo `audit_keep_days` |
| Hàng chờ MCP / MCP inbox | option `tqk_wpseo_mcp_inbox` |
| Gợi ý AI / AI suggestions | post meta `_tqk_wpseo_ai_suggestion`, option `tqk_wpseo_ai_pending` |
| License (Pro) | option `tqk_wpseo_license` (key chỉ hiện 4 ký tự cuối / only last 4 shown) |

Gỡ plugin (uninstall) xoá các thư mục `tqk-wpseo-private-*`. / Uninstall removes the private folders.

---

## 13. TQK Plugin Doctor

**VI**
- Menu: **Công cụ → Plugin Doctor** (quyền `manage_options`).
- **Lưu bản gốc**: SHA-256 + dung lượng của từng file trong từng plugin (kể cả plugin chưa bật và mu-plugins), bỏ qua `.git`. Tối đa 30.000 file / plugin. Lưu trong `uploads/tqk-plugin-doctor-{24 hex}/` (bị chặn truy cập trực tiếp).
- **So sánh**: liệt kê file **mới / bị sửa / bị xoá**. File PHP / JS mới hoặc bị sửa được dò mẫu backdoor. Trạng thái: *Chưa có bản gốc*, *Không đổi*, *Phiên bản mới* (đổi version, không có mã xấu: thường là cập nhật bình thường), *File bị thay đổi*, *Nghi mã độc*.
- **Tải về**: toàn bộ bản gốc dạng `.json` (cất ngoài site, nhập lại khi cần so với bản kẻ tấn công không sửa được) và **bản sao .zip** từng plugin kèm `doctor-manifest.json`. **Nhập bản gốc** từ file .json (kiểm tra hợp lệ: slug không có `..` / `/`, hash đúng 64 ký tự hex).
- **Tự động**: so sánh mỗi ngày (cron `tqk_pd_daily`), thông báo đỏ trong admin nếu có thay đổi; cập nhật plugin qua WordPress thì bản gốc tự cập nhật (tắt được).
- Chạy từng plugin một request nên host yếu không bị timeout. Kiểm tra trên site test: 19 plugin, 10.908 file.

**EN**
- **Tools → Plugin Doctor**. Baseline = SHA-256 + size of every file of every plugin (incl. inactive and mu-plugins), stored privately.
- Compare lists new / modified / deleted files; changed PHP/JS is scanned for backdoor patterns. Statuses: no baseline, unchanged, new version, files changed, looks like malware.
- Download all baselines (.json) or a .zip copy of a plugin with its manifest; import baselines (validated).
- Daily compare with admin notice; baselines refresh automatically after updates made through WordPress (optional). One request per plugin.

---

## 14. Phát triển / Development

```
php tests/php/s*.php                         # 26 bộ test của plugin chính / main plugin suites
php ../tqk-wpseo-pro/tests/test-pro.php      # add-on Pro
php ../tqk-plugin-doctor/tests/test-doctor.php
python tools/i18n-build.py                   # dịch / translations (quét cả add-on Pro)
python tools/build-release.py                # dist/tqk-wpseo-<v>.zip + dist/tqk-wpseo-pro-<v>.zip
python ../tqk-plugin-doctor/tools/build.py   # dist/tqk-plugin-doctor-<v>.zip
```

Chuỗi dịch mới: thêm vào `tools/i18n-vi-*.json` (plugin chính + Pro) hoặc `tqk-plugin-doctor/tools/vi.json`.
New strings: add to `tools/i18n-vi-*.json` (main + Pro) or `tqk-plugin-doctor/tools/vi.json`.
