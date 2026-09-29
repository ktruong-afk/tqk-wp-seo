=== TQK WP SEO ===
Contributors: tqk
Tags: seo, schema, sitemap, audit, vietnamese
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later

All-in-one SEO for WordPress, built for Vietnamese sites: clean code, live page audit, malware & SEO-spam scan, one-click move from Yoast / Rank Math.

== Description ==

TQK WP SEO covers the everyday SEO work of a site, audits pages the way visitors and Googlebot really see them, and makes leaving a cracked "Pro" SEO plugin painless.

**SEO basics (free)**

* SEO title, description, canonical, robots, Open Graph, Twitter cards — for posts, pages, products, categories and tags
* Schema.org graph (Article, WebPage, Product, FAQ, HowTo, Recipe, Course, Event, LocalBusiness, BreadcrumbList) + templates and conditional rules
* XML sitemap, robots.txt editor, llms.txt, redirects (CSV), 404 monitor, internal links, instant indexing (Google / Bing)

**Live Audit (free)**

* Opens every page like a visitor at 1440 / 1280 / 768 / 375 px, checks tags, schema, fields and layout, and saves a full-page snapshot
* Sample mode (every page + one post per post type) or every post of a type, tracked page by page: stop or close the tab any time and continue later
* Share preview check for Facebook / Zalo (og:image size and ratio)
* White-label client report (your name, logo, colour), printable to PDF, in Vietnamese or English

**Made for Vietnamese sites (free)**

* Vietnamese keyword matching: accent-insensitive (“ca phe” = “cà phê”) and syllable-aware; length rules tuned for Vietnamese
* 2025 administrative reform: flags old provinces and the abolished district level in addresses and LocalBusiness schema
* “Giá: Liên hệ” products: no broken Product schema, no “free” price

**Security & migration (free)**

* Malware & SEO-spam scan: WordPress core and WordPress.org plugins checked against official checksums, premium plugins / themes / uploads scanned for backdoor patterns, cloaking (Googlebot vs visitor) and hidden gambling / pharma links. Read-only.
* Import from Yoast SEO or Rank Math (posts, categories / tags, redirects) — works even when the old plugin is deactivated

**AI (optional)**

* Gemini Flash (free tier, paced to its limits) or local Ollama / LM Studio: suggested titles, descriptions, keywords, image ALT and schema fields. Nothing is written until you approve it.

**Pro**

* Image optimizer: resize to the sizes each screen really needs, crop to the visible area, compress, convert to WebP and serve through srcset (any builder), with site-wide images, background images and per-screen crops. The free version lists the exact manual steps instead.

== Installation ==

1. Upload `tqk-wpseo` to `wp-content/plugins` and activate.
2. Follow the setup wizard.
3. Moving from Yoast or Rank Math? Tools → Import, check a few pages, then delete the old plugin.
4. Run Live Audit from the Google Search Console screen.

== Frequently Asked Questions ==

= Does it need an AI API key? =
No. Every SEO feature works without AI. For AI suggestions you can use a free Gemini key or Ollama / LM Studio on your own machine.

= Will it slow down my site? =
No work happens on visitor page loads besides printing the tags. Audits run in your browser, scans run in small background steps.

= Does it send my data anywhere? =
Only when you use a feature that needs it: Gemini (AI suggestions), Google Search Console / Indexing, Bing, WordPress.org (official checksums for the security scan) and the license server (Pro). Each one can be left off.

= Is the uninstall clean? =
Yes. Deleting the plugin removes its tables, options, post / term meta, cron events, role capability and the files it created in uploads. Your original media is never touched.

== Changelog ==

= 0.2.0 =
* Live Audit: 4 viewports, full-page snapshots, JSON per page, resumable page-by-page tracking, share-image check.
* AI Advisor with Gemini Flash (rate-limited for the free tier).
* Image optimizer (Pro): srcset / WebP / crop, site-wide images, background overrides, <picture>; free manual steps.
* Security scan: checksums, backdoor patterns, cloaking, hidden spam links.
* Import from Yoast SEO / Rank Math, including categories / tags and redirects.
* Vietnamese SEO rules, 2025 address reform, “Liên hệ” products, category / tag SEO fields.
* White-label client report; settings page redesign; complete Vietnamese translation.
* Fixes: translations never loaded (file name), masked API keys overwritten on save, hidden buttons shown, uninstall left data behind.

= 0.1.0 =
* First release: S0 Foundation through S8 All-AJAX + setup wizard.
