<?php
namespace TQK_WPSEO\Analyzer;

defined('ABSPATH') || exit;

/** S4 — Shared text helpers: normalize, words, sentences, tag parsing. */
class Text {

    public static function normalize(string $s): string {
        $s = mb_strtolower(remove_accents($s), 'UTF-8');
        $s = (string) preg_replace('/[^a-z0-9\s]/u', ' ', $s);
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Whole-phrase match on normalized text: accent-insensitive ("ca phe"
     * matches "cà phê") and syllable-bounded ("an" does not match "bàn").
     */
    public static function has_phrase(string $haystack, string $normalized_phrase): bool {
        if ($normalized_phrase === '' || $haystack === '') {
            return false;
        }
        return str_contains(' ' . self::normalize($haystack) . ' ', ' ' . $normalized_phrase . ' ');
    }

    /**
     * Vietnamese text? Space-split "words" in Vietnamese are syllables
     * ("máy lọc nước" = 3), so length rules need their own thresholds.
     */
    public static function is_vietnamese(string $text): bool {
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters < 40) {
            return false;
        }
        $vi = preg_match_all('/[ăâđêôơưạảấầẩẫậắằẳẵặẹẻẽếềểễệỉịọỏốồổỗộớờởỡợụủứừửữựỳỵỷỹĂÂĐÊÔƠƯ]/u', $text);
        return $vi / $letters > 0.03;
    }

    /** @return string[] Paragraph texts of an HTML fragment. */
    public static function paragraphs(string $html): array {
        $out = [];
        if (preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $html, $m)) {
            foreach ($m[1] as $p) {
                $t = self::plain($p);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }
        return $out;
    }

    public static function words(string $s): array {
        $s = trim(wp_strip_all_tags($s));
        if ($s === '') {
            return [];
        }
        return preg_split('/\s+/u', $s) ?: [];
    }

    public static function plain(string $html): string {
        $html = (string) preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $html);
        $html = str_replace('><', '> <', $html);
        return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    /** @return array List of [level, text]. */
    public static function headings(string $html): array {
        $out = [];
        if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $text = trim(self::plain($h[2]));
                if ($text !== '') {
                    $out[] = [(int) $h[1], $text];
                }
            }
        }
        return $out;
    }

    /** @return array List of full <img ...> attribute strings. */
    public static function images(string $html): array {
        $out = [];
        if (preg_match_all('/<img\b([^>]*)>/i', $html, $m)) {
            $out = $m[1];
        }
        return $out;
    }

    public static function attr(string $attrs, string $name): string {
        if (preg_match('/' . $name . '\s*=\s*"([^"]*)"/i', $attrs, $m)) {
            return $m[1];
        }
        if (preg_match("/" . $name . "\s*=\s*'([^']*)'/i", $attrs, $m)) {
            return $m[1];
        }
        return '';
    }

    /** @return string[] Hrefs. */
    public static function links(string $html): array {
        $out = [];
        if (preg_match_all('/<a\b[^>]*href\s*=\s*"([^"]*)"[^>]*>/i', $html, $m)) {
            $out = $m[1];
        }
        if (preg_match_all("/<a\b[^>]*href\s*=\s*'([^']*)'[^>]*>/i", $html, $m)) {
            $out = array_merge($out, $m[1]);
        }
        return $out;
    }

    /** @return string[] Sentences. */
    public static function sentences(string $text): array {
        $parts = preg_split('/(?<=[.!?…])\s+/u', trim($text)) ?: [];
        return array_values(array_filter(array_map('trim', $parts)));
    }
}
