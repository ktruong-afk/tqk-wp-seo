# TQK WP SEO Plugin — Technical Spec (Personal / No-fee / Local-AI)

> Mục tiêu: SEO engine chắc, chạy offline 100% không cần AI. AI/MCP là lớp điều khiển phía trên, chạy local (Ollama/LM Studio), không tốn API.

---

## 1. Kiến trúc tổng thể

```
              ┌──────────────────────┐
              │      TQK WP SEO Plugin   │
              └──────────┬───────────┘
                         │
       ┌─────────────────┼─────────────────┐
       │                 │                 │
  SEO Core          SEO Analysis       SEO Output
       │                 │                 │
┌──────┼──────┐    ┌─────┼─────┐    ┌──────┼──────┐
│      │      │    │     │     │    │      │      │
Metadata Schema URL Score Content SERP Meta JSON-LD
│      │      │    │     │     │    │      │      │
└──────┴──────┴────┴─────┴─────┴────┴──────┴──────┘
                         │
                   WordPress API
                         │
           Posts / Pages / Terms / Users
```

Phân tầng triển khai:

```
                 TQK WP SEO
                    │
       ┌────────────┼────────────┐
       │            │            │
   CORE SEO      ANALYZER    INTELLIGENCE
       │            │            │
   Meta          Content         AI Local
   Canonical     Keyword         Suggestions
   Robots        Links           Automation
   Sitemap       Images          MCP
   Schema        Technical       Reports
       │            │            │
       └────────────┼────────────┘
                    │
               INTEGRATIONS
                    │
       ┌───────────┼───────────┐
       │           │           │
  WooCommerce   Builder      Multisite
```

Nguyên tắc: **Core không phụ thuộc UI.** Admin chỉ gọi class Core.

```
TQK WP SEO
  │
  ├─ SEO CORE ── Meta, Canonical, Robots, OG, Schema Graph, Sitemap, Breadcrumb
  │
  └─ AI LAYER ── MCP ── Local AI Adapter ── Ollama / LM Studio / Local API
```

### 1.1 Cấu trúc thư mục

```
tqk-wpseo/
├── tqk-wpseo.php              # bootstrap, constants, autoload
├── src/
│   ├── Core/               # Context, Meta, Canonical, Robots, OpenGraph, Schema, Sitemap, Breadcrumb, Cache
│   │   ├── Context.php
│   │   ├── Meta.php
│   │   ├── Canonical.php
│   │   ├── Robots.php
│   │   ├── OpenGraph.php
│   │   ├── Schema/
│   │   │   ├── SchemaGraph.php
│   │   │   ├── Organization.php
│   │   │   ├── WebSite.php
│   │   │   ├── WebPage.php
│   │   │   ├── Article.php
│   │   │   ├── Breadcrumb.php
│   │   │   └── Product.php
│   │   ├── Sitemap/
│   │   └── Breadcrumb.php
│   ├── Analyzer/           # Keyword, Content, Heading, Image, Links, Readability, Score, RulesEngine
│   ├── Links/              # InternalLinkEngine, LinkGraph
│   ├── Redirect/           # RedirectManager, Monitor404
│   ├── Scanner/            # SiteScanner, ScanJob
│   ├── Admin/              # Dashboard, Metabox, BulkEditor, Settings, SitemapUI, SchemaUI
│   ├── AI/                 # AIProviderInterface, OllamaProvider, LMStudioProvider, OpenAIProvider (opt)
│   ├── MCP/                # tools: seo_* handlers
│   └── API/                # tqk_wpseo() helper, hooks
├── assets/css/
├── assets/js/              # metabox, dashboard, scanner progress, AI suggest UI
├── templates/              # breadcrumb, SERP preview, audit report
└── languages/
```

---

## 2. Data flow (load-bearing)

### 2.1 Activation

```
Plugin Activation
  → Check WP version
  → Create/update DB (dbDelta)
  → Register settings
  → Register post meta
  → Register rewrite rules (sitemap)
  → Register hooks
  → Flush rewrite rules
```

### 2.2 Frontend render (`wp_head`, priority ~1-5)

```
WP Request
 → SEO\Context::resolve()   # singular? archive? home? CPT? term? author?
 → Load SEO data (cache → DB → defaults)
 → Generate defaults (template tags)
 → Apply overrides (postmeta / settings)
 → Canonical::generate()
 → Robots::generate()
 → OpenGraph::generate()
 → Schema::generate()       # single @graph
 → Breadcrumb (data + HTML on demand)
 → wp_head output (escaped)
```

Pseudo:

```php
$context = SEO\Context::resolve();
$data    = SEO\Data::for($context); // cached
echo SEO\Meta::render($data);
echo SEO\Canonical::render($data);
echo SEO\Robots::render($data);
echo SEO\OpenGraph::render($data);
echo SEO\Schema::render($data); // one <script type="application/ld+json">
```

### 2.3 Save post (phải nhanh, không scan full site)

```
Post Save
 → Validate + sanitize SEO fields
 → Save SEO meta
 → Invalidate cache (meta/schema/analysis for object_id)
 → Queue analyzer (async/cron)
 → Update SEO score (background)
 → Update internal links (parse <a> background)
```

Dùng `save_post` → queue → `wp_cron` / Action Scheduler. Bài 5000 từ vẫn save nhanh.

### 2.4 Site Scanner (batch, không timeout)

```
Start Scan
 → Create Scan Job (id, status, total, offset)
 → Get content IDs (posts, pages, CPT, taxonomies)
 → Batch 50 → Analyze → Store results → Next batch (AJAX/cron)
 → Complete → aggregate Dashboard
```

Tuyệt đối không scan 5000 posts trong 1 request (tránh timeout/OOM/502).

### 2.5 Internal Link Engine

```
Post saved
 → Parse HTML → Extract <a>
 → Resolve target (internal? post_id? external? broken?)
 → Store relation: source_id, target_id, anchor_text, url
```

Query:

- `Who links to X?` (incoming)
- `Orphan pages` (incoming = 0, exclude noindex/front)
- `Too many outgoing / weak linking`

### 2.6 Cache

Frontend chỉ:

```
Current URL → SEO object → Cached data → HTML output
```

Không: scan DB / analyze / tính score realtime.

Cache buckets: `meta`, `schema`, `sitemap`, `analysis`. Invalidate theo `object_id` khi save/settings đổi. Dùng transients/object-cache + file cache cho sitemap XML nếu cần.

---

## 3. Core SEO

### 3.1 SEO Meta (bắt buộc)

Fields per object:

- SEO Title, Meta Description, Canonical URL, Robots (index/follow/archive), Featured image override
- Social: Facebook title/desc/image, Twitter title/desc/image
- Schema type override

Template tags (settings → fallback):

```
%title% %site_name% %category% %author% %excerpt% %date% %modified% %price% (Woo)
```

VD: Post → `%title% | %site_name%`, Desc → `%excerpt%`, Product → `%title% - %price% | %site_name%`

Output phải escape (`esc_attr`, `esc_url`), cắt length hợp lý, apply filter `tqk_wpseo_title`, `tqk_wpseo_description`.

### 3.2 Canonical / URL

- Tự canonical, strip `utm_*`, pagination handling (`/page/2/`), attachment → parent/noindex option.
- Options: noindex attachments, search pages, author archives, tag archives, paginated archives.

### 3.3 Robots + robots.txt

- Meta robots per object + global defaults.
- Admin editor cho `robots.txt` với: Reset default, Preview, Validate.
- Guard: cảnh báo + confirm nếu rule block toàn site (`Disallow: /`).

### 3.4 Sitemap

```
sitemap_index.xml
 ├── post-sitemap.xml
 ├── page-sitemap.xml
 ├── category-sitemap.xml
 └── product-sitemap.xml (nếu Woo)
```

- `Sitemap Manager`: Posts, Pages, Categories, Tags, Authors, CPT, Custom Taxonomies.
- Filter dev: `apply_filters('tqk_wpseo_sitemap_items', $items, $type)`.
- Cache sitemap, invalidate khi publish/delete. Rewrite rules + `XSL` đơn giản (optional).

### 3.5 Schema / JSON-LD (dùng @graph)

```
Schema
 ├── SchemaGraph
 ├── Organization
 ├── WebSite
 ├── WebPage
 ├── Article / BlogPosting
 ├── BreadcrumbList
 ├── Person
 └── Product/Offer/Review/AggregateRating (Woo)
```

Output duy nhất:

```json
{ "@context": "https://schema.org", "@graph": [ ... ] }
```

Không phun nhiều `<script>` rời. `SchemaGraph::add($node)`, validate bằng code trước khi render. Schema Templates: `IF post_type=product THEN Product Schema`.

### 3.6 Breadcrumb

- HTML + `BreadcrumbList` schema đồng bộ.
- Helper theme: `tqk_wpseo_breadcrumb()`.
- Cấu trúc: Home > Blog > Category > Title.

---

## 4. Analyzer

### 4.1 Focus Keyword

- Input 1 keyword chính (v1), sau này multi/related.
- Normalize trước khi so khớp: lowercase, trim, unaccent/simple fold. VD `WordPress Multisite Plugin` == `wordpress multisite plugin`.
- Check: Title, URL/slug, Description, H1, first paragraph, H2, image ALT, internal links.
- Về sau: stemming, synonyms, semantic (optional, không hard-code vào score).

### 4.2 Content Analyzer

- Heading: đếm H1/H2/H3, phát hiện Multiple H1, Missing H1, hierarchy broken (VD H1 → H2 → H4 warning).
- Length: word count + content depth (configurable, không phán "phải 1500 từ").
- Paragraph/readability (module optional): avg paragraph length, longest paragraph, sentence length, passive-voice heuristic, transition words.

### 4.3 Image SEO

Scan `<img>`: ALT, width/height, lazy loading, file size, format.
Report: `Images: 12 | ALT ok 9 / missing 3 | Dimensions ok 10 / missing 2`.
v1: chỉ báo, không tự sửa ảnh. Về sau: gợi ý WebP/AVIF, large-image detection.

### 4.4 Internal/External Link Analyzer

- Đếm inbound/outbound/internal/external (text link counter như Yoast).
- Phát hiện orphan, weak linking, no incoming, too many outgoing.

### 4.5 SEO Score

Không chốt 1 score duy nhất quá sớm. Tính theo nhóm rồi aggregate:

```
Technical   92
Content     76
Links       81
Images      65
Schema     100
Social      90
→ overall_score() (weighted average, configurable)
```

Metabox hiển thị checklist:

```
SEO Score: 78/100
✓ Keyword trong title / desc / URL / H1 / H2 / internal links
⚠ Keyword density thấp / Image thiếu ALT
✗ Meta description quá dài
```

### 4.6 Rules Engine (khuyến nghị)

Thay vì hàng trăm `if`, định nghĩa rules:

```php
[
  'id' => 'meta_description_length',
  'group' => 'content',           // technical|content|links|images|schema|social
  'severity' => 'warning',        // critical|warning|passed
  'message' => 'Meta description quá dài',
]
```

Rules mặc định: `title_missing, title_too_long, description_missing, description_too_long, multiple_h1, missing_h1, missing_alt, orphan_page, broken_link, no_canonical, no_schema, noindex_unexpected`.

Thêm rule mới = thêm entry + evaluator, không sửa core.

---

## 5. Technical SEO

### 5.1 Redirect Manager

- Table redirects: old/new/type (301/302/307/410), hits, status.
- UI bulk, import/export CSV (v2).

### 5.2 404 Monitor

- Log 404 URL + hits + last hit + referrer (giới hạn retention để khỏi phình DB).
- `152 hits /wp-old/` → 1-click `Create redirect`.

### 5.3 Site-wide Scanner + Dashboard

Dashboard (khác Rank Math):

```
┌──────────────────────────────┐
│ SEO Health       86          │
│ Critical Issues      2       │
│ Warnings             12      │
│ Passed               48      │
└──────────────────────────────┘
Indexability ████████████████░░ 91%
Content      ██████████████░░░░ 78%
Links        ████████████████░░ 82%
Images       ████████████░░░░░░ 64%
```

Audit output mẫu:

```
Critical: 12 pages missing title, 7 missing desc, 3 unexpected noindex, 2 broken canonical
Warnings: 43 images missing ALT, 17 orphan posts, 29 long titles
```

### 5.4 Bulk editor

Table posts: Title/Description inline edit + filter `missing title/desc/social` → bulk select → save.

---

## 6. Database

### 6.1 Hybrid v1 (khuyến nghị)

- `postmeta` (+ `termmeta`): SEO đơn giản (title, desc, canonical, robots, social, schema_type).
- Custom tables: analytics/scan/links/404/redirect.

### 6.2 Schema đề xuất

`wp_tqk_wpseo_meta` (nếu tách khỏi postmeta về sau):

```
object_id BIGINT, object_type VARCHAR(20), -- post|term|user|archive
seo_title TEXT, seo_description TEXT, canonical TEXT, robots VARCHAR(50),
og_title TEXT, og_description TEXT, og_image TEXT,
twitter_title TEXT, twitter_description TEXT,
schema_type VARCHAR(50), created_at DATETIME, updated_at DATETIME,
PRIMARY KEY (object_type, object_id)
```

`wp_tqk_wpseo_redirects`: `id, source VARCHAR(255) UNIQUE, target TEXT, type SMALLINT, hits BIGINT, active TINYINT, created_at, updated_at`

`wp_tqk_wpseo_404`: `id, url VARCHAR(255), hits BIGINT, last_hit DATETIME, referrer TEXT`

`wp_tqk_wpseo_links`: `source_id BIGINT, source_type VARCHAR(20), target_id BIGINT NULL, url TEXT, anchor TEXT, is_internal TINYINT, is_broken TINYINT`

`wp_tqk_wpseo_scan`: `job_id, object_id, object_type, group_scores JSON, issues JSON, score INT, scanned_at`

Dùng `dbDelta`, prefix `$wpdb->prefix`, index cho `source_id/target_id/url`.

---

## 7. Admin UI

```
SEO
├── Dashboard (Health, Critical/Warnings/Passed, bars)
├── Site Analysis (Run Scan, Audit report)
├── Content (Posts, Pages, Taxonomies — bulk)
├── Sitemap (Manager + preview)
├── Schema (Graph preview + templates)
├── Redirects
├── 404 Monitor
├── Internal Links (graph, orphan)
├── Settings (templates, indexability, social defaults, AI, MCP)
└── Tools (import/export, reset, system info)
```

Post Editor metabox:

```
SEO [Focus Keyword ............]
SEO Title [....................] (char count + SERP preview)
Description [..................] (char count + SERP preview)
Preview: Google snippet + Social preview
Analysis: ✓/⚠/✗ list theo group
```

Gutenberg: sidebar panel + metabox fallback. Elementor/Builder: gọi API (mục 9).

---

## 8. AI Local (optional, Phase 4)

### 8.1 Interface

```php
interface AIProviderInterface {
  public function generate(string $prompt, array $context = []): string;
}
// OllamaProvider, LMStudioProvider, OpenAIProvider (opt), AnthropicProvider (opt), CustomProvider (opt)
```

Mặc định `AI Provider = Local`. Đổi model (Qwen/Llama/Gemma/DeepSeek) không sửa SEO core. Endpoint VD: `http://127.0.0.1:11434`.

### 8.2 Use cases

1. SEO title: content + old title → 5 options.
2. Meta description: content → description ~150-160 chars.
3. Keyword extraction: primary/secondary/related.
4. Content explanation: SEO engine đưa technical findings (JSON) → AI diễn giải tiếng Việt + gợi ý sửa.
5. Internal-link suggestions: đọc title/excerpt → `A→C reason: related topic` → UI checkbox `Apply selected`.
6. Schema suggestions: phân tích CPT/fields → đề xuất type → Schema engine validate bằng code.

Nguyên tắc: **score và facts do code quyết định, AI chỉ suggest/explain.** Luồng: `preview → approve → update`, không auto-write production.

---

## 9. MCP (Phase 5)

MCP không phụ thuộc AI provider. Đứng cạnh SEO Core:

```
         TQK WP SEO
            │
  ┌─────────┴─────────┐
  │                   │
SEO Core             MCP ── Local AI Agent (Ollama/LM Studio)
```

Tools:

```
seo_get_page, seo_update_meta, seo_analyze_page, seo_get_schema,
seo_get_missing_alt, seo_get_orphan_pages, seo_get_404,
seo_create_redirect, seo_scan_site, seo_get_sitemap
```

Luồng mẫu: `Analyze all pages missing desc → scan → 37 pages (structured) → AI generate → preview → approve → update`.

Auth: token local / capability `manage_options` / allowlist. Log mọi write.

---

## 10. Developer API + Hooks

```php
tqk_wpseo()->meta->title();
tqk_wpseo()->meta->description();
tqk_wpseo()->schema->add($node);
tqk_wpseo()->breadcrumb->render();
tqk_wpseo()->analyze($post_id);
```

Filters:

```
tqk_wpseo_title, tqk_wpseo_description, tqk_wpseo_canonical,
tqk_wpseo_robots, tqk_wpseo_schema, tqk_wpseo_sitemap, tqk_wpseo_analysis,
tqk_wpseo_sitemap_items
```

VD:

```php
add_filter('tqk_wpseo_title', fn($t) => $t . ' | My Website');
```

Tích hợp: Open Builder / Flatsome / Elementor / custom theme đều gọi API này, không parse HTML.

---

## 11. Baseline Yoast vs Rank Math (rút gọn)

| Nhóm | Yoast | Rank Math | TQK v1 |
|---|---|---|---|
| Title/Desc, SERP preview | ✅ | ✅ | ✅ Phase 1 |
| Focus keyword / multi | ✅/Prem | ✅ | 1 keyword Phase 2 |
| Readability | ✅ mạnh | ✅ | cơ bản Phase 2 |
| Sitemap, Canonical, Robots | ✅ | ✅ | ✅ Phase 1 |
| OG/Twitter | ✅ | ✅ | ✅ Phase 1 |
| Schema graph / 20+ types | ✅ graph | ✅ mạnh+templates | graph cơ bản Phase 1, builder Phase 4 |
| Breadcrumb | ✅ | ✅ | ✅ Phase 1 |
| Internal link count/suggest | ✅/Prem | ✅ | count Phase 2, suggest AI Phase 4 |
| Redirect/404 | Prem | ✅ | ✅ Phase 3 |
| Bulk editor | ✅ | ✅ | ✅ Phase 3 |
| Woo/Local/News/Video | Add-on | ✅ | Phase 4 (sau) |
| GSC/Analytics | ✅ | ✅ | GSC optional Phase 3-4 |
| AI title/desc/content | ✅ | ✅ Content AI | Local AI Phase 4 |
| AI visibility, MCP, llms.txt | partial/✅ llms | ✅ mạnh | MCP Phase 5, llms.txt optional |

Không làm (personal): rank SaaS, keyword DB online, competitor/SERP DB, subscription/license/credits.

14 module tổng: 01 Meta, 02 Content Analysis, 03 Readability, 04 Schema Engine, 05 Sitemap, 06 Social, 07 Internal Link, 08 Image SEO, 09 Redirect, 10 404 Monitor, 11 SEO Audit, 12 Rank/Analytics (chỉ GSC), 13 Woo/Local/News/Video (sau), 14 AI/MCP/Automation.

---

## 12. Roadmap

- **Phase 1 — Core:** title, desc, canonical, robots, OG, Twitter, Schema graph cơ bản, sitemap, robots.txt, breadcrumb.
- **Phase 2 — Analyzer:** focus keyword, title/desc/heading/content/image/internal/external/readability, score theo nhóm.
- **Phase 3 — Technical:** 404, redirect, broken links, orphan, bulk editor, site audit, indexability, GSC (opt).
- **Phase 4 — Advanced:** schema builder/templates, Woo/Local/News/Video (theo nhu cầu), AI local (title/desc/keyword/suggest/link/schema).
- **Phase 5 — AI/MCP:** read/analyze/scan/issues/suggest/update meta/redirect/schema. AI để cuối, không thành dependency.

---

## 13. Non-functional

- **Perf:** `wp_head` < ~5ms overhead khi cache hit; sitemap cache; scanner batch 50; cron/background.
- **Security:** nonce + `current_user_can`, sanitize/escape mọi output, validate robots/redirect/regex, rate-limit scan/AI/MCP.
- **i18n:** `__()`, text-domain `tqk-wpseo`, file `languages/`.
- **Tương thích:** Gutenberg trước, API mở cho Builder/Flatsome/Elementor/multisite.
- **GSC:** OAuth riêng, lưu token encrypted, cron sync clicks/impressions/CTR/position, tắt vẫn chạy full core.
