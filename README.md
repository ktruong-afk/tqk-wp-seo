# TQK WP SEO — Website Audit & Optimization Engine

**[English](#english) · [Tiếng Việt](#tiếng-việt)**

WordPress 6.2+ · PHP 8.0+ · Author: **Trương Quang Khải** · 📞 0364 999 916 · [facebook.com/Unk.khai](https://www.facebook.com/Unk.khai/)

---

<a id="english"></a>
# 🇬🇧 English

### The first SEO plugin that looks at your website **like a real visitor**, on 4 screens.

Yoast SEO and Rank Math are great at filling in meta tags. But they only read **the input boxes in wp-admin**; they never open your website. Customers and Google don't read input boxes: they see **the real page**, on **a real phone**.

**TQK WP SEO does exactly that.**

## Why another SEO plugin?

| What you really need | Yoast SEO | Rank Math | **TQK WP SEO** |
|---|:-:|:-:|:-:|
| Title, description, sitemap, schema, redirects, 404 | ✔ | ✔ | ✔ |
| Import from Yoast / Rank Math | — | ✔ | ✔ |
| **Open the real page at 4 screen sizes (1440 / 1280 / 768 / 375) and take snapshots** | ✘ | ✘ | ✔ |
| **Display score per screen**: text too small on phones, headings too big, poor contrast, horizontal overflow | ✘ | ✘ | ✔ |
| **Ready-to-copy CSS fixes**, WCAG-passing colours | ✘ | ✘ | ✔ |
| **Prioritised action plan** for every page | ✘ | ✘ | ✔ |
| **Images measured against their real box** on each screen: original size, rendered box, what to resize to | ✘ | ✘ | ✔ |
| **Malware check on the real HTML**: visitors see one page, Google sees another (cloaking), unknown scripts, hidden iframes | ✘ | ✘ | ✔ |
| **Connect AI apps over MCP** (Claude, Cursor…) for suggestions, **you approve before anything is applied** | ✘ | ✘ | ✔ |
| **Zalo** share preview | ✘ | ✘ | ✔ |
| Address check for Vietnam's **2025 province merger** | ✘ | ✘ | ✔ |
| Correct schema for **"contact for price"** products | ✘ | ✘ | ✔ |

> Comparison based on the default, publicly listed features of each plugin at the time of writing. TQK WP SEO does not replace good content; it shows you **exactly** what to fix, where, and on which screen.

## 1. Live Audit: see your site like a real visitor

Press **Start** and the plugin opens every page, scrolls to the bottom and takes snapshots at **4 screen sizes**, then scores each screen.

- Easy scope: **all pages**, **one sample post** per post type (enough to know whether that type's SEO and schema are right), or **every post** (Pro).
- Includes **category / tag / product category archives**, which other plugins often forget.
- Closed the tab halfway? The queue carries on.
- Each page gets a **full report**: 4 snapshots, scores, SEO issues (H1, H2, alt, OG, schema…) and an **action plan**: *fix now*, *should fix*, *nice to have*.

**No more taking screenshots by hand to send to an AI or a developer.** The plugin does it all.

## 2. Images: the #1 reason sites are slow on phones

A 3000px image shown in a 343px box wastes almost 90% of its weight. TQK WP SEO **measures the real box** of every image on every screen and says:

> **Original:** 3000 × 2000 · **Box:** 343 × 229 (mobile) · **Resize to:** 750px (2x) · WebP

- **Free:** exact manual steps (sizes to export, WebP, `srcset` / `sizes`).
- **Pro:** one click **resizes, crops to the region visitors actually see, compresses, converts to WebP**, and serves it through `srcset` per screen. Works with any builder: Gutenberg, Elementor, Flatsome, WooCommerce. **Your original uploads are never touched.** Turn Pro off and the optimized images keep working.

## 3. Security: malware checked on the page visitors actually get

A hacked site rarely goes down. It **quietly adds gambling links**, redirects visitors coming from Google, or shows Googlebot a different page, while the owner, logged in, sees nothing wrong.

TQK WP SEO fetches the page from **4 points of view**: admin, visitor, visitor coming from Google, and Googlebot. It then compares them. Every script is classified by origin (plugin, theme, core, **uploads folder, very suspicious**, unknown domain). The verdict is clear: **Clean / Review / Infected**.

Plus a **Security scan** page: files checked against official WordPress.org checksums, backdoor patterns, hidden spam links. It only reports; it never deletes anything on its own.

## 4. AI helps, **you** decide

- **AI JSON sets**: send small, focused packs to ChatGPT or Gemini (even "Ask Gemini" in Chrome). Each set includes **the page content as plain text** (max 1000 words) so the AI understands the article.
- **MCP**: connect Claude Desktop / Claude Code / Cursor directly. Press **PUSH** and the AI app receives the 4 snapshots and the full evaluation, then sends back **advice, a plan and proposed content**.
- **Nothing is written until you approve.** You see a "current ↔ proposed" table, tick the rows you want, then apply.

## 5. Faster everyday SEO

- A **Google preview that looks like the real result**, with example sitelinks and a guide. Keyword, title and description sit right beside it: type and see.
- One tick: **SEO title = page title**, **social = Google**.
- **Facebook, Zalo and X** previews.
- **Dashboard quick edit**: pages missing a title, description, keyword or schema are listed and fixed right there.
- An **Audit this page** button in the toolbar while you browse your site.

## 6. Switch from Yoast / Rank Math in minutes

**Tools → Import from Yoast SEO / Rank Math**: titles, descriptions, keywords, canonical, robots, Facebook/X, schema, category SEO and redirects. Variables like `%%title%%` / `%title%` are converted. By default it **only fills empty fields**; your existing data is kept.

## 7. Built for Vietnam

- Full Vietnamese interface.
- **Zalo** preview.
- Address reminders for the **2025 province merger** (e.g. Bình Dương → Ho Chi Minh City).
- **"Contact for price"** products get no fake price in schema (avoids Google warnings).
- Vietnamese spam-link dictionary (gambling, lottery terms…).
- Direct support in Vietnamese by phone.

## 8. Bonus: **TQK Plugin Doctor**

A small standalone plugin that gives your plugins a health check:
- Saves a **fingerprint** (SHA-256) of **every file** of **every plugin** as a baseline.
- Compares every day: **new, modified or deleted files** you never touched are the typical trace of injected malware.
- Download the baseline (.json) or a .zip copy of a plugin to keep outside the site.
- Plugin updates made through WordPress refresh the baseline automatically, so there are no false alarms.

## Free vs Pro

| | **Free** | **Pro** |
|---|:-:|:-:|
| All SEO: meta, sitemap, schema, redirects, 404, internal links, Yoast / Rank Math import | ✔ | ✔ |
| Live Audit on 4 screens + snapshots + action plan | ✔ | ✔ |
| Every page + one sample per post type / taxonomy | ✔ | ✔ |
| Audit of **every** post, product and archive | — | ✔ |
| Image measurements + manual optimization steps | ✔ | ✔ |
| **Automatic** resize · crop · compress · WebP · `srcset` per screen | — | ✔ |
| Security scan, cloaking check, MCP, AI (approval before applying) | ✔ | ✔ |
| Priority support | — | ✔ |

**Promise:** the free version **never hides a problem** to push you to buy. Every issue is shown. Pro just **does the time-consuming work for you**.

---

<a id="tiếng-việt"></a>
# 🇻🇳 Tiếng Việt

### Plugin SEO đầu tiên xem website của bạn **như một khách hàng thật**, trên 4 màn hình.

Yoast SEO và Rank Math rất mạnh ở phần "điền thẻ meta". Nhưng chúng chỉ đọc **ô nhập liệu trong trang quản trị**, không bao giờ mở website ra xem. Khách hàng và Google thì không đọc ô nhập liệu: họ xem **trang thật**, trên **điện thoại thật**.

**TQK WP SEO làm đúng điều đó.**

## Vì sao cần một plugin SEO khác?

| Việc bạn thật sự cần | Yoast SEO | Rank Math | **TQK WP SEO** |
|---|:-:|:-:|:-:|
| Tiêu đề, mô tả, sitemap, schema, redirect, 404 | ✔ | ✔ | ✔ |
| Nhập dữ liệu từ Yoast / Rank Math | — | ✔ | ✔ |
| **Mở trang thật ở 4 màn hình (1440 / 1280 / 768 / 375) và chụp ảnh** | ✘ | ✘ | ✔ |
| **Chấm điểm hiển thị từng màn**: chữ quá nhỏ trên điện thoại, heading quá to, tương phản kém, tràn ngang | ✘ | ✘ | ✔ |
| **CSS sửa sẵn** để copy, màu đạt chuẩn WCAG | ✘ | ✘ | ✔ |
| **Kế hoạch hành động** xếp theo mức ưu tiên cho từng trang | ✘ | ✘ | ✔ |
| **Đo ảnh theo khung thật** của từng màn hình: ảnh gốc bao nhiêu, khung hiển thị bao nhiêu, nên resize về bao nhiêu | ✘ | ✘ | ✔ |
| **Quét mã độc trên HTML thật**: khách thấy một kiểu, Google thấy một kiểu (cloaking), script lạ, iframe ẩn | ✘ | ✘ | ✔ |
| **Kết nối app AI qua MCP** (Claude, Cursor…) để xin gợi ý, **bạn duyệt trước rồi mới áp dụng** | ✘ | ✘ | ✔ |
| Xem trước khi chia sẻ lên **Zalo** | ✘ | ✘ | ✔ |
| Kiểm tra địa chỉ theo **sắp xếp tỉnh thành 2025** | ✘ | ✘ | ✔ |
| Schema đúng cho sản phẩm **"Giá: Liên hệ"** | ✘ | ✘ | ✔ |

> Bảng so sánh dựa trên tính năng mặc định công bố của các plugin tại thời điểm viết. TQK WP SEO không thay thế công sức làm nội dung. Nó cho bạn thấy **chính xác** cần sửa gì, ở đâu, trên màn hình nào.

## 1. Live Audit: xem website như khách hàng thật

Bấm **Bắt đầu**, plugin tự mở từng trang, cuộn hết trang và chụp ảnh ở **4 kích thước màn hình**. Sau đó nó chấm điểm từng màn.

- Chọn phạm vi dễ dàng: **tất cả trang**, mỗi loại bài chỉ **1 bài mẫu** (đủ để biết hệ thống SEO và schema đã chuẩn chưa), hoặc **toàn bộ bài viết** (Pro).
- Có cả **trang danh mục / thẻ / danh mục sản phẩm**, nơi các plugin khác hay bỏ quên.
- Đóng tab giữa chừng? Không sao, hàng đợi tự chạy tiếp.
- Mỗi trang nhận một **báo cáo đầy đủ**: ảnh chụp 4 màn, điểm, lỗi SEO (H1, H2, alt, OG, schema…) và **kế hoạch sửa**: *sửa ngay*, *nên sửa*, *nên có*.

**Không cần tự chụp màn hình rồi gửi cho AI hay cho dev nữa.** Plugin làm hết.

## 2. Ảnh: nguyên nhân số 1 làm web chậm trên điện thoại

Một ảnh 3000px hiển thị trong khung 343px là lãng phí gần 90% dung lượng. TQK WP SEO **đo khung thật** của từng ảnh trên từng màn hình và ghi rõ:

> **Ảnh gốc:** 3000 × 2000 · **Khung:** 343 × 229 (mobile) · **Nên resize về:** 750px (2x) · WebP

- **Bản miễn phí:** hướng dẫn từng bước làm tay (kích thước cần xuất, WebP, `srcset`/`sizes`).
- **Bản Pro:** một cú bấm là tự **resize, cắt đúng vùng khách thấy, nén, chuyển WebP**, rồi phục vụ qua `srcset` theo từng màn hình. Chạy được với mọi builder: Gutenberg, Elementor, Flatsome, WooCommerce. **Không bao giờ đụng vào ảnh gốc.** Tắt Pro thì ảnh đã tối ưu vẫn chạy bình thường.

## 3. An toàn: soi mã độc trên chính trang khách đang xem

Website bị hack thường không "sập" mà **âm thầm chèn link cá cược**, chuyển hướng khách đến từ Google, hoặc cho Googlebot xem một trang khác. Chủ site đăng nhập thì thấy mọi thứ bình thường.

TQK WP SEO tải trang dưới **4 góc nhìn**: quản trị viên, khách, khách đến từ Google, Googlebot. Sau đó nó so sánh 4 kết quả. Mọi script được phân loại theo nguồn (plugin, theme, core, **thư mục uploads, rất đáng ngờ**, domain lạ). Kết luận rõ ràng: **Sạch / Cần xem / Nhiễm**.

Kèm theo là trang **Security scan** so file với checksum chính thức của WordPress.org, dò backdoor và link spam ẩn. Nó chỉ báo cáo, không tự xoá lung tung.

## 4. AI giúp, nhưng **bạn** quyết định

- **Bộ JSON cho AI**: gửi cho ChatGPT, Gemini (kể cả "Ask Gemini" trên Chrome) từng bộ gọn nhẹ. Bộ nào cũng kèm **nội dung trang dạng chữ thuần** (tối đa 1000 từ) để AI hiểu đúng bài.
- **MCP**: kết nối thẳng Claude Desktop / Claude Code / Cursor. Bấm **PUSH**, app AI nhận 4 ảnh chụp và toàn bộ đánh giá, rồi gửi lại **lời khuyên, kế hoạch và nội dung đề xuất**.
- **Không gì được ghi khi bạn chưa duyệt.** Bạn xem bảng so sánh "hiện tại ↔ đề xuất", tích từng dòng, rồi mới áp dụng.

## 5. Làm SEO hằng ngày nhanh hơn

- Khung xem trước **Google giống thật**, với sitelinks minh hoạ và hướng dẫn. Ô từ khoá, tiêu đề, mô tả nằm ngay cạnh: gõ là thấy.
- Một cú tích: **tiêu đề SEO = tiêu đề trang**, **mạng xã hội = Google**.
- Xem trước **Facebook, Zalo, X**.
- **Dashboard sửa nhanh**: trang nào thiếu tiêu đề, mô tả, từ khoá, schema thì hiện ra và sửa ngay tại chỗ.
- Nút **Audit trang này** ngay trên thanh công cụ khi bạn đang xem website.

## 6. Chuyển từ Yoast / Rank Math trong vài phút

**Công cụ → Nhập từ Yoast SEO / Rank Math**. Tiêu đề, mô tả, từ khoá, canonical, robots, Facebook/X, schema, SEO danh mục, redirect đều được chuyển. Biến `%%title%%` / `%title%` được đổi tự động. Mặc định **chỉ điền ô trống**, dữ liệu sẵn có được giữ nguyên.

## 7. Làm cho người Việt

- Giao diện tiếng Việt đầy đủ.
- Xem trước **Zalo**.
- Nhắc sửa địa chỉ theo **sắp xếp tỉnh thành 2025** (ví dụ Bình Dương → TP. Hồ Chí Minh).
- Sản phẩm **"Giá: Liên hệ"** không bị bịa giá trong schema (tránh cảnh báo của Google).
- Từ điển link spam tiếng Việt (nổ hũ, tài xỉu, lô đề…).
- Hỗ trợ trực tiếp bằng tiếng Việt qua điện thoại.

## 8. Tặng kèm: **TQK Plugin Doctor**

Plugin nhỏ, độc lập, chuyên "khám sức khoẻ" cho plugin:
- Lưu **dấu vân tay** (SHA-256) của **từng file** trong **từng plugin** làm bản gốc.
- Mỗi ngày tự so sánh: **file mới, file bị sửa, file bị xoá** mà bạn không hề làm là dấu vết điển hình của mã độc bị chèn vào.
- Tải bản gốc (.json) hoặc bản sao .zip của plugin để cất ngoài website.
- Cập nhật plugin qua WordPress thì bản gốc tự cập nhật, không báo nhầm.

## Miễn phí và Pro

| | **Miễn phí** | **Pro** |
|---|:-:|:-:|
| Toàn bộ SEO: meta, sitemap, schema, redirect, 404, link nội bộ, nhập Yoast / Rank Math | ✔ | ✔ |
| Live Audit 4 màn hình + ảnh chụp + kế hoạch sửa | ✔ | ✔ |
| Mọi trang (page) + 1 bài mẫu mỗi loại bài / danh mục | ✔ | ✔ |
| Audit **toàn bộ** bài viết, sản phẩm, danh mục | — | ✔ |
| Đo ảnh + hướng dẫn tối ưu làm tay | ✔ | ✔ |
| **Tự động** resize · cắt · nén · WebP · `srcset` theo màn hình | — | ✔ |
| Quét bảo mật, kiểm tra cloaking, MCP, AI (duyệt trước khi áp dụng) | ✔ | ✔ |
| Hỗ trợ ưu tiên | — | ✔ |

**Cam kết:** bản miễn phí **không giấu lỗi** nào để ép bạn mua. Mọi vấn đề đều được chỉ ra. Pro chỉ giúp bạn **tự động làm** phần việc tốn thời gian.

---

## Installation / Cài đặt

**EN**
1. Click **Code → Download ZIP** on this page.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, then **Activate**.
3. Open **TQK SEO** in the admin menu. The setup wizard guides you through the first steps.

**VI**
1. Bấm **Code → Download ZIP** trên trang này.
2. Trong WordPress: **Plugin → Cài mới → Tải plugin lên**, chọn file zip rồi bấm **Kích hoạt**.
3. Mở menu **TQK SEO**, trình hướng dẫn cài đặt sẽ dẫn bạn qua các bước đầu tiên.

Full documentation / Tài liệu đầy đủ: [docs/TECHNICAL.md](docs/TECHNICAL.md) (VI + EN) · [docs/GIOI-THIEU.md](docs/GIOI-THIEU.md)

## Development / Phát triển

```
php tests/php/s0-smoke.php        # one suite; run every tests/php/s*.php
python tools/i18n-build.py        # rebuild translations / dựng lại bản dịch
python tools/build-release.py     # dist/tqk-wpseo-<version>.zip
```

## Contact / Liên hệ

**Trương Quang Khải**
📞 0364 999 916 · ✉️ roo.adm@gmail.com
🌐 [facebook.com/Unk.khai](https://www.facebook.com/Unk.khai/) · conggame.online

*Support the author / Ủng hộ tác giả:* Vietcombank · STK **0501 000 151853** · Truong Quang Khai · Chi nhánh Củ Chi · SWIFT BFTVVNVX
