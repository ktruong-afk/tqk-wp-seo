<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/** S1 — Canonical: strips tracking params, keeps pagination. */
class Canonical {

    /** @return string Cleaned URL. */
    public static function clean(string $url): string {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $drop = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach ($drop as $k) {
                unset($query[$k]);
            }
        }
        $out = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $out .= ':' . $parts['port'];
        }
        $out .= $parts['path'] ?? '/';
        if ($query !== []) {
            $out .= '?' . http_build_query($query);
        }
        return $out;
    }

    public static function render(array $d): string {
        $canon = self::clean((string) ($d['canonical'] ?? ''));
        if ($canon === '') {
            return '';
        }
        return sprintf("<link rel=\"canonical\" href=\"%s\" />\n", esc_url($canon));
    }
}
