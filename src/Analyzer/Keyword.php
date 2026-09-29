<?php
namespace TQK_WPSEO\Analyzer;

defined('ABSPATH') || exit;

/** S4 — Focus keyword checks (normalized matching, not raw substring). */
class Keyword {

    /**
     * @return array ['set' => bool, 'checks' => [id => bool], 'density' => float]
     */
    public static function analyze(string $keyword, $post, array $data, string $plain, array $headings, array $images): array {
        $kw = Text::normalize($keyword);
        if ($kw === '') {
            return ['set' => false, 'checks' => [], 'density' => 0.0];
        }
        // Accent-insensitive, syllable-bounded: "an" must not match "bàn".
        $in = fn($hay) => Text::has_phrase((string) $hay, $kw);

        $h1 = '';
        $h2hit = false;
        foreach ($headings as [$level, $text]) {
            if ($h1 === '' && $level === 1) {
                $h1 = $text;
            }
            if ($level === 2 && $in($text)) {
                $h2hit = true;
            }
        }
        $first_para = '';
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', (string) ($post->post_content ?? ''), $m)) {
            $first_para = Text::plain($m[1]);
        }

        $alt_hit = false;
        foreach ($images as $attrs) {
            if ($in(Text::attr($attrs, 'alt'))) {
                $alt_hit = true;
                break;
            }
        }

        $slug = Text::normalize(str_replace(['-', '_', '/'], ' ', (string) ($post->post_name ?? '')));
        $words = Text::words($plain);
        $total = count($words);
        $occ = $total > 0 ? substr_count(' ' . Text::normalize($plain) . ' ', ' ' . $kw . ' ') : 0;

        return [
            'set' => true,
            'checks' => [
                'kw_title' => $in($data['title'] ?? ''),
                'kw_url' => $slug !== '' && str_contains(' ' . $slug . ' ', ' ' . $kw . ' '),
                'kw_description' => $in($data['description'] ?? ''),
                'kw_h1' => $h1 !== '' && $in($h1),
                'kw_first_para' => $in($first_para),
                'kw_h2' => $h2hit,
                'kw_alt' => $alt_hit,
            ],
            'density' => $total > 0 ? round(100 * $occ / $total, 2) : 0.0,
        ];
    }

    /** Parses secondary list (comma-separated, max 4 like Rank Math's 5 total). */
    public static function parse_secondary(string $raw): array {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $kw = Text::normalize($part);
            if ($kw !== '' && !in_array($kw, $out, true)) {
                $out[] = $kw;
            }
        }
        return array_slice($out, 0, 4);
    }

    /**
     * Secondary presence: each keyword should appear in content, title or
     * description. Returns ['set' => bool, 'missing' => [...], 'present' => [...]].
     */
    public static function analyze_secondary(array $keywords, array $data, string $plain): array {
        if ($keywords === []) {
            return ['set' => false, 'missing' => [], 'present' => []];
        }
        $haystacks = [$plain, (string) ($data['title'] ?? ''), (string) ($data['description'] ?? '')];
        $missing = [];
        $present = [];
        foreach ($keywords as $kw) {
            $found = false;
            foreach ($haystacks as $hay) {
                if (Text::has_phrase($hay, $kw)) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $present[] = $kw;
            } else {
                $missing[] = $kw;
            }
        }
        return ['set' => true, 'missing' => $missing, 'present' => $present];
    }
}
