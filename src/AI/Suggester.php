<?php
namespace TQK_WPSEO\AI;

defined('ABSPATH') || exit;

use TQK_WPSEO\Analyzer\Text;

/**
 * S6 — Suggestion prompts + response parsing + AJAX endpoint.
 * Flow is always preview → approve: options return to the modal, the user
 * clicks to fill the field, nothing writes to production by itself.
 */
class Suggester {

    public static function hooks(): void {
        add_action('wp_ajax_tqk_wpseo_ai', [self::class, 'ajax']);
    }

    public static function prompt_title($post, array $data): string {
        return self::biz_context()
            . "Write 5 SEO title options (max 60 characters each) for this post. "
            . "Current title: {$data['title']}. Post title: {$post->post_title}. "
            . 'Reply with a numbered list only, no explanations.';
    }

    public static function prompt_description($post, array $data): string {
        $plain = substr(Text::plain((string) ($post->post_content ?? '')), 0, 1500);
        return self::biz_context()
            . "Write 3 meta description options (150-160 characters each) for this post. "
            . "Content excerpt: {$plain} Reply with a numbered list only, no explanations.";
    }

    public static function prompt_keywords($post): string {
        $plain = substr(Text::plain((string) ($post->post_content ?? '')), 0, 2000);
        return self::biz_context()
            . "Extract 1 primary keyword and up to 5 secondary keywords from this article. "
            . "Content: {$plain} Reply with a numbered list only, no explanations.";
    }

    public static function prompt_explain(array $result): string {
        $lines = [];
        foreach ((array) ($result['findings'] ?? []) as $f) {
            $lines[] = '- ' . ($f['message'] ?? ($f['id'] ?? ''));
        }
        return "Explain these SEO findings in plain language with one fix suggestion each:\n" . implode("\n", $lines);
    }

    /** @param array $candidates Each: [id, title, excerpt]. */
    public static function prompt_links($post, array $candidates): string {
        $lines = [];
        foreach ($candidates as $c) {
            $lines[] = "- [{$c['id']}] {$c['title']}: " . substr((string) ($c['excerpt'] ?? ''), 0, 200);
        }
        return "This post is titled '{$post->post_title}'. Which 3 of these posts are most topically related "
            . "as internal link targets? Reply with a numbered list of [id] plus one short reason each.\n" . implode("\n", $lines);
    }

    public static function prompt_schema($post, array $data): string {
        return self::biz_context()
            . "Post type: {$post->post_type}. Title: {$data['title']}. "
            . 'Which single Schema.org type fits best (Article, BlogPosting, WebPage, Product, Course, FAQPage, HowTo, Recipe, Event)? '
            . 'Reply with the type name only.';
    }

    /** Business context from setup: industry/audience/tone feed every prompt. */
    public static function biz_context(): string {
        $g = fn($k) => trim((string) \TQK_WPSEO\Admin\Settings::get($k, ''));
        $parts = [];
        if ($g('business_name') !== '') {
            $parts[] = 'Business: ' . $g('business_name');
        }
        if ($g('business_industry') !== '') {
            $parts[] = 'Industry: ' . $g('business_industry');
        }
        if ($g('business_description') !== '') {
            $parts[] = 'About: ' . mb_substr($g('business_description'), 0, 300);
        }
        if ($g('business_audience') !== '') {
            $parts[] = 'Audience: ' . $g('business_audience');
        }
        if ($g('business_tone') !== '') {
            $parts[] = 'Tone: ' . $g('business_tone');
        }
        return $parts === [] ? '' : 'Context: ' . implode(' | ', $parts) . ". ";
    }

    /** @return string[] Cleaned numbered/bulleted list items. */
    public static function parse_list(string $raw): array {
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            $line = (string) preg_replace('/^(\d+[.)\-]|\*|-)\s*/', '', $line);
            $line = trim($line, " \"'");
            if ($line !== '' && mb_strlen($line) < 300) {
                $out[] = $line;
            }
        }
        return array_values(array_unique(array_slice($out, 0, 8)));
    }

    /** @return array|string[] Options or ['error' => msg]. */
    public static function generate(string $type, int $post_id, ?ProviderInterface $provider = null): array {
        $provider = $provider ?? Factory::create();
        if (!$provider) {
            return ['error' => __('Enable Local AI in Settings first.', 'tqk-wpseo')];
        }
        $post = get_post($post_id);
        if (!$post) {
            return ['error' => __('Post not found.', 'tqk-wpseo')];
        }
        $data = \TQK_WPSEO\Core\Data::for(['type' => 'singular', 'object_id' => $post_id, 'post_type' => $post->post_type ?? 'post']);
        $raw = match ($type) {
            'title' => $provider->generate(self::prompt_title($post, $data)),
            'description' => $provider->generate(self::prompt_description($post, $data)),
            'keywords' => $provider->generate(self::prompt_keywords($post)),
            'explain' => $provider->generate(self::prompt_explain(\TQK_WPSEO\Analyzer\Analyzer::analyze($post_id))),
            'links' => $provider->generate(self::prompt_links($post, self::candidates($post_id))),
            'schema' => $provider->generate(self::prompt_schema($post, $data)),
            default => '',
        };
        if (trim($raw) === '') {
            return ['error' => __('The AI provider returned nothing. Check endpoint and model.', 'tqk-wpseo')];
        }
        return $type === 'explain' ? [$raw] : self::parse_list($raw);
    }

    /** @return array Candidate link targets (recent published, excluding self). */
    public static function candidates(int $post_id, int $limit = 15): array {
        $out = [];
        foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => ['publish'], 'numberposts' => $limit + 1]) as $p) {
            if ((int) $p->ID === $post_id) {
                continue;
            }
            $out[] = ['id' => (int) $p->ID, 'title' => (string) $p->post_title, 'excerpt' => (string) $p->post_excerpt];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Bulk generate for up to 10 posts (local LLM, sequential). @return array id => options|['error']. */
    public static function generate_bulk(string $type, array $ids, ?ProviderInterface $provider = null): array {
        $provider = $provider ?? Factory::create();
        if (!$provider) {
            $out = [];
            foreach (array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 10) as $id) {
                $out[$id] = ['error' => __('Enable Local AI in Settings first.', 'tqk-wpseo')];
            }
            return $out;
        }
        $out = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 10) as $id) {
            if ($id <= 0 || !get_post($id)) {
                continue;
            }
            $out[$id] = self::generate($type, $id, $provider);
        }
        return $out;
    }

    public static function ajax(): void {
        check_ajax_referer('tqk-wpseo-ai');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wpseo')]);
        }
        $type = sanitize_key($_POST['ai_type'] ?? '');
        if (!in_array($type, ['title', 'description', 'keywords'], true)) {
            wp_send_json_error(['message' => __('Unknown action', 'tqk-wpseo')]);
        }
        $raw_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? $_POST['post_ids'] : [];
        if ($raw_ids !== []) {
            wp_send_json_success(['results' => self::generate_bulk($type, $raw_ids)]);
        }
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $result = self::generate($type, $post_id);
        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }
        wp_send_json_success(['options' => $result]);
    }
}
