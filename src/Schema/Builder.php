<?php
namespace TQK_WPSEO\Schema;

defined('ABSPATH') || exit;

/**
 * S15 — Builds real schema.org nodes from a post's own data.
 *
 * The rule that matters: an incomplete node is worse than no node. If required
 * data is missing, build() returns [] and Schema::build() keeps the old
 * Article/Product node. A label-swapped Article called "FAQPage" is what this
 * replaces — it never produced a rich result, it just looked like it worked.
 */
class Builder {

    /**
     * @return array Schema node, or [] when the data cannot produce a valid one.
     */
    public static function build(int $post_id, string $type): array {
        if ($post_id <= 0 || $type === '') {
            return [];
        }
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }
        $data = \TQK_WPSEO\Core\Data::for([
            'type' => 'singular',
            'object_id' => $post_id,
            'post_type' => (string) ($post->post_type ?? 'post'),
        ]);
        if (!self::passes($post_id, $type)) {
            return [];
        }
        $meta = self::meta($post_id, $type);

        $node = match ($type) {
            'FAQPage' => self::faq($post, $data, $meta),
            'HowTo'   => self::howto($post, $data, $meta),
            'Recipe'   => self::recipe($post, $data, $meta),
            'Event'    => self::event($post, $data, $meta),
            'Course'   => self::course($post, $data, $meta),
            'Product'  => Nodes::product($post, $data),
            'Article', 'BlogPosting' => Nodes::article($post, $data, $type),
            'WebPage'  => Nodes::webpage($data),
            default    => [],
        };
        return self::prune($node);
    }

    /** Data must be valid before we will emit it. */
    public static function passes(int $post_id, string $type): bool {
        return Validator::is_valid($post_id, $type);
    }

    /** @return array<string,string> Trimmed field values for a type. */
    private static function meta(int $post_id, string $type): array {
        $out = [];
        foreach (Fields::for($type) as $name => $spec) {
            $out[$name] = trim((string) get_post_meta($post_id, (string) $spec['key'], true));
        }
        return $out;
    }

    // ---------------- FAQPage ----------------

    private static function faq($post, array $data, array $meta): array {
        $pairs = self::parse_faq((string) ($meta['faq_items'] ?? ''));
        $entities = [];
        foreach ($pairs as [$q, $a]) {
            $entities[] = [
                '@type' => 'Question',
                'name' => $q,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
            ];
        }
        if ($entities === []) {
            return [];
        }
        return [
            '@type' => 'FAQPage',
            '@id' => self::id($data, 'faq'),
            'mainEntity' => $entities,
            'mainEntityOfPage' => ['@id' => self::id($data, 'webpage')],
            'isPartOf' => ['@id' => home_url('/#website')],
        ];
    }

    /** Blank line separates pairs; first line is the question. */
    private static function parse_faq(string $raw): array {
        $out = [];
        foreach (preg_split('/\n\s*\n/', trim($raw)) ?: [] as $block) {
            $lines = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', trim($block))),
                fn($l) => $l !== ''
            ));
            if (count($lines) < 2) {
                continue;
            }
            $question = array_shift($lines);
            $out[] = [$question, trim(implode(' ', $lines))];
        }
        return $out;
    }

    // ---------------- HowTo ----------------

    private static function howto($post, array $data, array $meta): array {
        $steps = self::lines((string) ($meta['howto_steps'] ?? ''));
        if ($steps === []) {
            return [];
        }
        $node = [
            '@type' => 'HowTo',
            '@id' => self::id($data, 'howto'),
            'name' => $meta['howto_name'] !== '' ? $meta['howto_name'] : (string) ($data['title'] ?? ''),
            'isPartOf' => ['@id' => self::id($data, 'webpage')],
            'step' => self::step_nodes($steps),
        ];
        if (($meta['howto_description'] ?? '') !== '') {
            $node['description'] = $meta['howto_description'];
        }
        $time = self::iso_duration((string) ($meta['howto_total_time'] ?? ''));
        if ($time !== '') {
            $node['totalTime'] = $time;
        }
        $tools = self::lines((string) ($meta['howto_tools'] ?? ''));
        if ($tools !== []) {
            $node['tool'] = array_map(fn($t) => ['@type' => 'HowToTool', 'name' => $t], $tools);
        }
        $supplies = self::lines((string) ($meta['howto_supplies'] ?? ''));
        if ($supplies !== []) {
            $node['supply'] = array_map(fn($t) => ['@type' => 'HowToSupply', 'name' => $t], $supplies);
        }
        return $node;
    }

    // ---------------- Recipe ----------------

    private static function recipe($post, array $data, array $meta): array {
        $ingredients = self::lines((string) ($meta['ingredients'] ?? ''));
        $instructions = self::lines((string) ($meta['instructions'] ?? ''));
        if ($ingredients === [] || $instructions === []) {
            return [];
        }
        $node = [
            '@type' => 'Recipe',
            '@id' => self::id($data, 'recipe'),
            'name' => $meta['recipe_name'] !== '' ? $meta['recipe_name'] : (string) ($data['title'] ?? ''),
            'recipeIngredient' => $ingredients,
            'recipeInstructions' => self::step_nodes($instructions),
            'isPartOf' => ['@id' => self::id($data, 'webpage')],
        ];
        $desc = $meta['recipe_description'] !== '' ? $meta['recipe_description'] : (string) ($data['description'] ?? '');
        if ($desc !== '') {
            $node['description'] = $desc;
        }
        if (($meta['recipe_yield'] ?? '') !== '') {
            $node['recipeYield'] = $meta['recipe_yield'];
        }
        foreach (['prep_time' => 'prepTime', 'cook_time' => 'cookTime', 'total_time' => 'totalTime'] as $from => $to) {
            $iso = self::iso_duration((string) ($meta[$from] ?? ''));
            if ($iso !== '') {
                $node[$to] = $iso;
            }
        }
        $img = (string) ($data['og_image'] ?? '');
        if ($img !== '') {
            $node['image'] = $img;
        }
        $author = get_the_author_meta('display_name', (int) ($post->post_author ?? 0));
        if ($author !== '') {
            $node['author'] = ['@type' => 'Person', 'name' => $author];
        }
        return $node;
    }

    // ---------------- Event ----------------

    private static function event($post, array $data, array $meta): array {
        $start = self::iso_date((string) ($meta['event_start'] ?? ''));
        if ($start === '') {
            return [];
        }
        $node = [
            '@type' => 'Event',
            '@id' => self::id($data, 'event'),
            'name' => (string) ($data['title'] ?? ''),
            'startDate' => $start,
            'isPartOf' => ['@id' => self::id($data, 'webpage')],
        ];
        $end = self::iso_date((string) ($meta['event_end'] ?? ''));
        if ($end !== '') {
            $node['endDate'] = $end;
        }
        $loc = trim((string) ($meta['event_location'] ?? ''));
        if ($loc !== '') {
            $place = ['@type' => 'Place', 'name' => $loc];
            $addr = trim((string) ($meta['event_address'] ?? ''));
            if ($addr !== '') {
                $place['address'] = \TQK_WPSEO\Local\Address::postal($addr);
            }
            $node['location'] = $place;
        }
        $mode = trim((string) ($meta['event_offers'] ?? ''));
        if ($mode !== '' && (bool) preg_match('/AttendanceMode$/', $mode)) {
            $node['eventAttendanceMode'] = 'https://schema.org/' . $mode;
        }
        $desc = (string) ($data['description'] ?? '');
        if ($desc !== '') {
            $node['description'] = $desc;
        }
        return $node;
    }

    // ---------------- Course ----------------

    private static function course($post, array $data, array $meta): array {
        $provider = trim((string) ($meta['course_provider'] ?? ''));
        if ($provider === '') {
            return [];
        }
        $node = [
            '@type' => 'Course',
            '@id' => self::id($data, 'course'),
            'name' => (string) ($data['title'] ?? ''),
            'provider' => ['@type' => 'Organization', 'name' => $provider],
            'isPartOf' => ['@id' => self::id($data, 'webpage')],
        ];
        $desc = $meta['course_description'] !== '' ? $meta['course_description'] : (string) ($data['description'] ?? '');
        if ($desc !== '') {
            $node['description'] = $desc;
        }
        $level = trim((string) ($meta['course_level'] ?? ''));
        if ($level !== '') {
            $node['educationalLevel'] = $level;
        }
        $url = trim((string) ($meta['course_url'] ?? ''));
        if ($url !== '') {
            $node['url'] = $url;
        }
        $hours = trim((string) ($meta['course_hours'] ?? ''));
        if ($hours !== '' && is_numeric($hours)) {
            $node['hasCourseInstance'] = [[
                '@type' => 'CourseInstance',
                'courseMode' => 'online',
                'courseWorkload' => self::workload((float) $hours),
            ]];
        }
        // aggregateRating is only emitted with a real count, never a bare score.
        $rating = trim((string) ($meta['course_rating'] ?? ''));
        $count = trim((string) ($meta['course_rating_count'] ?? ''));
        if ($rating !== '' && is_numeric($rating) && $count !== '' && is_numeric($count) && (int) $count > 0) {
            $node['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) $rating,
                'reviewCount' => (string) (int) $count,
            ];
        }
        return $node;
    }

    /** 20 -> "PT20H". */
    private static function workload(float $hours): string {
        $h = (int) floor($hours);
        $m = (int) round(($hours - $h) * 60);
        if ($m === 60) {
            $h++;
            $m = 0;
        }
        return 'PT' . ($h > 0 ? $h . 'H' : '') . ($m > 0 ? $m . 'M' : '');
    }

    // ---------------- Shared helpers ----------------

    private static function step_nodes(array $steps, string $type = 'HowToStep'): array {
        $out = [];
        $pos = 1;
        foreach ($steps as $s) {
            $out[] = ['@type' => $type, 'position' => $pos++, 'text' => $s];
        }
        return $out;
    }

    /** @return string[] Non-empty trimmed lines, numbering stripped. */
    public static function lines(string $raw): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $raw) ?: [] as $line) {
            $out_line = trim(self::strip_step_prefix((string) $line));
            if ($out_line !== '') {
                $out[] = $out_line;
            }
        }
        return $out;
    }

    /**
     * Strips "1.", "Bước 1:", "Buoc 2:" so the editor's numbering never ends up
     * duplicated inside the node text.
     */
    public static function strip_step_prefix(string $line): string {
        $stripped = preg_replace('/^\s*(?:b(?:u|ư)(?:\s*o|ớ)?c\s*)?\d+\s*[.):\-]?\s*/iu', '', $line);
        return trim((string) $stripped);
    }

    /**
     * "30 phút" / "1 giờ 30 phút" / "2 hours" → ISO 8601 duration.
     * Already-ISO input passes through. Anything else → '' so the caller can
     * drop the property instead of emitting a value Google can't read.
     */
    public static function iso_duration(string $raw): string {
        $v = trim($raw);
        if ($v === '') {
            return '';
        }
        if (preg_match('/^P(?:\d+[YMWD])*(?:T(?:\d+[HMS])+)?$/', $v)) {
            return $v;
        }
        $norm = mb_strtolower($v);
        $norm = strtr($norm, [
            'giờ' => 'h', 'gio' => 'h', 'hour' => 'h', 'hours' => 'h', 'hr' => 'h', 'hrs' => 'h', 'tieng' => 'h',
            'phút' => 'm', 'phut' => 'm', 'minute' => 'm', 'minutes' => 'm', 'min' => 'm', 'mins' => 'm',
            'giây' => 's', 'second' => 's', 'seconds' => 's', 'sec' => 's', 'secs' => 's',
        ]);
        if (!preg_match('/(?:(\d+(?:[.,]\d+)?)\s*h)?\s*(?:(\d+(?:[.,]\d+)?)\s*m)?\s*(?:(\d+(?:[.,]\d+)?)\s*s)?/', $norm, $m)) {
            return '';
        }
        $h = (float) str_replace(',', '.', $m[1] ?? 0);
        $mi = (float) str_replace(',', '.', $m[2] ?? 0);
        $s = (float) str_replace(',', '.', $m[3] ?? 0);
        if ($h === 0.0 && $mi === 0.0 && $s === 0.0) {
            return '';
        }
        $out = 'PT';
        $out .= $h > 0 ? rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.') . 'H' : '';
        $out .= $mi > 0 ? rtrim(rtrim(number_format($mi, 2, '.', ''), '0'), '.') . 'M' : '';
        $out .= $s > 0 ? rtrim(rtrim(number_format($s, 2, '.', ''), '0'), '.') . 'S' : '';
        return $out;
    }

    /** "2026-10-01 09:00" (or T-separated) → full ISO 8601 stamp. */
    public static function iso_date(string $raw): string {
        $v = trim(str_replace('T', ' ', $raw));
        if ($v === '') {
            return '';
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ ](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $v, $m)) {
            return '';
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }
        $hh = str_pad((string) ($m[4] ?? '00'), 2, '0', STR_PAD_LEFT);
        $mm = str_pad((string) ($m[5] ?? '00'), 2, '0', STR_PAD_LEFT);
        $ss = str_pad((string) ($m[6] ?? '00'), 2, '0', STR_PAD_LEFT);
        $tz = wp_timezone_string();
        $offset = $tz ? self::offset($tz) : '+00:00';
        return sprintf('%s-%s-%sT%s:%s:%s%s', $m[1], $m[2], $m[3], $hh, $mm, $ss, $offset);
    }

    private static function offset(string $tz): string {
        try {
            $z = new \DateTimeZone($tz);
            $off = $z->getOffset(new \DateTime('now', $z));
        } catch (\Exception $e) {
            return '+00:00';
        }
        if ($off === 0) {
            return '+00:00';
        }
        return ($off > 0 ? '+' : '-') . gmdate('H:i', abs($off));
    }

    private static function id(array $data, string $slug): string {
        $base = trailingslash((string) ($data['canonical'] ?? home_url('/')));
        return $base . '#' . $slug;
    }

    /** Drop empty values so the output never carries a blank property. */
    private static function prune(array $node): array {
        $out = [];
        foreach ($node as $k => $v) {
            if ($v === '' || $v === null || $v === []) {
                continue;
            }
            $out[$k] = is_array($v) ? self::prune($v) : $v;
        }
        return $out;
    }

    // ---------------- Harvest from post content ----------------

    /**
     * Best-effort extraction so nobody types 12 ingredients by hand.
     * Returns only what it actually found; the user edits before saving.
     *
     * @return array<string,string> field name → raw value
     */
    public static function harvest(int $post_id, string $type): array {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }
        $html = (string) ($post->post_content ?? '');
        if (trim($html) === '') {
            return [];
        }
        return match ($type) {
            'HowTo' => self::harvest_howto($html),
            'Recipe' => self::harvest_recipe($html),
            'FAQPage' => self::harvest_faq($html),
            default => [],
        };
    }

    private static function harvest_howto(string $html): array {
        $steps = self::harvest_steps($html);
        return $steps === [] ? [] : ['howto_steps' => implode("\n", $steps)];
    }

    /**
     * Step detection: <ol><li>, "1." / "Bước 1:" line prefixes, and headings
     * that look like a step title (each followed by its own paragraph).
     */
    private static function harvest_steps(string $html): array {
        $out = [];
        if (preg_match_all('#<li[^>]*>(.*?)</li>#is', $html, $m)) {
            foreach ($m[1] as $li) {
                $t = trim(self::to_text($li));
                if ($t !== '') {
                    $out[] = $t;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        $text = self::to_text($html);
        $text = preg_replace('/\n{2,}/', "\n", (string) $text);
        foreach (explode("\n", (string) $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $is_step = (bool) preg_match('/^(?:b[uướ]\w*\s*)?\d+[.)]\s*\S/iu', $line);
            if ($is_step || count($out) > 0) {
                $out[] = $line;
            }
        }
        return array_map([self::class, 'strip_step_prefix'], $out);
    }

    private static function harvest_recipe(string $html): array {
        $out = [];
        $ingredients = self::section_list($html, ['nguyên liệu', 'nguyen lieu', 'ingredients']);
        if ($ingredients !== []) {
            $out['ingredients'] = implode("\n", $ingredients);
        }
        $steps = self::harvest_steps($html);
        if ($steps !== []) {
            $out['instructions'] = implode("\n", $steps);
        }
        return $out;
    }

    private static function harvest_faq(string $html): array {
        // <h3>Question</h3><p>Answer</p> is the pattern most Q&A posts use, but a
        // post has plenty of headings that are not questions. Require one.
        if (!preg_match_all('#<h[23][^>]*>(.*?)</h[23]>\s*(?:<p[^>]*>(.*?)</p>)?#is', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        $pairs = [];
        foreach ($m as $set) {
            $q = trim(self::to_text($set[1]));
            $a = trim(self::to_text($set[2] ?? ''));
            if ($q === '' || $a === '') {
                continue;
            }
            if (!preg_match('/[?？]/u', $q) || mb_strlen($q) > 200) {
                continue;
            }
            $pairs[] = $q . "\n" . $a;
        }
        return $pairs === [] ? [] : ['faq_items' => implode("\n\n", $pairs)];
    }

    /** <ul>/<ol> items under a heading matching one of the labels. */
    private static function section_list(string $html, array $labels): array {
        $t = mb_strtolower($html);
        foreach ($labels as $label) {
            $pos = mb_strpos($t, $label);
            if ($pos === false) {
                continue;
            }
            $slice = substr($html, $pos, 4000);
            if (preg_match('#<ul[^>]*>(.*?)</ul>#is', $slice, $m)) {
                $out = [];
                if (preg_match_all('#<li[^>]*>(.*?)</li>#is', $m[1], $li)) {
                    foreach ($li[1] as $item) {
                        $txt = trim(self::to_text($item));
                        if ($txt !== '') {
                            $out[] = $txt;
                        }
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        }
        return [];
    }

    private static function to_text(string $html): string {
        $t = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html);
        $t = preg_replace('#<br\s*/?>#i', "\n", (string) $t);
        $t = preg_replace('#</(p|div|li|h[1-6])>#i', "\n", (string) $t);
        $t = wp_strip_all_tags((string) $t);
        return trim(preg_replace('/[ \t]+/u', ' ', (string) $t) ?? '');
    }
}
