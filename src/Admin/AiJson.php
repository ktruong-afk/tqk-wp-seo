<?php
namespace TQK_WPSEO\Admin;

use TQK_WPSEO\Schema\Fields;

defined('ABSPATH') || exit;

/**
 * JSON in / out of the post editor, for any outside AI (Claude, ChatGPT…).
 *
 * Export: the page's context, its current SEO values and the exact reply
 * format (short keys). Import: the AI's reply, OR structured data (JSON-LD)
 * copied from another page — the schema type is chosen and its form filled.
 *
 * Nothing is saved here: the browser fills the fields, the editor reviews
 * them and saves the post like any other change.
 */
class AiJson {

    /** Short reply key => meta key, for the plain SEO fields. */
    public const KEYS = [
        'focus_keyword' => '_tqk_wpseo_focus_keyword',
        'secondary_keywords' => '_tqk_wpseo_secondary_keywords',
        'title' => '_tqk_wpseo_title',
        'description' => '_tqk_wpseo_description',
        'canonical' => '_tqk_wpseo_canonical',
        'robots' => '_tqk_wpseo_robots',
        'og_title' => '_tqk_wpseo_og_title',
        'og_description' => '_tqk_wpseo_og_description',
        'twitter_title' => '_tqk_wpseo_twitter_title',
        'twitter_description' => '_tqk_wpseo_twitter_description',
        'schema_type' => '_tqk_wpseo_schema_type',
    ];

    /**
     * Never imported: ratings copied from elsewhere (or made up by an AI) are
     * fake reviews for Google — a manual action risk for the whole site.
     */
    public const BLOCKED = ['course_rating', 'course_rating_count'];

    private const HINTS = [
        'focus_keyword' => 'main search phrase, 2-5 words, as people type it',
        'secondary_keywords' => 'up to 4 related phrases, comma separated',
        'title' => 'SEO title, max ~580px (about 55-60 chars), focus keyword near the start',
        'description' => 'meta description, max ~920px (about 150-160 chars), contains the focus keyword, ends with a reason to click',
        'canonical' => 'only if this page is a copy of another URL on this same site; otherwise omit',
        'robots' => 'one of: "", "index,follow", "noindex,follow", "noindex,nofollow"; omit unless sure',
        'og_title' => 'Facebook / Zalo share title (optional, falls back to title)',
        'og_description' => 'Facebook / Zalo share text (optional, falls back to description)',
        'twitter_title' => 'optional',
        'twitter_description' => 'optional',
        'schema_type' => 'one of the schema types listed in allowed_schema_types',
    ];

    /** Every importable short key => [meta key, field type, label]. */
    public static function map(): array {
        $fields = Metabox::fields();
        $out = [];
        foreach (self::KEYS as $short => $meta) {
            $out[$short] = [$meta, (string) ($fields[$meta][1] ?? 'text'), (string) ($fields[$meta][0] ?? $short)];
        }
        foreach (Fields::specs() as $specs) {
            foreach ($specs as $name => $spec) {
                if (in_array($name, self::BLOCKED, true) || isset($out[$name])) {
                    continue;
                }
                $out[$name] = [(string) $spec['key'], (string) $spec['type'], (string) $spec['label']];
            }
        }
        return $out;
    }

    /** Words of page text sent in a bundle: enough to understand the page. */
    public const MAX_WORDS = 1000;

    /** Reply keys of each bundle. */
    public const BUNDLES = [
        'seo' => ['focus_keyword', 'secondary_keywords', 'title', 'description'],
        'social' => ['og_title', 'og_description', 'twitter_title', 'twitter_description'],
        'schema' => ['schema_type'],
    ];

    /**
     * The page as plain text: no tags, no shortcodes, no scripts, spaces
     * collapsed, cut at MAX_WORDS words (on a word boundary).
     *
     * @return array{text:string,words:int,cut:bool}
     */
    public static function plain_text(int $id): array {
        $post = get_post($id);
        [$html] = $post ? \TQK_WPSEO\Analyzer\Analyzer::content_for($post) : ['', ''];
        $html = (string) preg_replace('/\[\/?[A-Za-z][\w-]*(?:\s[^\]]*)?\]/', ' ', (string) $html);
        $text = trim((string) preg_replace('/\s+/u', ' ', \TQK_WPSEO\Analyzer\Text::plain($html)));
        $words = $text === '' ? [] : preg_split('/\s+/u', $text);
        $cut = count($words) > self::MAX_WORDS;
        return [
            'text' => $cut ? implode(' ', array_slice($words, 0, self::MAX_WORDS)) . ' …' : $text,
            'words' => count($words),
            'cut' => $cut,
        ];
    }

    /**
     * One self-contained bundle for an outside AI. Content comes FIRST in
     * every bundle (the AI must read the page before it can suggest a title),
     * then the current values and the task, with only that task's keys.
     * Small assistants (Gemini in Chrome, free tiers) handle one short bundle
     * at a time far better than one big dump.
     *
     * @param string $bundle content | seo | social | schema | all
     * @return array|string JSON-able array, or plain text for "content".
     */
    public static function bundle(int $id, string $bundle) {
        $post = get_post($id);
        $c = self::plain_text($id);
        if ($bundle === 'content') {
            return (string) ($post->post_title ?? '') . "\n" . (string) get_permalink($id) . "\n\n" . $c['text'];
        }
        if ($bundle === 'all' || !isset(self::BUNDLES[$bundle])) {
            $all = self::export($id);
            $all['page'] = ['content' => $c['text']] + $all['page'];
            return $all;
        }
        $keys = self::BUNDLES[$bundle];
        $reply = array_intersect_key(self::HINTS, array_flip($keys));
        $current = [];
        foreach (self::map() as $short => [$meta]) {
            $v = (string) get_post_meta($id, $meta, true);
            if ($v !== '' && (in_array($short, $keys, true) || ($bundle === 'schema' && !isset(self::KEYS[$short])) || ($bundle === 'social' && in_array($short, ['title', 'description'], true)))) {
                $current[$short] = $v;
            }
        }
        $p = Metabox::preview($id);
        $task = [
            'seo' => 'Suggest the focus keyword, up to 4 secondary keywords, an SEO title (max ~60 characters, keyword near the start) and a meta description (max ~155 characters, with the keyword, a reason to click).',
            'social' => 'Suggest the share title and text for Facebook / Zalo (og_*) and X (twitter_*): catchy, true to the page, no clickbait.',
            'schema' => 'Choose the schema type that fits the page (one of allowed_schema_types) and fill its fields from the content. Only facts found in the content.',
        ][$bundle];
        $out = [
            'page' => [
                'content' => $c['text'],
                'content_words' => $c['words'] . ($c['cut'] ? ' (first ' . self::MAX_WORDS . ' sent)' : ''),
                'title' => (string) ($post->post_title ?? ''),
                'url' => (string) get_permalink($id),
                'language' => (string) get_bloginfo('language'),
            ],
            'current' => $current,
            'task' => $task,
            'reply_format' => '{"tqk_seo":1,"post_id":' . $id . ',"fields":{...}} — JSON only, only these keys, leave out what you would not change. Same language as the page (Vietnamese: full diacritics). No invented facts, prices, ratings or reviews.',
            'reply_keys' => $reply,
            'post_id' => $id,
            'tqk_seo' => 1,
        ];
        if ($bundle === 'seo') {
            $out['shown_in_google_now'] = ['title' => (string) ($p['title'] ?? ''), 'description' => (string) ($p['description'] ?? '')];
        }
        if ($bundle === 'schema') {
            $type = (string) get_post_meta($id, '_tqk_wpseo_schema_type', true);
            $fields = [];
            foreach (Fields::specs() as $t => $specs) {
                if ($type !== '' && $t !== $type) {
                    continue;
                }
                foreach ($specs as $name => $spec) {
                    if (!in_array($name, self::BLOCKED, true)) {
                        $fields[$t][$name] = (string) $spec['label'] . ($spec['type'] === 'textarea' ? ' (one per line)' : '');
                    }
                }
            }
            $out['allowed_schema_types'] = array_values(array_filter(array_keys(Metabox::schema_options())));
            $out['schema_fields'] = $fields;
        }
        return $out;
    }

    /** Package handed to the outside AI (everything, for strong models). */
    public static function export(int $id): array {
        $post = get_post($id);
        $current = [];
        foreach (self::map() as $short => [$meta]) {
            $v = (string) get_post_meta($id, $meta, true);
            if ($v !== '') {
                $current[$short] = $v;
            }
        }
        $p = Metabox::preview($id);
        [$html] = $post ? \TQK_WPSEO\Analyzer\Analyzer::content_for($post) : ['', ''];
        $text = preg_replace('/\s+/u', ' ', \TQK_WPSEO\Analyzer\Text::plain((string) $html));
        $problems = [];
        foreach ((array) ((\TQK_WPSEO\Analyzer\Analyzer::stored($id) ?? [])['findings'] ?? []) as $f) {
            if (in_array($f['severity'] ?? '', ['critical', 'warning'], true)) {
                $problems[] = wp_strip_all_tags((string) $f['message']);
            }
        }
        $reply = [];
        foreach (self::KEYS as $short => $meta) {
            $reply[$short] = self::HINTS[$short];
        }
        $schema = [];
        foreach (Fields::specs() as $type => $specs) {
            foreach ($specs as $name => $spec) {
                if (!in_array($name, self::BLOCKED, true)) {
                    $schema[$type][$name] = (string) $spec['label'] . ($spec['type'] === 'textarea' ? ' (one item per line)' : '');
                }
            }
        }
        $headings = array_map(fn($h) => 'H' . $h[0] . ': ' . $h[1], \TQK_WPSEO\Analyzer\Text::headings((string) $html));

        return [
            'tqk_seo' => 1,
            'post_id' => $id,
            'instructions' => implode("\n", [
                'You are helping with the SEO of one web page. Read "page" and "current".',
                'Reply with ONE JSON object only (no text around it) in this shape: {"tqk_seo":1,"post_id":' . $id . ',"fields":{...}}.',
                'Put in "fields" only the keys you want to change, using exactly the key names of "reply_keys" (and "schema_fields" if a schema fits the page).',
                'Write in the same language as the page content. For Vietnamese keep full diacritics.',
                'Do not invent facts, prices, ratings or reviews. Leave a key out when unsure.',
                'If many things are missing, fix the most important first: focus_keyword, title, description, then social, then schema.',
            ]),
            'page' => [
                'title' => $post ? (string) $post->post_title : '',
                'url' => (string) get_permalink($id),
                'type' => (string) get_post_type($id),
                'language' => (string) get_bloginfo('language'),
                'site_name' => (string) get_bloginfo('name'),
                'headings' => array_slice($headings, 0, 40),
                'words' => count(preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY)),
                'content' => function_exists('mb_substr') ? mb_substr((string) $text, 0, 8000) : substr((string) $text, 0, 8000),
            ],
            'current' => $current,
            'shown_in_google_now' => ['title' => (string) ($p['title'] ?? ''), 'description' => (string) ($p['description'] ?? '')],
            'problems_found' => array_slice($problems, 0, 20),
            'reply_keys' => $reply,
            'allowed_schema_types' => array_values(array_filter(array_keys(Metabox::schema_options()))),
            'schema_fields' => $schema,
        ];
    }

    /**
     * Reads a pasted reply or JSON-LD. Only whitelisted keys come out, each
     * sanitised for its field type.
     *
     * @return array{kind:string,fields:array<string,string>,labels:array<string,string>,notes:string[],dropped:string[],post_mismatch:bool}|array{error:string}
     */
    public static function parse(string $raw, int $id): array {
        $docs = self::decode($raw);
        if ($docs === []) {
            return ['error' => __('This is not valid JSON. Paste the whole reply (or the JSON-LD block).', 'tqk-wpseo')];
        }
        $out = ['kind' => 'reply', 'fields' => [], 'labels' => [], 'notes' => [], 'dropped' => [], 'post_mismatch' => false];
        $first = $docs[0];
        $is_reply = count($docs) === 1 && is_array($first) && !isset($first['@type']) && !isset($first['@graph']) && !isset($first['@context'])
            && (isset($first['fields']) || isset($first['tqk_seo']) || array_intersect_key($first, self::map()) !== []);
        if ($is_reply) {
            $pid = (int) ($first['post_id'] ?? 0);
            $out['post_mismatch'] = $pid > 0 && $pid !== $id;
            $out['reply_post_id'] = $pid;
            $values = is_array($first['fields'] ?? null) ? $first['fields'] : $first;
        } else {
            $out['kind'] = 'jsonld';
            [$values, $notes, $dropped] = self::from_jsonld($docs);
            $out['notes'] = $notes;
            $out['dropped'] = $dropped;
        }
        $map = self::map();
        foreach ((array) $values as $k => $v) {
            $k = (string) $k;
            if (in_array($k, self::BLOCKED, true)) {
                $out['dropped'][] = __('Ratings are never imported: copied or invented ratings count as fake reviews for Google.', 'tqk-wpseo');
                continue;
            }
            if (!isset($map[$k])) {
                continue;
            }
            [$meta, $type, $label] = $map[$k];
            $clean = self::clean($k, $type, $v);
            if ($clean === null) {
                $out['notes'][] = sprintf(__('%s: value not accepted, skipped.', 'tqk-wpseo'), $label);
                continue;
            }
            $out['fields'][$meta] = $clean;
            $out['labels'][$meta] = $label;
        }
        $out['notes'] = array_values(array_unique($out['notes']));
        $out['dropped'] = array_values(array_unique($out['dropped']));
        if ($out['fields'] === [] && $out['notes'] === [] && $out['dropped'] === []) {
            $out['notes'][] = __('No known key found. Use the key names from the exported JSON.', 'tqk-wpseo');
        }
        return $out;
    }

    /**
     * Accepts a bare JSON value, text around it (```json fences, chat noise),
     * or one or more <script type="application/ld+json"> blocks from a page.
     *
     * @return array<int,array> Decoded documents.
     */
    public static function decode(string $raw): array {
        $raw = trim($raw);
        $chunks = [];
        if (preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#is', $raw, $m)) {
            $chunks = $m[1];
        } else {
            $raw = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $raw);
            $chunks = [$raw];
        }
        $docs = [];
        foreach ($chunks as $c) {
            $c = trim((string) $c);
            $d = json_decode($c, true);
            if (!is_array($d)) {
                // Text around the object: take the outermost {...} or [...].
                $s = strpos($c, '{');
                $a = strpos($c, '[');
                $start = $s === false ? $a : ($a === false ? $s : min($s, $a));
                $end = max((int) strrpos($c, '}'), (int) strrpos($c, ']'));
                if ($start !== false && $end > $start) {
                    $d = json_decode(substr($c, $start, $end - $start + 1), true);
                }
            }
            if (!is_array($d)) {
                continue;
            }
            if (array_is_list($d)) {
                foreach ($d as $item) {
                    if (is_array($item)) {
                        $docs[] = $item;
                    }
                }
            } else {
                $docs[] = $d;
            }
        }
        return $docs;
    }

    /** Short type name: "https://schema.org/FAQPage" or ["Product","X"] → "FAQPage" / "Product". */
    private static function type_of(array $node): string {
        $t = $node['@type'] ?? '';
        if (is_array($t)) {
            $t = (string) ($t[0] ?? '');
        }
        $t = (string) $t;
        $pos = strrpos($t, '/');
        return $pos === false ? $t : substr($t, $pos + 1);
    }

    /** Which of our schema families a type belongs to, '' if none. */
    private static function family(string $t): string {
        if (in_array($t, ['FAQPage', 'HowTo', 'Recipe', 'Course', 'Product'], true)) {
            return $t;
        }
        if ($t === 'BlogPosting') {
            return 'BlogPosting';
        }
        if (in_array($t, ['Article', 'NewsArticle', 'TechArticle', 'Report', 'ScholarlyArticle'], true)) {
            return 'Article';
        }
        if ($t === 'Event' || preg_match('/Event$/', $t)) {
            return 'Event';
        }
        if (in_array($t, ['LocalBusiness', 'Organization', 'Store', 'Restaurant', 'Corporation'], true)
            || preg_match('/(Business|Store|Shop|Restaurant|Clinic|Dentist|Hotel|Agency|Office|Service)$/', $t)) {
            return 'Business';
        }
        return '';
    }

    /** Walks @graph / nested objects; a matched node is not searched further. */
    private static function collect($node, array &$found, bool &$rating, int $depth = 0): void {
        if (!is_array($node) || $depth > 12) {
            return;
        }
        if (isset($node['aggregateRating']) || isset($node['review'])) {
            $rating = true;
        }
        $fam = isset($node['@type']) ? self::family(self::type_of($node)) : '';
        if ($fam !== '') {
            $found[$fam][] = $node;
            if ($fam !== 'Article' && $fam !== 'BlogPosting') {
                return;
            }
        }
        foreach ($node as $k => $child) {
            if (is_array($child) && $k !== 'aggregateRating' && $k !== 'review') {
                self::collect($child, $found, $rating, $depth + 1);
            }
        }
    }

    /** @return array{0:array<string,mixed>,1:string[],2:string[]} values, notes, dropped */
    private static function from_jsonld(array $docs): array {
        $found = [];
        $rating = false;
        foreach ($docs as $d) {
            self::collect($d, $found, $rating);
        }
        $v = [];
        $notes = [];
        $dropped = [];
        if ($rating) {
            $dropped[] = __('Ratings / reviews in that JSON-LD were not imported: copied ratings count as fake reviews for Google (manual action risk).', 'tqk-wpseo');
        }
        foreach (['Recipe', 'HowTo', 'Course', 'Event', 'FAQPage', 'Product', 'BlogPosting', 'Article'] as $fam) {
            if (!empty($found[$fam])) {
                $v['schema_type'] = $fam;
                break;
            }
        }
        if (!empty($found['FAQPage'])) {
            $pairs = [];
            foreach ($found['FAQPage'] as $n) {
                foreach (self::as_list($n['mainEntity'] ?? []) as $q) {
                    $question = self::text($q['name'] ?? '');
                    $answer = self::text(is_array($q['acceptedAnswer'] ?? null) ? (self::as_list($q['acceptedAnswer'])[0]['text'] ?? '') : '');
                    if ($question !== '' && $answer !== '') {
                        $pairs[] = $question . "\n" . $answer;
                    }
                }
            }
            if ($pairs !== []) {
                $v['faq_items'] = implode("\n\n", $pairs);
            }
        }
        if (!empty($found['HowTo'])) {
            $n = $found['HowTo'][0];
            $v += array_filter([
                'howto_name' => self::text($n['name'] ?? ''),
                'howto_description' => self::text($n['description'] ?? ''),
                'howto_total_time' => self::text($n['totalTime'] ?? ''),
                'howto_tools' => implode("\n", self::names($n['tool'] ?? [])),
                'howto_supplies' => implode("\n", self::names($n['supply'] ?? [])),
                'howto_steps' => implode("\n", self::steps($n['step'] ?? [])),
            ]);
        }
        if (!empty($found['Recipe'])) {
            $n = $found['Recipe'][0];
            $yield = $n['recipeYield'] ?? '';
            $v += array_filter([
                'recipe_name' => self::text($n['name'] ?? ''),
                'recipe_description' => self::text($n['description'] ?? ''),
                'recipe_yield' => self::text(is_array($yield) ? (self::as_list($yield)[0] ?? '') : $yield),
                'prep_time' => self::text($n['prepTime'] ?? ''),
                'cook_time' => self::text($n['cookTime'] ?? ''),
                'total_time' => self::text($n['totalTime'] ?? ''),
                'ingredients' => implode("\n", self::names($n['recipeIngredient'] ?? ($n['ingredients'] ?? []))),
                'instructions' => implode("\n", self::steps($n['recipeInstructions'] ?? [])),
            ]);
        }
        if (!empty($found['Course'])) {
            $n = $found['Course'][0];
            $level = strtolower(self::text($n['educationalLevel'] ?? ''));
            $hours = '';
            foreach (self::as_list($n['hasCourseInstance'] ?? []) as $inst) {
                if (preg_match('/PT(\d+)H/i', (string) ($inst['courseWorkload'] ?? ''), $hm)) {
                    $hours = $hm[1];
                }
            }
            $v += array_filter([
                'course_provider' => self::text($n['provider'] ?? ''),
                'course_description' => self::text($n['description'] ?? ''),
                'course_level' => str_contains($level, 'begin') ? 'beginner' : (str_contains($level, 'inter') ? 'intermediate' : (str_contains($level, 'adv') ? 'advanced' : '')),
                'course_hours' => $hours,
            ]);
            $notes[] = __('The course provider was read from the other page: change it to your own name.', 'tqk-wpseo');
        }
        if (!empty($found['Event'])) {
            $n = $found['Event'][0];
            $loc = self::as_list($n['location'] ?? [])[0] ?? [];
            $mode = (string) ($n['eventAttendanceMode'] ?? '');
            $mode = ($pos = strrpos($mode, '/')) === false ? $mode : substr($mode, $pos + 1);
            $v += array_filter([
                'event_start' => self::text($n['startDate'] ?? ''),
                'event_end' => self::text($n['endDate'] ?? ''),
                'event_location' => is_array($loc) ? self::text($loc['name'] ?? ($loc['url'] ?? '')) : self::text($loc),
                'event_address' => is_array($loc) ? self::address($loc['address'] ?? '') : '',
                'event_offers' => $mode,
            ]);
        }
        if (!empty($found['Product'])) {
            $notes[] = __('Product: price, stock and SKU always come from the WooCommerce product itself, so only the schema type was taken.', 'tqk-wpseo');
        }
        if (!empty($found['Business'])) {
            $b = $found['Business'][0];
            $notes[] = sprintf(
                __('Business details found (%s) were not imported: your own name, phone and address belong in Settings.', 'tqk-wpseo'),
                implode(' · ', array_filter([self::text($b['name'] ?? ''), self::text($b['telephone'] ?? ''), self::address($b['address'] ?? '')]))
            );
        }
        if (!isset($v['schema_type']) && empty($found['Business'])) {
            $notes[] = __('No schema type this plugin can fill was found in that JSON-LD.', 'tqk-wpseo');
        }
        return [$v, $notes, $dropped];
    }

    /** Wraps a single object so it can be iterated like a list. */
    private static function as_list($v): array {
        if (!is_array($v)) {
            return $v === '' || $v === null ? [] : [$v];
        }
        return array_is_list($v) ? $v : [$v];
    }

    /** Plain text from a string, a {name}/{text} object or a list. */
    private static function text($v): string {
        if (is_array($v)) {
            if (isset($v['name']) || isset($v['text'])) {
                $v = $v['name'] ?? $v['text'];
            } elseif (array_is_list($v)) {
                $v = implode(', ', array_map([self::class, 'text'], $v));
            } else {
                return '';
            }
        }
        $v = html_entity_decode(wp_strip_all_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/[ \t]+/u', ' ', $v));
    }

    /** @return string[] */
    private static function names($v): array {
        return array_values(array_filter(array_map([self::class, 'text'], self::as_list($v))));
    }

    /** HowTo / recipe steps: strings, HowToStep, HowToSection (nested). @return string[] */
    private static function steps($v, int $depth = 0): array {
        if (is_string($v)) {
            return array_values(array_filter(array_map('trim', preg_split('/\r?\n+/', self::text($v)))));
        }
        $out = [];
        foreach (self::as_list($v) as $s) {
            if (is_array($s) && isset($s['itemListElement']) && $depth < 4) {
                $out = array_merge($out, self::steps($s['itemListElement'], $depth + 1));
            } elseif (is_array($s)) {
                $t = self::text($s['text'] ?? ($s['name'] ?? ''));
                if ($t !== '') {
                    $out[] = $t;
                }
            } else {
                $t = self::text($s);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }
        return $out;
    }

    private static function address($a): string {
        if (!is_array($a)) {
            return self::text($a);
        }
        $parts = [];
        foreach (['streetAddress', 'addressLocality', 'addressRegion', 'addressCountry'] as $k) {
            $t = self::text($a[$k] ?? '');
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        return implode(', ', $parts);
    }

    /** Sanitised value for one field, null when it cannot be accepted. */
    private static function clean(string $short, string $type, $v): ?string {
        if ($short === 'faq_items' && is_array($v)) {
            $pairs = [];
            foreach ($v as $item) {
                if (is_array($item)) {
                    $q = self::text($item['question'] ?? ($item['q'] ?? ''));
                    $a = self::text($item['answer'] ?? ($item['a'] ?? ''));
                    if ($q !== '' && $a !== '') {
                        $pairs[] = $q . "\n" . $a;
                    }
                }
            }
            $v = implode("\n\n", $pairs);
        } elseif (is_array($v)) {
            $v = $type === 'textarea' ? implode("\n", self::names($v)) : implode(', ', self::names($v));
        }
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);
        switch ($short) {
            case 'robots':
                return array_key_exists($v, Metabox::robot_options()) ? $v : null;
            case 'schema_type':
                return array_key_exists($v, Metabox::schema_options()) ? $v : null;
            case 'secondary_keywords':
                $list = array_slice(array_filter(array_map('trim', explode(',', sanitize_text_field($v)))), 0, 4);
                return implode(', ', $list);
            case 'canonical':
                // Only this site: a canonical to another domain hands this
                // page's ranking to that domain.
                $url = esc_url_raw($v);
                $home = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
                return $url !== '' && (string) wp_parse_url($url, PHP_URL_HOST) === $home ? $url : null;
        }
        switch ($type) {
            case 'textarea':
                return sanitize_textarea_field($v);
            case 'number':
                return is_numeric($v) ? (string) (0 + $v) : null;
            case 'url':
                return esc_url_raw($v);
            case 'datetime':
                if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $v, $m)) {
                    return $m[1] . 'T' . ($m[2] ?? '00:00');
                }
                return null;
            case 'select':
                foreach (Fields::specs() as $specs) {
                    if (isset($specs[$short])) {
                        return array_key_exists($v, (array) $specs[$short]['options']) ? $v : null;
                    }
                }
                return null;
        }
        return sanitize_text_field($v);
    }
}
