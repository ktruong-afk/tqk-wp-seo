<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

use TQK_WPSEO\Core\Data;
use TQK_WPSEO\Core\Queue;

/**
 * S2 — Post metabox: focus keyword, SEO fields, social, SERP preview.
 * Save is fast: sanitize → save → invalidate cache → queue analyzer.
 */
class Metabox {

    public const NONCE = 'tqk-wpseo-metabox';
    /** SEO title follows the post title (kept in sync on save). */
    public const TITLE_SYNC = '_tqk_wpseo_title_sync';
    /** Posted checkbox only (never stored): share texts follow the SEO ones. */
    public const SOCIAL_SYNC = '_tqk_wpseo_social_sync';

    public static function hooks(): void {
        add_action('add_meta_boxes', [self::class, 'register']);
        add_action('save_post', [self::class, 'save'], 10, 2);
        add_action('wp_ajax_tqk_wpseo_live_analysis', [self::class, 'live_analysis']);
        add_action('wp_ajax_tqk_wpseo_scan_result', [self::class, 'scan_result']);
        add_action('wp_ajax_tqk_wpseo_ai_json', [self::class, 'ai_json']);
        add_action('wp_ajax_tqk_wpseo_page_security', [\TQK_WPSEO\Security\PageCheck::class, 'ajax']);
        add_action('wp_ajax_tqk_wpseo_mcp_push', [self::class, 'mcp_push']);
        add_action('wp_ajax_tqk_wpseo_ai_suggestion', [self::class, 'ai_suggestion']);
    }

    /**
     * Every public post type (posts, pages, products, CPTs), not only
     * post/page: a product needs its title and description as much. Filterable.
     *
     * @return string[]
     */
    public static function post_types(): array {
        $pts = function_exists('get_post_types') ? array_values((array) get_post_types(['public' => true])) : [];
        $pts = array_values(array_unique(array_diff(array_merge(['post', 'page'], $pts), ['attachment'])));
        return (array) apply_filters('tqk_wpseo_metabox_post_types', $pts);
    }

    /**
     * Scan result from the editor: the page rendered in the visible stage,
     * main content extracted in the browser. Stored as the post's rendered
     * copy (used by every later analysis) and analysed right away.
     */
    public static function scan_result(): void {
        check_ajax_referer(Ajax::NONCE);
        $id = (int) ($_POST['post_id'] ?? 0);
        if ($id <= 0 || !current_user_can('edit_post', $id)) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wpseo')]);
        }
        $html = (string) wp_unslash($_POST['html'] ?? '');
        if ($html === '' || strlen($html) > 400000) {
            wp_send_json_error(['message' => __('The page could not be read.', 'tqk-wpseo')]);
        }
        // Same extraction as the server-side capture; only ever parsed, never printed.
        $html = \TQK_WPSEO\Analyzer\Frontend::main_html($html);
        update_post_meta($id, \TQK_WPSEO\Analyzer\Analyzer::RENDERED, wp_slash((string) wp_json_encode(['at' => time(), 'html' => $html])));
        $r = \TQK_WPSEO\Analyzer\Analyzer::analyze($id, $html);

        // Images measured at the 4 screens → the optimizer's input for this page.
        $images = json_decode((string) wp_unslash($_POST['images'] ?? ''), true);
        if (is_array($images)) {
            \TQK_WPSEO\Analyzer\ImageFix::save_editor_images($id, $images);
        }
        $rows = count(\TQK_WPSEO\Analyzer\ImageFix::rows($id));

        // Snapshots: kept for the person (and to hand to an AI) — JPEG only.
        $shots = [];
        foreach (\TQK_WPSEO\Analyzer\LiveAudit::EDITOR_SHOT_KEYS as $key) {
            $data = (string) wp_unslash($_POST['shot_' . $key] ?? '');
            if ($data !== '') {
                $u = \TQK_WPSEO\Analyzer\LiveAudit::save_editor_shot($id, $key, $data);
                if ($u !== '') {
                    $shots[$key] = $u;
                }
            }
        }

        $counts = ['critical' => 0, 'warning' => 0];
        $checklist = [];
        foreach ((array) ($r['findings'] ?? []) as $f) {
            $sev = (string) ($f['severity'] ?? '');
            if (isset($counts[$sev])) {
                $counts[$sev]++;
                $checklist[] = ['severity' => $sev, 'group' => (string) ($f['group'] ?? ''), 'message' => (string) ($f['message'] ?? '')];
            }
        }

        // Report kept for AI agents (MCP): what the browser measured + the
        // server checklist + which screenshots exist.
        $client = json_decode((string) wp_unslash($_POST['report'] ?? ''), true);
        $report = (is_array($client) ? $client : []) + [
            'url' => (string) (get_post_status($id) === 'publish' ? get_permalink($id) : get_preview_post_link($id)),
        ];
        $report['score'] = (int) $r['score'];
        $report['checklist'] = $checklist;
        $report['images_to_optimize'] = $rows;
        $report['shots'] = $shots; // vault keys: private files, not public URLs
        \TQK_WPSEO\MCP\Reports::save($id, $report);
        wp_send_json_success([
            'html' => \TQK_WPSEO\Analyzer\AnalysisBox::html($r, $id),
            'score' => (int) $r['score'],
            'server' => $counts,
            'images' => ['count' => $rows, 'pro' => \TQK_WPSEO\Core\Pro::active()],
            'images_html' => $rows > 0 ? \TQK_WPSEO\Analyzer\ImageFix::page_html($id) : '',
            'shots' => array_filter(array_map([\TQK_WPSEO\Analyzer\LiveAudit::class, 'file_url'], $shots)),
        ]);
    }

    /** JSON for an outside AI: export the package, or read a pasted reply / JSON-LD. Never saves. */
    public static function ai_json(): void {
        check_ajax_referer(Ajax::NONCE);
        $id = (int) ($_POST['post_id'] ?? 0);
        if ($id <= 0 || !current_user_can('edit_post', $id)) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wpseo')]);
        }
        if (($_POST['mode'] ?? '') === 'export') {
            wp_send_json_success(['json' => AiJson::export($id)]);
        }
        if (($_POST['mode'] ?? '') === 'bundle') {
            $b = sanitize_key((string) ($_POST['bundle'] ?? 'seo'));
            $out = AiJson::bundle($id, $b);
            wp_send_json_success(is_string($out) ? ['text' => $out] : ['json' => $out]);
        }
        $raw = (string) wp_unslash($_POST['json'] ?? '');
        if (strlen($raw) > 500000) {
            wp_send_json_error(['message' => __('Too large.', 'tqk-wpseo')]);
        }
        $r = AiJson::parse($raw, $id);
        if (isset($r['error'])) {
            wp_send_json_error(['message' => $r['error']]);
        }
        wp_send_json_success($r);
    }

    /**
     * AI suggestion sent over MCP, waiting for approval: the AI's advice in
     * full, then field by field "now → proposed" to tick and fill. Nothing
     * reaches the page until the person fills the fields and updates the post.
     */
    private static function suggestion_box(int $id): void {
        $s = \TQK_WPSEO\MCP\Reports::suggestion($id);
        if ($s === null || ($s['status'] ?? '') !== 'pending') {
            return;
        }
        echo '<div class="tqk-ai-review">';
        printf(
            '<div class="tqk-ai-review-head"><span class="tqk-ai-review-badge">AI</span><strong>%s</strong><span class="description">%s</span></div>',
            esc_html__('Suggestion from an AI app — waiting for your approval', 'tqk-wpseo'),
            esc_html(sprintf(__('%1$s · %2$s', 'tqk-wpseo'), (string) ($s['from'] ?? 'AI'), wp_date('d/m/Y H:i', (int) ($s['at'] ?? 0))))
        );
        if ((string) ($s['advice'] ?? '') !== '') {
            echo '<div class="tqk-ai-advice">' . nl2br(esc_html((string) $s['advice'])) . '</div>';
        }
        echo '<div class="tqk-ai-review-fields"></div>';
        printf(
            '<script type="application/json" class="tqk-ai-review-data">%s</script>',
            wp_json_encode(['fields' => (array) ($s['fields'] ?? []), 'labels' => (array) ($s['labels'] ?? []), 'refused' => (array) ($s['refused'] ?? [])], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)
        );
        printf(
            '<div class="tqk-ai-review-foot"><input type="hidden" name="tqk_wpseo_ai_applied" value=""><button type="button" class="button tqk-ai-dismiss">%s</button>'
            . '<span class="description">%s</span><span class="tqk-ai-review-msg" aria-live="polite"></span></div>',
            esc_html__('Dismiss this suggestion', 'tqk-wpseo'),
            esc_html__('Filled values are saved when you update the post.', 'tqk-wpseo')
        );
        echo '</div>';
    }

    /** Dismisses the pending AI suggestion of a post. */
    public static function ai_suggestion(): void {
        check_ajax_referer(Ajax::NONCE);
        $id = (int) ($_POST['post_id'] ?? 0);
        if ($id <= 0 || !current_user_can('edit_post', $id)) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wpseo')]);
        }
        \TQK_WPSEO\MCP\Reports::close_suggestion($id, 'dismissed');
        wp_send_json_success(['message' => __('Suggestion dismissed.', 'tqk-wpseo')]);
    }

    /** Puts the post in the MCP inbox (its stored scan report goes with it). */
    public static function mcp_push(): void {
        check_ajax_referer(Ajax::NONCE);
        $id = (int) ($_POST['post_id'] ?? 0);
        if ($id <= 0 || !current_user_can('edit_post', $id)) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wpseo')]);
        }
        if (\TQK_WPSEO\MCP\Reports::get($id) === null) {
            wp_send_json_error(['message' => __('Scan the page first: there is no report to send yet.', 'tqk-wpseo')]);
        }
        \TQK_WPSEO\MCP\Reports::push($id, (string) wp_unslash($_POST['note'] ?? ''));
        wp_send_json_success(['message' => __('Sent. In Claude or Cursor, ask: "review the pages I sent from TQK SEO".', 'tqk-wpseo')]);
    }

    /** Live checklist for unsaved editor values (own nonce + edit rights on that post). */
    public static function live_analysis(): void {
        check_ajax_referer(Ajax::NONCE);
        \TQK_WPSEO\Analyzer\AnalysisBox::ajax_live();
    }

    private static function ver(string $rel): string {
        $mtime = @filemtime(TQK_WPSEO_DIR . $rel);
        return $mtime ? TQK_WPSEO_VERSION . '.' . $mtime : TQK_WPSEO_VERSION;
    }

    public static function register(): void {
        foreach (self::post_types() as $pt) {
            add_meta_box('tqk-wpseo', __('TQK SEO', 'tqk-wpseo'), [self::class, 'render'], $pt, 'normal', 'high');
        }
    }

    public static function assets(string $hook): void {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        // Scan: the same visible stage + page check as Live Audit. Loaded
        // before the metabox script, which uses them as soon as it runs
        // (e.g. to show the last stored report).
        $helpers = ['tqk-wpseo-stage', 'tqk-wpseo-snapshot', 'tqk-wpseo-responsive', 'tqk-wpseo-sections', 'tqk-wpseo-plan'];
        foreach ($helpers as $h) {
            wp_enqueue_script($h, TQK_WPSEO_URL . 'assets/js/' . $h . '.js', [], self::ver('assets/js/' . $h . '.js'), true);
        }
        wp_enqueue_script(
            'tqk-wpseo-metabox',
            TQK_WPSEO_URL . 'assets/js/tqk-wpseo-metabox.js',
            $helpers,
            self::ver('assets/js/tqk-wpseo-metabox.js'),
            true
        );
        wp_enqueue_style(
            'tqk-wpseo-metabox',
            TQK_WPSEO_URL . 'assets/css/tqk-wpseo-metabox.css',
            [],
            self::ver('assets/css/tqk-wpseo-metabox.css')
        );
        wp_enqueue_script(
            'tqk-wpseo-ai',
            TQK_WPSEO_URL . 'assets/js/tqk-wpseo-ai.js',
            [],
            TQK_WPSEO_VERSION,
            true
        );
        wp_enqueue_style(
            'tqk-wpseo-ai',
            TQK_WPSEO_URL . 'assets/css/tqk-wpseo-ai.css',
            [],
            TQK_WPSEO_VERSION
        );
        wp_localize_script('tqk-wpseo-metabox', 'tqkWpseoAjax', [
            'nonce' => wp_create_nonce(Ajax::NONCE),
            't' => self::js_strings(),
        ]);
        // The image field is a media frame, not a URL box.
        wp_enqueue_media();
    }

    /** Texts of the editor scan / plan / AI tools; also used by the toolbar audit. */
    public static function js_strings(): array {
        return [
            'updating' => __('Updating…', 'tqk-wpseo'),
            'template' => __('from template', 'tqk-wpseo'),
            'px' => __('%1$d / %2$d px · %3$d chars', 'tqk-wpseo'),
            'cut' => __('Google will cut it here', 'tqk-wpseo'),
            'noDesc' => __('No description: Google will pick a snippet from the page.', 'tqk-wpseo'),
            'sizeOk' => __('good size', 'tqk-wpseo'),
            'sizeLow' => __('works, 1200×630 is sharper', 'tqk-wpseo'),
            'sizeRatio' => __('will be cropped to 1.91:1 (see the frame)', 'tqk-wpseo'),
            'sizeSmall' => __('too small: under 600×315 Facebook / Zalo show a tiny thumbnail or none', 'tqk-wpseo'),
            'sizeBroken' => __('image does not load', 'tqk-wpseo'),
            'stage' => Pages::stage_strings(),
            'seo' => Pages::seo_strings(),
            'scanLoad' => __('Opening %s…', 'tqk-wpseo'),
            'scanRead' => __('Reading the page…', 'tqk-wpseo'),
            'scanShot' => __('Snapshot %s…', 'tqk-wpseo'),
            'scanSaving' => __('Updating the checklist…', 'tqk-wpseo'),
            'scanDone' => __('Checked the page as visitors see it (%s).', 'tqk-wpseo'),
            'scanFail' => __('The page could not be opened: %s', 'tqk-wpseo'),
            'scanStopped' => __('Stopped.', 'tqk-wpseo'),
            'scanDirty' => __('Unsaved changes are not on the live page yet: use Save & scan.', 'tqk-wpseo'),
            'scanScreens' => __('Display score per screen (/100)', 'tqk-wpseo'),
            'whyTitle' => __('Display score on this screen, out of 100.', 'tqk-wpseo'),
            'whyLost' => __('Points lost for:', 'tqk-wpseo'),
            'whyNone' => __('Nothing to fix.', 'tqk-wpseo'),
            'whyOverflow' => __('scrolls sideways by %dpx', 'tqk-wpseo'),
            'whyTiny' => __('%d text(s) under 12px', 'tqk-wpseo'),
            'whyTap' => __('%d button(s)/link(s) under 44px', 'tqk-wpseo'),
            'whyDims' => __('%d image(s) without width/height', 'tqk-wpseo'),
            'whyOver' => __('%d oversized image(s)', 'tqk-wpseo'),
            'scanPage' => __('On the page', 'tqk-wpseo'),
            'scanNoIssue' => __('No problem found on the page.', 'tqk-wpseo'),
            'saving' => __('Saving…', 'tqk-wpseo'),
            'jsonCopied' => __('Copied. Paste it into Claude, ChatGPT or Gemini, then paste the reply below.', 'tqk-wpseo'),
            'jsonCopyFail' => __('Select the text and copy it (Ctrl+C).', 'tqk-wpseo'),
            'jsonCopiedSet' => __('%1$s: %2$s characters (~%3$s tokens).', 'tqk-wpseo'),
            'jsonCopiedText' => __('Copied: paste it first so the AI knows the page, then send the other sets.', 'tqk-wpseo'),
            'toastCopied' => __('Copied %1$s — %2$s characters. Paste it into the AI chat.', 'tqk-wpseo'),
            'toastNotCopied' => __('Not copied automatically: the text is selected in the box below, press Ctrl+C.', 'tqk-wpseo'),
            'toastFailed' => __('Could not prepare the text. Reload the page and try again.', 'tqk-wpseo'),
            'toastCss' => __('CSS copied. Paste it in Appearance → Customize → Additional CSS.', 'tqk-wpseo'),
            'toastCssFail' => __('Not copied: select the CSS line under the item and press Ctrl+C.', 'tqk-wpseo'),
            'jsonEmpty' => __('Paste the JSON first.', 'tqk-wpseo'),
            'jsonField' => __('Field', 'tqk-wpseo'),
            'jsonNow' => __('Now', 'tqk-wpseo'),
            'jsonNew' => __('New', 'tqk-wpseo'),
            'jsonApply' => __('Fill selected fields', 'tqk-wpseo'),
            'jsonApplied' => __('%d field(s) filled. Review them, then update the post to save.', 'tqk-wpseo'),
            'jsonSame' => __('Everything in this JSON is already filled in.', 'tqk-wpseo'),
            'jsonMismatch' => __('This reply was made for another post (ID %d). Check before using it.', 'tqk-wpseo'),
            'jsonFromLd' => __('Structured data (JSON-LD) found: schema type and fields below.', 'tqk-wpseo'),
            'empty' => __('(empty)', 'tqk-wpseo'),
            'plan' => self::plan_strings(),
            'aiFilled' => __('Filled. Check the fields, then update the post to save them.', 'tqk-wpseo'),
            'aiDismissed' => __('Suggestion dismissed.', 'tqk-wpseo'),
            'lastTitle' => __('Last check of this page', 'tqk-wpseo'),
            'trend' => __('Over %1$d checks: SEO %2$d → %3$d, to fix now %4$d → %5$d', 'tqk-wpseo'),
            'lastWhen' => __('%1$s · %2$s — scan again for fresh results', 'tqk-wpseo'),
            'sources' => [
                'site audit' => __('from the Site analysis audit', 'tqk-wpseo'),
                'editor scan' => __('from the Scan button', 'tqk-wpseo'),
                'toolbar' => __('from the toolbar audit', 'tqk-wpseo'),
            ],
            'secRunning' => __('Checking the page as visitors and Google get it…', 'tqk-wpseo'),
            'scriptsTitle' => __('Scripts on this page', 'tqk-wpseo'),
            'scriptsSum' => __('%1$d files · %2$d inline', 'tqk-wpseo'),
            'scriptsHow' => __('Every script visitors load, and where it comes from. Unknown domains are not always bad: check which plugin or theme adds them. Nothing is removed automatically.', 'tqk-wpseo'),
            'inlineFlagged' => __('Inline scripts to review', 'tqk-wpseo'),
            'kinds' => [
                'local' => __('this site', 'tqk-wpseo'),
                'known' => __('known service', 'tqk-wpseo'),
                'unknown' => __('unknown', 'tqk-wpseo'),
                'bad' => __('malicious', 'tqk-wpseo'),
            ],
            'shotsTitle' => __('Snapshots', 'tqk-wpseo'),
            'shotsHow' => __('Full-page pictures of this scan: open, save, or give them to an AI for design advice.', 'tqk-wpseo'),
            'imagesTitle' => __('Images per screen', 'tqk-wpseo'),
            'copied' => __('Copied', 'tqk-wpseo'),
            'secTitle' => __('Section by section', 'tqk-wpseo'),
            'secDone' => __('%1$d sections, %2$d images checked, %3$d thing(s) to fix.', 'tqk-wpseo'),
            'sec' => [
                'part' => __('Part %1$d/%2$d', 'tqk-wpseo'),
                'imgCheck' => __('image %1$d/%2$d', 'tqk-wpseo'),
                'ok' => __('No problem found', 'tqk-wpseo'),
                'words' => __('%d words', 'tqk-wpseo'),
                'images' => __('%d images', 'tqk-wpseo'),
                'bg' => __('Background', 'tqk-wpseo'),
                'mobile' => __('On mobile (375 px)', 'tqk-wpseo'),
                'broken' => __('Does not load', 'tqk-wpseo'),
                'noAlt' => __('No ALT text', 'tqk-wpseo'),
                'oversized' => __('%1$d px file shown at %2$d px: too heavy', 'tqk-wpseo'),
                'upscaled' => __('%1$d px file stretched to %2$d px: blurry', 'tqk-wpseo'),
                'mobileBig' => __('%1$d px file shown at %2$d px on a phone: needs a smaller size (srcset)', 'tqk-wpseo'),
                'noDims' => __('No width/height: the page jumps while loading', 'tqk-wpseo'),
                'heavy' => __('Heavy: %s', 'tqk-wpseo'),
                'webp' => __('WebP would be much lighter (%s)', 'tqk-wpseo'),
                'lazyTop' => __('Lazy-loaded in the first screen: slows the main image (LCP)', 'tqk-wpseo'),
                'noLazy' => __('Below the first screen but not lazy-loaded', 'tqk-wpseo'),
                'emptyLink' => __('%d link(s) without text: Google and screen readers cannot tell where they go', 'tqk-wpseo'),
            ],
        ];
    }

    /** Texts of the action plan shown after a scan (assets/js/tqk-wpseo-plan.js). */
    public static function plan_strings(): array {
        return [
            'title' => __('Action plan', 'tqk-wpseo'),
            'now' => __('Fix now', 'tqk-wpseo'),
            'should' => __('Should fix', 'tqk-wpseo'),
            'nice' => __('Nice to have', 'tqk-wpseo'),
            'nNow' => __('%d fix now', 'tqk-wpseo'),
            'nShould' => __('%d should fix', 'tqk-wpseo'),
            'nNice' => __('%d nice to have', 'tqk-wpseo'),
            'empty' => __('Nothing left to fix on this page.', 'tqk-wpseo'),
            'words' => __('%d words', 'tqk-wpseo'),
            'fix' => __('Fix', 'tqk-wpseo'),
            'copyCss' => __('Copy CSS', 'tqk-wpseo'),
            'seeImages' => __('Optimize', 'tqk-wpseo'),
            'seeImagesFree' => __('See how', 'tqk-wpseo'),
            'seeChecklist' => __('See', 'tqk-wpseo'),
            'noH2' => __('No H2 heading', 'tqk-wpseo'),
            'hSkip' => __('Heading levels skip (%s)', 'tqk-wpseo'),
            'noTwitter' => __('No X (Twitter) card tag', 'tqk-wpseo'),
            'overflow' => __('The page scrolls sideways on %1$s: %2$s', 'tqk-wpseo'),
            'smallTap' => __('%d buttons / links are too small to tap', 'tqk-wpseo'),
            'smallTapHow' => __('Fingers need about 44×44 px. Make them bigger or add padding.', 'tqk-wpseo'),
            'emptyLinks' => __('%d links without text', 'tqk-wpseo'),
            'emptyLinksHow' => __('Icon-only links need a label (aria-label or a title) so Google and screen readers know where they go.', 'tqk-wpseo'),
            'images' => __('%d images should be resized for each screen', 'tqk-wpseo'),
            'imagesPro' => __('Pro makes one file per screen (resized, cropped, compressed, WebP) and serves it through srcset. Check the list below, then Generate and Activate.', 'tqk-wpseo'),
            'imagesFree' => __('The steps for each image are listed below. Pro does all of it in one click: resize, crop, compress, convert to WebP and serve the right file to each screen.', 'tqk-wpseo'),
            'checklist' => __('Content checklist: %1$d serious, %2$d warnings', 'tqk-wpseo'),
            'checklistHow' => __('Keyword, length, links and readability: see the checklist below.', 'tqk-wpseo'),
            'secHow' => __('Signs of hacked code. Run the full Security scan (TQK SEO → Security) to find the file that adds it, and change all passwords.', 'tqk-wpseo'),
            'unknownScripts' => __('Scripts from unfamiliar domains: %s', 'tqk-wpseo'),
            'unknownHow' => __('Check which plugin, theme or tracking code adds them; remove what you do not recognise.', 'tqk-wpseo'),
            'verdict' => [
                'clean' => __('No malware signs', 'tqk-wpseo'),
                'review' => __('To review', 'tqk-wpseo'),
                'infected' => __('Malware signs', 'tqk-wpseo'),
            ],
            'how' => [
                'noindex' => __('Google will not show this page. Check Advanced → Robots, and Settings → Reading → "Discourage search engines".', 'tqk-wpseo'),
                'noTitle' => __('Write a title of about 50–60 characters with the main keyword near the start.', 'tqk-wpseo'),
                'noDesc' => __('Without it Google picks any sentence from the page. Write 1–2 sentences (about 150 characters) with the main keyword.', 'tqk-wpseo'),
                'longTitle' => __('Keep it under about 580 px (≈ 60 characters) so it is not cut.', 'tqk-wpseo'),
                'longDesc' => __('Keep it under about 920 px (≈ 155 characters) so it is not cut.', 'tqk-wpseo'),
                'noH1' => __('Set the main title of the page as Heading H1 (only one).', 'tqk-wpseo'),
                'manyH1' => __('Keep one H1 for the main title; turn the others into H2 in the builder.', 'tqk-wpseo'),
                'noH2' => __('Split the content with H2 sub-headings so Google and readers see its structure.', 'tqk-wpseo'),
                'hSkip' => __('Go H1 → H2 → H3 in order, without skipping a level.', 'tqk-wpseo'),
                'noOg' => __('Facebook, Zalo and X read these tags. Open the Social tab: title, description and image are filled automatically from the SEO values.', 'tqk-wpseo'),
                'noSchema' => __('Pick a schema type in the Advanced tab (Article, Product, FAQ…) so Google understands the page.', 'tqk-wpseo'),
                'noAlt' => __('Describe each image in a few words (ALT), in the Media Library or in the builder.', 'tqk-wpseo'),
                'thin' => __('Pages that rank usually have at least %d words of useful text.', 'tqk-wpseo'),
                'noCanon' => __('The plugin prints a canonical by default; check it is not switched off for this page.', 'tqk-wpseo'),
                'priceZero' => __('Turn on the "no price" setting instead of 0, or Google shows the product as free.', 'tqk-wpseo'),
            ],
            'type' => [
                'mainSmall' => __('Main reading text is %1$spx on %2$s: use %3$spx', 'tqk-wpseo'),
                'bodySmall' => __('%1$s: text %2$spx is too small on %3$s: use %4$spx', 'tqk-wpseo'),
                'bodyBig' => __('%1$s: text %2$spx is too big on %3$s: about %4$spx', 'tqk-wpseo'),
                'hBig' => __('%1$s (%2$s) is %3$spx and takes %4$s lines on %5$s: about %6$spx', 'tqk-wpseo'),
                'hSmall' => __('%1$s (%2$s) is only %3$spx on %4$s: about %5$spx', 'tqk-wpseo'),
                'lh' => __('%1$s: lines too tight (line-height %2$s) on %3$s: use %4$s', 'tqk-wpseo'),
                'measure' => __('%1$s: lines of about %2$s characters on %3$s: keep 60–75', 'tqk-wpseo'),
                'contrast' => __('%1$s: %2$s text on %3$s is hard to read (%4$s:1, needs %5$s:1): use %6$s', 'tqk-wpseo'),
            ],
        ];
    }

    /** @return array Field key => [label, type]. */
    public static function fields(): array {
        return [
            '_tqk_wpseo_focus_keyword'      => [__('Focus keyword', 'tqk-wpseo'), 'text'],
            '_tqk_wpseo_secondary_keywords' => [__('Secondary keywords (comma, max 4)', 'tqk-wpseo'), 'text'],
            '_tqk_wpseo_title'              => [__('SEO Title', 'tqk-wpseo'), 'text'],
            '_tqk_wpseo_description'        => [__('Meta Description', 'tqk-wpseo'), 'textarea'],
            '_tqk_wpseo_canonical'          => [__('Canonical URL', 'tqk-wpseo'), 'url'],
            '_tqk_wpseo_robots'             => [__('Robots', 'tqk-wpseo'), 'select'],
            '_tqk_wpseo_og_title'           => [__('Share title (Facebook, Zalo)', 'tqk-wpseo'), 'text'],
            '_tqk_wpseo_og_description'     => [__('Share description (Facebook, Zalo)', 'tqk-wpseo'), 'textarea'],
            '_tqk_wpseo_og_image'           => [__('Share image (Facebook, Zalo, X)', 'tqk-wpseo'), 'image'],
            '_tqk_wpseo_twitter_title'      => [__('X (Twitter) title', 'tqk-wpseo'), 'text'],
            '_tqk_wpseo_twitter_description' => [__('X (Twitter) description', 'tqk-wpseo'), 'textarea'],
            '_tqk_wpseo_schema_type'        => [__('Schema Type', 'tqk-wpseo'), 'schema'],
            '_tqk_wpseo_sitemap_exclude'    => [__('Hide from sitemap (bot won\'t crawl via sitemap)', 'tqk-wpseo'), 'checkbox'],
        ];
    }

    public static function robot_options(): array {
        return [
            ''                 => __('Default (index, follow)', 'tqk-wpseo'),
            'index,follow'     => 'index, follow',
            'noindex,follow'   => 'noindex, follow',
            'noindex,nofollow' => 'noindex, nofollow',
        ];
    }

    public static function schema_options(): array {
        return [
            '' => __('Automatic (from template)', 'tqk-wpseo'),
            'Article' => 'Article',
            'BlogPosting' => 'BlogPosting',
            'WebPage' => 'WebPage',
            'Product' => 'Product',
            'Course' => 'Course',
            'FAQPage' => 'FAQPage',
            'HowTo' => 'HowTo',
            'Recipe' => 'Recipe',
            'Event' => 'Event',
        ];
    }

    /**
     * Types with a dynamic form. Rendered under the schema type select, one
     * block per type, JS reveals the matching one.
     *
     * @return array<string,array> type => [label, help, fields[]]
     */
    public static function schema_field_blocks(): array {
        $blocks = [];
        foreach (\TQK_WPSEO\Schema\Fields::types() as $type) {
            $fields = \TQK_WPSEO\Schema\Fields::for($type);
            if ($fields === []) {
                continue;
            }
            $blocks[$type] = [
                'label' => $type,
                'help' => $fields[array_key_first($fields)]['help'] ?? '',
                'fields' => $fields,
            ];
        }
        return $blocks;
    }

    /** Dynamic per-type fields + live validation errors + the spec for JS. */
    public static function schema_fields(int $post_id): void {
        $blocks = self::schema_field_blocks();
        if ($blocks === []) {
            return;
        }
        $type = (string) get_post_meta($post_id, '_tqk_wpseo_schema_type', true);
        echo '<div class="tqk-wpseo-schema-fields" id="tqk-wpseo-schema-fields" data-active="' . esc_attr($type) . '">';
        foreach ($blocks as $slug => $block) {
            printf('<div class="tqk-wpseo-schema-block" data-schema-type="%s"%s>', esc_attr($slug), $slug === $type ? '' : ' hidden');
            printf('<h4 class="tqk-wpseo-schema-block-title">%s</h4>', esc_html($block['label']));
            if ($block['help'] !== '') {
                printf('<p class="description">%s</p>', esc_html((string) $block['help']));
            }
            printf(
                '<button type="button" class="button tqk-wpseo-schema-harvest" data-harvest="%s" data-post="%d">%s</button>',
                esc_attr($slug),
                $post_id,
                esc_html__('Extract from post content', 'tqk-wpseo')
            );
            foreach ($block['fields'] as $name => $spec) {
                self::schema_field($name, $spec, (string) get_post_meta($post_id, (string) $spec['key'], true));
            }
            $errs = \TQK_WPSEO\Schema\Validator::errors($post_id, $slug);
            if ($errs !== []) {
                echo '<ul class="tqk-wpseo-schema-errors">';
                foreach ($errs as $e) {
                    printf('<li>%s</li>', esc_html((string) $e['message']));
                }
                echo '</ul>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private static function schema_field(string $name, array $spec, string $val): void {
        $key = (string) $spec['key'];
        $req = !empty($spec['required']);
        printf(
            '<div class="tqk-wpseo-field tqk-wpseo-schema-field" data-key="%s"><div class="tqk-field-head"><label class="tqk-field-label" for="%1$s">%2$s%3$s</label></div>',
            esc_attr($key),
            esc_html((string) $spec['label']),
            $req ? ' <span class="tqk-req">*</span>' : ''
        );
        $type = (string) $spec['type'];
        if ($type === 'textarea') {
            printf(
                '<textarea id="%1$s" name="%1$s" rows="3" class="large-text" data-schema-field>%2$s</textarea>',
                esc_attr($key),
                esc_textarea($val)
            );
        } elseif ($type === 'select') {
            printf('<select id="%1$s" name="%1$s" data-schema-field>', esc_attr($key));
            foreach ((array) ($spec['options'] ?? []) as $ov => $ol) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr((string) $ov),
                    $val === (string) $ov ? ' selected' : '',
                    esc_html((string) $ol)
                );
            }
            echo '</select>';
        } else {
            printf(
                '<input type="%3$s" id="%1$s" name="%1$s" value="%2$s" class="regular-text" data-schema-field>',
                esc_attr($key),
                esc_attr($val),
                esc_attr($type === 'datetime' ? 'datetime-local' : $type)
            );
        }
        echo '</div>';
    }

    /**
     * Effective title/description the frontend will actually output for a post.
     * Goes through Core\Data so the metabox preview cannot drift from the real
     * template resolution (custom value, else %title% / %site_name% fallback).
     *
     * @return array{title:string,description:string,og_title:string,og_description:string,og_image:string}
     */
    public static function preview(int $post_id, array $overrides = []): array {
        $d = Data::for(['type' => 'singular', 'object_id' => $post_id, 'post_type' => (string) get_post_type($post_id)]);
        foreach ($overrides as $k => $v) {
            if ($v !== '' && array_key_exists($k, $d)) {
                $d[$k] = $v;
            }
        }
        return $d;
    }

    /** %tags% supported by Core\Data::tags(), for the inline hint under a field. */
    public static function tokens(): string {
        return '%title% %site_name% %excerpt% %category%';
    }

    public static function render($post): void {
        $id = (int) (is_object($post) ? $post->ID : $post);
        wp_nonce_field(self::NONCE, self::NONCE);
        $p = self::preview($id);
        $post_title = (string) get_the_title($id);
        $analysis = \TQK_WPSEO\Analyzer\Analyzer::stored($id);
        $score = $analysis === null ? null : (int) ($analysis['score'] ?? 0);
        $tabs = [
            'seo'      => __('SEO', 'tqk-wpseo'),
            'social'   => __('Social', 'tqk-wpseo'),
            'advanced' => __('Advanced', 'tqk-wpseo'),
        ];
        $spec_json = [];
        foreach (self::schema_field_blocks() as $slug => $block) {
            $req = [];
            foreach ($block['fields'] as $name => $f) {
                if (!empty($f['required'])) {
                    $req[] = (string) $f['key'];
                }
            }
            $spec_json[$slug] = $req;
        }
        printf(
            '<script type="application/json" class="tqk-wpseo-schema-spec">%s</script>',
            wp_json_encode($spec_json)
        );
        echo '<div class="tqk-wpseo-metabox" data-post-title="' . esc_attr($post_title) . '">';
        self::suggestion_box($id);
        self::serp_preview($id, $p);
        echo '<div class="tqk-wpseo-tabs" role="tablist">';
        foreach ($tabs as $slug => $label) {
            $badge = ($slug === 'seo' && $score !== null)
                ? sprintf('<span class="tqk-wpseo-tab-score %s">%d</span>', esc_attr(\TQK_WPSEO\Admin\View::score_tone($score)), $score)
                : '';
            printf(
                '<button type="button" class="tqk-wpseo-tab%1$s" role="tab" data-tab="%2$s" aria-selected="%3$s" aria-controls="tqk-wpseo-panel-%2$s">%4$s%5$s</button>',
                $slug === 'seo' ? ' is-active' : '',
                esc_attr($slug),
                $slug === 'seo' ? 'true' : 'false',
                esc_html($label),
                $badge
            );
        }
        echo '</div>';
        foreach ($tabs as $slug => $label) {
            printf('<div class="tqk-wpseo-panel" id="tqk-wpseo-panel-%s" data-panel="%s"%s>', esc_attr($slug), esc_attr($slug), $slug === 'seo' ? '' : ' hidden');
            if ($slug === 'social') {
                self::social_panel($id, $p);
            } else {
                self::render_fields($id, self::tab_fields($slug), $p);
            }
            if ($slug === 'seo') {
                self::analysis_section($id);
            }
            echo '</div>';
        }
        self::ai_box($id);
        echo '</div>';
    }

    /** Which meta keys belong to which tab. */
    public static function tab_fields(string $tab): array {
        $f = self::fields();
        $map = [
            // Typed values first, right under the Google preview they drive.
            'seo'      => ['_tqk_wpseo_focus_keyword', '_tqk_wpseo_title', '_tqk_wpseo_description', '_tqk_wpseo_secondary_keywords', '_tqk_wpseo_canonical'],
            'social'   => ['_tqk_wpseo_og_title', '_tqk_wpseo_og_description', '_tqk_wpseo_og_image', '_tqk_wpseo_twitter_title', '_tqk_wpseo_twitter_description'],
            'advanced' => ['_tqk_wpseo_robots', '_tqk_wpseo_schema_type', '_tqk_wpseo_sitemap_exclude'],
        ];
        $out = [];
        foreach ($map[$tab] ?? [] as $k) {
            if (isset($f[$k])) {
                $out[$k] = $f[$k];
            }
        }
        return $out;
    }

    /** @param array<string,array{0:string,1:string}> $fields */
    private static function render_fields(int $id, array $fields, array $p = []): void {
        foreach ($fields as $key => [$label, $type]) {
            $val = (string) get_post_meta($id, $key, true);
            printf('<div class="tqk-wpseo-field" data-key="%s">', esc_attr($key));
            // One head row: label left, helpers (auto checkbox, pixel counter)
            // right, vertically centred on the same line.
            printf('<div class="tqk-field-head"><label class="tqk-field-label" for="%s">%s</label><span class="tqk-field-aside">', esc_attr($key), esc_html($label));
            if ($key === '_tqk_wpseo_title') {
                printf(
                    '<label class="tqk-check"><input type="checkbox" id="tqk-title-sync" name="%s" value="1"%s><span>%s</span></label>',
                    esc_attr(self::TITLE_SYNC),
                    get_post_meta($id, self::TITLE_SYNC, true) === '1' ? ' checked' : '',
                    esc_html__('Same as page title', 'tqk-wpseo')
                );
            }
            if ($type === 'text' || $type === 'textarea') {
                printf('<small class="tqk-wpseo-count" data-for="%s"></small>', esc_attr($key));
            }
            echo '</span></div>';

            if ($type === 'checkbox') {
                printf(
                    '<label class="tqk-check"><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s><span>%3$s</span></label>',
                    esc_attr($key),
                    $val === '1' ? ' checked' : '',
                    esc_html__('Exclude', 'tqk-wpseo')
                );
            } elseif ($type === 'textarea') {
                printf(
                    '<textarea id="%1$s" name="%1$s" rows="2" class="large-text" data-counter placeholder="%3$s">%2$s</textarea>',
                    esc_attr($key),
                    esc_html($val),
                    esc_attr($key === '_tqk_wpseo_description' ? (string) ($p['description'] ?? '') : '')
                );
            } elseif ($type === 'image') {
                self::image_field($id, $key);
            } elseif ($type === 'select' || $type === 'schema') {
                $options = $key === '_tqk_wpseo_schema_type' ? self::schema_options() : self::robot_options();
                printf('<select id="%s" name="%s">', esc_attr($key), esc_attr($key));
                foreach ($options as $opt_val => $opt_label) {
                    printf('<option value="%s"%s>%s</option>', esc_attr($opt_val), $val === (string) $opt_val ? ' selected' : '', esc_html($opt_label));
                }
                echo '</select>';
            } else {
                printf(
                    '<input type="%3$s" id="%1$s" name="%1$s" value="%2$s" class="large-text" data-counter placeholder="%4$s">',
                    esc_attr($key),
                    esc_attr($val),
                    esc_attr($type),
                    esc_attr($key === '_tqk_wpseo_title' ? (string) ($p['title'] ?? '') : '')
                );
            }
            if ($key === '_tqk_wpseo_schema_type') {
                self::schema_fields($id);
            }
            echo '</div>';
        }
    }

    /**
     * Share image picker: a file picker, not a URL box. Always shows the
     * image that WILL be shared (picked, else the automatic one: featured /
     * default / first in content), framed at 1.91:1 like Facebook and Zalo.
     */
    private static function image_field(int $id, string $key): void {
        $picked = (string) get_post_meta($id, $key, true);
        $auto = self::auto_share_image($id);
        $shown = $picked !== '' ? $picked : $auto['url'];
        printf(
            '<div class="tqk-wpseo-image tqk-share-pick" data-image-field data-auto-url="%s" data-auto-label="%s" data-custom-label="%s">',
            esc_attr($auto['url']),
            esc_attr($auto['label']),
            esc_attr__('Chosen for this post', 'tqk-wpseo')
        );
        printf(
            '<div class="tqk-share-frame%s"><img class="tqk-wpseo-image-preview" src="%s" alt=""%s><span class="tqk-share-empty"%s>%s</span></div>',
            $shown === '' ? ' is-empty' : '',
            esc_url($shown),
            $shown === '' ? ' hidden' : '',
            $shown === '' ? '' : ' hidden',
            esc_html__('No share image — the link will show without a picture.', 'tqk-wpseo')
        );
        printf('<input type="hidden" id="%1$s" name="%1$s" value="%2$s" data-image-url>', esc_attr($key), esc_attr($picked));
        echo '<div class="tqk-share-side">';
        printf(
            '<div class="tqk-share-meta"><span class="tqk-share-source" data-source="%s">%s</span><span class="tqk-share-size"></span></div>',
            $picked !== '' ? 'custom' : ($auto['url'] !== '' ? 'auto' : 'none'),
            esc_html($picked !== '' ? __('Chosen for this post', 'tqk-wpseo') : $auto['label'])
        );
        printf(
            '<div class="tqk-share-actions"><button type="button" class="button tqk-wpseo-image-pick" data-label-select="%s" data-label-change="%s">%s</button>',
            esc_attr__('Select image', 'tqk-wpseo'),
            esc_attr__('Change image', 'tqk-wpseo'),
            esc_html($picked !== '' ? __('Change image', 'tqk-wpseo') : __('Select image', 'tqk-wpseo'))
        );
        printf(
            '<button type="button" class="button-link tqk-wpseo-image-clear"%s>%s</button></div>',
            $picked === '' ? ' hidden' : '',
            esc_html__('Use automatic image', 'tqk-wpseo')
        );
        echo '<small class="description">' . esc_html__('Best size 1200×630 (1.91:1). The frame shows how Facebook and Zalo crop it.', 'tqk-wpseo') . '</small>';
        echo '</div></div>';
    }

    /** Page check (scan) + SEO score and checklist, under the SEO fields. */
    private static function analysis_section(int $id): void {
        if (!\TQK_WPSEO\Core\Modules::is_active('analyzer')) {
            return;
        }
        $status = (string) get_post_status($id);
        $url = $status === 'publish' ? (string) get_permalink($id) : (string) get_preview_post_link($id);
        echo '<div class="tqk-sub">' . esc_html__('Page check', 'tqk-wpseo') . '</div>';
        // form="…" with no such form: the radios are never posted with the post.
        printf(
            '<div class="tqk-scan-bar" data-scan-url="%s"><div class="tqk-scan-row">'
            . '<span class="tqk-seg" role="radiogroup" aria-label="%s">'
            . '<label><input type="radio" name="tqk_scan_mode" value="detail" form="tqk-wpseo-noform"><span>%s</span></label>'
            . '<label><input type="radio" name="tqk_scan_mode" value="fast" form="tqk-wpseo-noform"><span>%s</span></label></span>'
            . '<button type="button" class="button button-primary tqk-scan-run">%s</button>'
            . '<button type="button" class="button tqk-scan-save">%s</button>'
            . '<span class="tqk-scan-msg" aria-live="polite"></span></div>'
            . '<p class="description tqk-scan-help" data-detail="%s" data-fast="%s"></p></div>',
            esc_url($url),
            esc_attr__('Scan mode', 'tqk-wpseo'),
            esc_html__('Section by section', 'tqk-wpseo'),
            esc_html__('Quick', 'tqk-wpseo'),
            esc_html__('Scan the page', 'tqk-wpseo'),
            esc_html__('Save & scan', 'tqk-wpseo'),
            esc_attr__('Goes through the page slowly, one section at a time: outlines each part, points at every image (size shown vs file, weight, ALT, lazy-load, blurry or oversized) and shows the result next to the page as it goes. Then checks 4 screen sizes.', 'tqk-wpseo'),
            esc_attr__('Opens the page like a visitor, scrolls it once and checks 4 screen sizes. About 30 seconds.', 'tqk-wpseo')
        );
        echo '<div class="tqk-scan-report" hidden></div>';
        // Last report of this page (Live Audit or an earlier Scan), shown at once.
        $last = \TQK_WPSEO\MCP\Reports::get($id);
        if ($last !== null) {
            // Private files: signed links for this admin, and none once pruned.
            $last['shots'] = array_filter(array_map([\TQK_WPSEO\Analyzer\LiveAudit::class, 'file_url'], array_map('strval', (array) ($last['shots'] ?? []))));
            printf(
                '<script type="application/json" class="tqk-last-report" data-when="%s">%s</script>',
                esc_attr(wp_date('d/m/Y H:i', (int) ($last['at'] ?? 0))),
                wp_json_encode($last, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)
            );
        }
        echo '<div class="tqk-wpseo-analysis">';
        \TQK_WPSEO\Analyzer\AnalysisBox::render($id);
        echo '</div>';
    }

    /** Hand the page to any outside AI as JSON, paste its reply (or a JSON-LD) back. */
    private static function json_box(int $id): void {
        echo '<details class="tqk-json-box"><summary>' . esc_html__('Use another AI (Claude, ChatGPT…) or import JSON-LD', 'tqk-wpseo') . '</summary>';
        echo '<ol class="tqk-json-steps"><li>' . esc_html__('Pick one set and copy it: each set starts with the page text (no tags, no shortcodes, up to 1,000 words), then one task. Small assistants like Gemini in Chrome read one set at a time best.', 'tqk-wpseo') . '</li>'
            . '<li>' . esc_html__('Paste its JSON reply here: each key fills the field with the same key. You can also paste JSON-LD copied from any page: the schema type and its fields are filled for you.', 'tqk-wpseo') . '</li></ol>';
        echo '<div class="tqk-json-sets" role="group">';
        foreach ([
            'content' => __('① Page text', 'tqk-wpseo'),
            'seo' => __('② Title · description · keywords', 'tqk-wpseo'),
            'social' => __('③ Social', 'tqk-wpseo'),
            'schema' => __('④ Schema', 'tqk-wpseo'),
            'all' => __('Everything', 'tqk-wpseo'),
        ] as $b => $label) {
            printf('<button type="button" class="button tqk-json-set" data-bundle="%s">%s</button>', esc_attr($b), esc_html($label));
        }
        echo '</div><p class="tqk-json-status"><span class="tqk-json-msg" aria-live="polite"></span></p>';
        printf(
            '<textarea class="large-text code tqk-json-in" rows="5" placeholder="%s"></textarea>',
            esc_attr__('{"tqk_seo":1,"fields":{"title":"…"}} or <script type="application/ld+json">…</script>', 'tqk-wpseo')
        );
        printf(
            '<p><button type="button" class="button tqk-json-read">%s</button></p><div class="tqk-json-result"></div>',
            esc_html__('Read JSON', 'tqk-wpseo')
        );
        echo '<p class="description">' . esc_html__('The page text is sent to the AI you paste it into. Nothing is saved until you update the post; ratings and reviews are never imported.', 'tqk-wpseo') . '</p>';
        echo '</details>';
        self::mcp_box($id);
    }

    /**
     * "Send to AI via MCP": puts this page (its last scan: report, 4
     * screenshots, plan, security) in the inbox an MCP client reads.
     */
    private static function mcp_box(int $id): void {
        $on = \TQK_WPSEO\Core\Modules::is_active('mcp');
        echo '<details class="tqk-json-box tqk-mcp-box"><summary>' . esc_html__('Send to an AI app via MCP (Claude, Cursor…)', 'tqk-wpseo') . '</summary>';
        if (!$on) {
            printf(
                '<p class="description">%s <a href="%s">%s</a></p></details>',
                esc_html__('Connect Claude Desktop, Claude Code or Cursor to this site once, then send pages from here with one click. Turn on the MCP module first.', 'tqk-wpseo'),
                esc_url(admin_url('admin.php?page=tqk-wpseo-tools#mcp')),
                esc_html__('Set up MCP', 'tqk-wpseo')
            );
            return;
        }
        $r = \TQK_WPSEO\MCP\Reports::get($id);
        printf(
            '<p class="description">%s</p>',
            esc_html($r
                ? sprintf(__('Sends the last scan (%s): report, action plan, security check and screenshots of 4 screens.', 'tqk-wpseo'), wp_date('d/m H:i', (int) ($r['at'] ?? 0)))
                : __('Scan the page first: the AI gets the report, the action plan and screenshots of 4 screens.', 'tqk-wpseo'))
        );
        printf(
            '<div class="tqk-mcp-row"><input type="text" class="tqk-mcp-note" form="tqk-wpseo-noform" placeholder="%s"><button type="button" class="button button-primary tqk-mcp-push">%s</button></div>'
            . '<p class="tqk-mcp-msg" aria-live="polite"></p>',
            esc_attr__('Note for the AI (optional), e.g. focus on mobile', 'tqk-wpseo'),
            esc_html__('Send to AI', 'tqk-wpseo')
        );
        echo '</details>';
    }

    /**
     * The share image used when none is picked for this post, with where it
     * comes from: featured image → site default → first image in content.
     *
     * @return array{url:string,label:string}
     */
    public static function auto_share_image(int $id): array {
        $thumb = (int) get_post_thumbnail_id($id);
        $src = $thumb > 0 ? wp_get_attachment_image_src($thumb, 'full') : false;
        if (is_array($src) && !empty($src[0])) {
            return ['url' => (string) $src[0], 'label' => __('Automatic: featured image', 'tqk-wpseo')];
        }
        $default = (string) \TQK_WPSEO\Admin\Settings::get('social_default_image', '');
        if ($default !== '') {
            return ['url' => $default, 'label' => __('Automatic: site default image', 'tqk-wpseo')];
        }
        // Whatever Data resolved (first image in content when enabled).
        $url = (string) (self::preview($id)['og_image'] ?? '');
        if ($url !== '' && $url !== (string) get_post_meta($id, '_tqk_wpseo_og_image', true)) {
            return ['url' => $url, 'label' => __('Automatic: first image in the content', 'tqk-wpseo')];
        }
        return ['url' => '', 'label' => __('No automatic image: add a featured image or a site default.', 'tqk-wpseo')];
    }


    /**
     * Tells the editor where the image actually came from. Without this the
     * auto-filled URL looks like something they typed and they second-guess it.
     *
     * @return array{slug:string,label:string}
     */
    private static function social_image_source(string $img): array {
        if ($img === '') {
            return ['slug' => 'none', 'label' => __('No image — cards will show without one.', 'tqk-wpseo')];
        }
        $default = (string) \TQK_WPSEO\Admin\Settings::get('social_default_image', '');
        if ($default !== '' && $img === $default) {
            return ['slug' => 'default', 'label' => __('Using the site-wide default image.', 'tqk-wpseo')];
        }
        return ['slug' => 'auto', 'label' => __('Chosen automatically — pick an image below to change it.', 'tqk-wpseo')];
    }

    /**
     * Social tab: how the link looks when shared (Facebook, Zalo, X — all
     * read the same Open Graph tags; X falls back to them), then the image,
     * then the texts. "Same as Google" keeps the share texts empty so they
     * follow the SEO title and description automatically.
     */
    private static function social_panel(int $id, array $p): void {
        $f = self::fields();
        $url = (string) ($p['canonical'] ?? '') !== '' ? (string) $p['canonical'] : (string) get_permalink($id);
        $host = (string) preg_replace('/^www\./', '', (string) wp_parse_url($url, PHP_URL_HOST));
        $img = (string) ($p['og_image'] ?? '');
        $src = self::social_image_source($img);
        $title = esc_html((string) ($p['og_title'] ?? ''));
        $desc = esc_html((string) ($p['og_description'] ?? ''));
        $im = sprintf('<img class="tqk-wpseo-social-img" data-social-img src="%s" alt=""%s>', esc_url($img), $img === '' ? ' hidden' : '');

        echo '<div class="tqk-wpseo-social tqk-social-preview">';
        echo '<div class="tqk-social-head"><span class="tqk-sub-inline">' . esc_html__('When the link is shared', 'tqk-wpseo') . '</span>';
        echo '<span class="tqk-seg tqk-social-tabs" role="tablist">';
        foreach (['facebook' => 'Facebook', 'zalo' => 'Zalo', 'x' => 'X (Twitter)'] as $net => $name) {
            printf('<button type="button" role="tab" data-net="%s" aria-selected="%s">%s</button>', $net, $net === 'facebook' ? 'true' : 'false', esc_html($name));
        }
        echo '</span></div>';

        // Facebook: image, uppercase domain, bold title, one line of text.
        printf(
            '<div class="tqk-card tqk-card-facebook" data-net="facebook"><div class="tqk-card-img">%s</div><div class="tqk-card-body">'
            . '<div class="tqk-card-domain">%s</div><div class="tqk-card-title tqk-wpseo-social-title" data-social-title>%s</div><div class="tqk-card-desc tqk-wpseo-social-desc" data-social-desc>%s</div></div></div>',
            $im,
            esc_html(strtoupper($host)),
            $title,
            $desc
        );
        // Zalo: link card inside a chat bubble — image, 2-line title, text, domain.
        printf(
            '<div class="tqk-card tqk-card-zalo" data-net="zalo" hidden><div class="tqk-zalo-chat"><div class="tqk-zalo-bubble">'
            . '<div class="tqk-zalo-url">%s</div><div class="tqk-card-img">%s</div><div class="tqk-card-body">'
            . '<div class="tqk-card-title" data-social-title>%s</div><div class="tqk-card-desc" data-social-desc>%s</div><div class="tqk-card-domain">%s</div>'
            . '</div></div></div></div>',
            esc_html($url),
            $im,
            $title,
            $desc,
            esc_html($host)
        );
        // X: large image with the title on it, "From domain" under it.
        printf(
            '<div class="tqk-card tqk-card-x" data-net="x" hidden><div class="tqk-card-img">%s<span class="tqk-x-title" data-social-title data-x>%s</span></div>'
            . '<div class="tqk-x-from">%s</div></div>',
            $im,
            esc_html((string) ($p['twitter_title'] ?? ($p['og_title'] ?? ''))),
            esc_html(sprintf(__('From %s', 'tqk-wpseo'), $host))
        );
        printf('<div class="tqk-wpseo-social-source" data-source="%s">%s</div>', esc_attr($src['slug']), esc_html($src['label']));
        printf(
            '<p class="tqk-social-refresh">%s <a href="%s" target="_blank" rel="noopener">Facebook</a> · <a href="https://developers.zalo.me/tools/debug-sharing" target="_blank" rel="noopener">Zalo</a></p>',
            esc_html__('Facebook and Zalo keep the old preview for a while. After changing the image or texts, refresh it with their sharing debugger:', 'tqk-wpseo'),
            esc_url('https://developers.facebook.com/tools/debug/?q=' . rawurlencode($url))
        );
        echo '</div>';

        self::render_fields($id, ['_tqk_wpseo_og_image' => $f['_tqk_wpseo_og_image']], $p);

        $texts = ['_tqk_wpseo_og_title', '_tqk_wpseo_og_description', '_tqk_wpseo_twitter_title', '_tqk_wpseo_twitter_description'];
        $sync = true;
        foreach ($texts as $k) {
            if ((string) get_post_meta($id, $k, true) !== '') {
                $sync = false;
            }
        }
        echo '<div class="tqk-wpseo-field tqk-social-sync-row">';
        printf(
            '<label class="tqk-check"><input type="checkbox" id="tqk-social-sync" name="%s" value="1"%s><span>%s</span></label>',
            esc_attr(self::SOCIAL_SYNC),
            $sync ? ' checked' : '',
            esc_html__('Same title and description as Google (SEO)', 'tqk-wpseo')
        );
        echo '<small class="description">' . esc_html__('Untick to write different texts for Facebook / Zalo and X.', 'tqk-wpseo') . '</small></div>';
        printf('<div class="tqk-social-custom"%s>', $sync ? ' hidden' : '');
        self::render_fields($id, array_intersect_key($f, array_flip($texts)), $p);
        echo '</div>';
    }

    /**
     * Sitelinks preview for the front page: the main menu's top items, each
     * with its own description, as Google may show them under the result.
     *
     * @return array<int,array{title:string,url:string,desc:string}>
     */
    public static function sitelinks(int $id): array {
        if ((int) get_option('page_on_front') !== $id || !function_exists('get_nav_menu_locations')) {
            return [];
        }
        $items = [];
        foreach ((array) get_nav_menu_locations() as $menu_id) {
            $items = (array) wp_get_nav_menu_items((int) $menu_id);
            if ($items !== []) {
                break;
            }
        }
        $home = untrailingslashit(home_url('/'));
        $out = [];
        foreach ($items as $it) {
            $link = (string) ($it->url ?? '');
            if ((int) ($it->menu_item_parent ?? 0) !== 0 || $link === '' || $link[0] === '#' || untrailingslashit($link) === $home || (int) ($it->object_id ?? 0) === $id) {
                continue;
            }
            $desc = '';
            if (($it->type ?? '') === 'post_type' && (int) $it->object_id > 0) {
                $d = Data::for(['type' => 'singular', 'object_id' => (int) $it->object_id, 'post_type' => (string) ($it->object ?? 'page')]);
                $desc = (string) ($d['description'] ?? '');
            }
            if (function_exists('mb_strimwidth')) {
                $desc = mb_strimwidth($desc, 0, 90, '…');
            }
            $out[] = ['title' => wp_strip_all_tags((string) ($it->title ?? '')), 'url' => $link, 'desc' => $desc];
            if (count($out) >= 6) {
                break;
            }
        }
        return $out;
    }

    /** Google result preview, drawn like the real one, seeded with the resolved values. */
    private static function serp_preview(int $id, array $p): void {
        $url = (string) ($p['canonical'] ?? '') !== '' ? (string) $p['canonical'] : (string) get_permalink($id);
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        $crumbs = $path === '' ? [] : array_map('urldecode', explode('/', $path));
        $site = (string) get_bloginfo('name');
        $icon = function_exists('get_site_icon_url') ? (string) get_site_icon_url(32) : '';
        $date = (get_post_type($id) === 'post' && function_exists('get_the_date')) ? (string) get_the_date('', $id) : '';

        echo '<div class="tqk-wpseo-serp">';
        echo '<div class="tqk-serp-site">';
        if ($icon !== '') {
            printf('<span class="tqk-serp-fav"><img src="%s" alt=""></span>', esc_url($icon));
        } else {
            printf('<span class="tqk-serp-fav is-letter">%s</span>', esc_html(function_exists('mb_substr') ? mb_strtoupper(mb_substr($site !== '' ? $site : $host, 0, 1)) : strtoupper(substr($site !== '' ? $site : $host, 0, 1))));
        }
        printf(
            '<span class="tqk-serp-src"><span class="tqk-serp-name">%s</span><span class="tqk-wpseo-serp-url">%s</span></span><span class="tqk-serp-more" aria-hidden="true">⋮</span>',
            esc_html($site !== '' ? $site : $host),
            esc_html('https://' . $host . ($crumbs ? ' › ' . implode(' › ', $crumbs) : ''))
        );
        echo '</div>';
        printf('<div class="tqk-wpseo-serp-title" id="tqk-wpseo-serp-title" data-effective="%1$s">%1$s</div>', esc_html((string) ($p['title'] ?? '')));
        $d = (string) ($p['description'] ?? '');
        echo '<div class="tqk-serp-snippet">';
        if ($date !== '') {
            printf('<span class="tqk-serp-date">%s — </span>', esc_html($date));
        }
        printf(
            '<span class="tqk-wpseo-serp-desc%s" id="tqk-wpseo-serp-desc" data-effective="%s">%s</span>',
            $d === '' ? ' is-empty' : '',
            esc_attr($d),
            esc_html($d !== '' ? $d : __('No description: Google will pick a snippet from the page.', 'tqk-wpseo'))
        );
        echo '</div>';

        $links = self::sitelinks($id);
        if ($links !== []) {
            echo '<div class="tqk-serp-sitelinks">';
            foreach ($links as $l) {
                printf(
                    '<div class="tqk-serp-sl"><div class="tqk-serp-sl-title">%s</div><div class="tqk-serp-sl-desc">%s</div></div>',
                    esc_html($l['title']),
                    esc_html($l['desc'])
                );
            }
            echo '</div><div class="tqk-serp-note">' . esc_html__('Sitelinks preview from your main menu. Google decides whether to show them.', 'tqk-wpseo') . '</div>';
        }

        echo '<details class="tqk-serp-help"><summary>' . esc_html__('How do some sites get extra links under their result (sitelinks)?', 'tqk-wpseo') . '</summary><ul>';
        foreach ([
            __('Google picks sitelinks by itself; no tag or plugin can switch them on. They mostly show when people search your brand or site name.', 'tqk-wpseo'),
            __('Keep a clear main menu of 4–8 key pages, with short menu labels that match those pages\' titles.', 'tqk-wpseo'),
            __('Give every key page its own short, unique SEO title and description (this box).', 'tqk-wpseo'),
            __('Link those pages from the home page, keep them indexable and in the sitemap (done by this plugin).', 'tqk-wpseo'),
            __('Set the Site Title and a site icon (Appearance → Customize → Site Identity): Google shows them as the name and logo above the result; the plugin outputs the WebSite and Organization schema for it.', 'tqk-wpseo'),
            __('The breadcrumb schema (output by the plugin) turns the address line into "domain › category › page".', 'tqk-wpseo'),
            __('The search box inside results (sitelinks search box) was retired by Google in November 2024: nothing to do there.', 'tqk-wpseo'),
        ] as $tip) {
            echo '<li>' . esc_html($tip) . '</li>';
        }
        echo '</ul></details>';
        echo '</div>';
    }

    private static function ai_box(int $id): void {
        $enabled = \TQK_WPSEO\AI\Factory::create() !== null;
        echo '<hr><h4>' . esc_html__('AI suggestions', 'tqk-wpseo') . '</h4>';
        self::json_box($id);
        if (!$enabled) {
            printf(
                '<p class="description">%s <a href="%s">%s</a></p>',
                esc_html__('Choose an AI provider (free Gemini key, or Ollama / LM Studio on your machine) to get title, description and keyword ideas. Nothing is written without your approval.', 'tqk-wpseo'),
                esc_url(admin_url('admin.php?page=tqk-wpseo-settings')),
                esc_html__('Settings', 'tqk-wpseo')
            );
            return;
        }
        foreach (['title' => __('Titles', 'tqk-wpseo'), 'description' => __('Descriptions', 'tqk-wpseo'), 'keywords' => __('Keywords', 'tqk-wpseo')] as $type => $label) {
            printf(
                '<button type="button" class="button tqk-wpseo-ai-btn" data-ai="%s" data-post="%d" data-nonce="%s">%s: %s</button> ',
                esc_attr($type),
                $id,
                esc_attr(wp_create_nonce('tqk-wpseo-ai')),
                esc_html__('Generate', 'tqk-wpseo'),
                esc_html($label)
            );
        }
        echo '<div class="tqk-wpseo-ai-modal" id="tqk-wpseo-ai-modal" hidden><div class="tqk-wpseo-ai-box">'
            . '<div id="tqk-wpseo-ai-options"></div>'
            . sprintf('<p><button type="button" class="button" id="tqk-wpseo-ai-close">%s</button></p>', esc_html__('Close', 'tqk-wpseo'))
            . '</div></div>';
    }

    public static function save(int $post_id, $post = null): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(wp_unslash($_POST[self::NONCE]), self::NONCE)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $pt = is_object($post) ? ($post->post_type ?? '') : (string) get_post_type($post_id);
        if (!in_array($pt, self::post_types(), true)) {
            return;
        }

        // "Same as page title": the SEO title is the post title, kept in sync.
        $title_sync = isset($_POST[self::TITLE_SYNC]);
        if ($title_sync) {
            update_post_meta($post_id, self::TITLE_SYNC, '1');
            $post_obj = get_post($post_id);
            $_POST['_tqk_wpseo_title'] = wp_slash((string) ($post_obj->post_title ?? ''));
        } else {
            delete_post_meta($post_id, self::TITLE_SYNC);
        }
        // "Same as Google": share texts stay empty, so they follow the SEO ones.
        $social_sync = isset($_POST[self::SOCIAL_SYNC]);
        $social_texts = ['_tqk_wpseo_og_title', '_tqk_wpseo_og_description', '_tqk_wpseo_twitter_title', '_tqk_wpseo_twitter_description'];

        foreach (self::fields() as $key => [$label, $type]) {
            if ($social_sync && in_array($key, $social_texts, true)) {
                delete_post_meta($post_id, $key);
                continue;
            }
            if ($type === 'checkbox') {
                if (!isset($_POST[$key])) {
                    delete_post_meta($post_id, $key);
                    continue;
                }
                update_post_meta($post_id, $key, '1');
                continue;
            }
            if (!isset($_POST[$key])) {
                continue;
            }
            $raw = wp_unslash($_POST[$key]);
            $val = ($type === 'url' || $type === 'image') ? esc_url($raw) : sanitize_text_field($raw);
            if ($key === '_tqk_wpseo_robots' && !array_key_exists($val, self::robot_options())) {
                continue;
            }
            if ($key === '_tqk_wpseo_schema_type' && !array_key_exists($val, self::schema_options())) {
                continue;
            }
            if ($val === '') {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $val);
            }
        }

        self::save_schema_fields($post_id);

        // Values from a pending AI suggestion were filled and saved: it is done.
        if (!empty($_POST['tqk_wpseo_ai_applied'])) {
            \TQK_WPSEO\MCP\Reports::close_suggestion($post_id, 'applied');
        }

        Data::invalidate_for_post($post_id);
        delete_post_meta($post_id, '_tqk_wpseo_audit_cache');
        Queue::push($post_id);
    }

    /**
     * Dynamic schema fields. Only keys declared in the spec for the saved type
     * are written, so a hand-crafted POST cannot stuff arbitrary meta in.
     */
    private static function save_schema_fields(int $post_id): void {
        // Iterate the whole spec table, not just the active type, so switching
        // type and saving does not silently drop the data already entered.
        foreach (self::schema_field_blocks() as $block) {
            foreach ($block['fields'] as $spec) {
                $key = (string) $spec['key'];
                $raw = isset($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : '';
                $val = $spec['type'] === 'textarea'
                    ? sanitize_textarea_field($raw)
                    : ($spec['type'] === 'url' ? esc_url($raw) : sanitize_text_field($raw));
                $val === '' ? delete_post_meta($post_id, $key) : update_post_meta($post_id, $key, $val);
            }
        }
    }
}
