# NOTE — TQK WP SEO Plugin (Personal Use)

Ngày: 2026-09-21
Mục tiêu: plugin SEO hoàn thiện để tự dùng, không cạnh tranh Yoast/Rank Math, không tốn phí.

## Quyết định chính

1. **Không clone thương mại:** Lấy Yoast + Rank Math làm baseline/spec (14 module), chỉ làm 01→11 trước.
2. **Không SaaS / Không API trả phí:**
   - Không rank tracking SaaS, keyword DB online, competitor, SERP DB, license server, credits.
   - GSC giữ lại (optional, OAuth, free quota).
3. **AI local-first:**
   - Mặc định Ollama (`http://127.0.0.1:11434`) / LM Studio / Custom endpoint.
   - OpenAI/Anthropic là provider optional.
   - Nguyên tắc: **Code tính score, AI chỉ giải thích/gợi ý.** AI không tự sửa production, phải preview → approve.
4. **MCP là control layer:** expose actions cho local AI agent (Claude Code / OpenCode / Ollama agent), không phụ thuộc AI provider.
5. **Kiến trúc 3 tầng:**
   - Core SEO (Meta, Canonical, Robots, OG, Schema Graph, Sitemap, Breadcrumb) — không phụ thuộc UI.
   - Admin/Analysis (Score, Analyzer, Scanner, Links, Images, Redirect, 404, Dashboard).
   - Automation/Advanced (AI Local, MCP, GSC, Woo/Local/News/Video sau cùng).
6. **DB hybrid v1:** postmeta cho SEO đơn giản + custom tables cho scan/links/404/redirect để giảm complexity.
7. **Performance:** frontend chỉ load cached SEO object → HTML, không scan/analyze realtime. Save post → queue → background. Scanner batch 50/job.
8. **Roadmap:** Phase 1 Core → Phase 2 Analyzer → Phase 3 Technical → Phase 4 AI Local → Phase 5 MCP.

## Next step

Chi tiết kỹ thuật đầy đủ nằm ở `pushed/01-technical-spec.md`. Đọc file đó trước khi code.
Từ đó triển khai theo thứ tự: spec → Context/Data flow → Core → Analyzer → Technical → AI/MCP.
