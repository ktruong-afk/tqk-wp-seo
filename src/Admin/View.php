<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

/**
 * Shared admin rendering. Every page goes through View::page() so all
 * ten screens share one header/container and the `.tqk-wpseo` scope wrapper.
 * Icons are inline SVG (no emoji anywhere).
 */
class View {

    /**
     * @param string $title   Page title (already translated by caller).
     * @param string $content Inner HTML (already escaped by component helpers).
     * @param string $actions Optional HTML for header action buttons.
     */
    public static function page(string $title, string $content, string $actions = ''): void {
        $template = TQK_WPSEO_DIR . 'templates/admin-page.php';
        include $template;
    }

    public static function mark(): string {
        return '<span class="tqk-wpseo-mark" aria-hidden="true">'
            . '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V5a1 1 0 0 1 1-1h14"/><path d="M4 19a1 1 0 0 0 1 1h14"/><path d="M9 9l2 2 4-4"/></svg>'
            . '</span>';
    }

    private static function icon(string $kind): string {
        $paths = [
            'health' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2.5"/>',
            'alert' => '<path d="M12 3 2.5 20h19L12 3z"/><path d="M12 10v4M12 17.5v.5"/>',
            'warn' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4M12 15.5v.5"/>',
            'check' => '<circle cx="12" cy="12" r="8"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
            'doc' => '<path d="M6 2h9l5 5v15a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v6h6"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
            'pages' => '<path d="M12 3 3 8l9 5 9-5-9-5z"/><path d="M3 13l9 5 9-5"/>',
            'display' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
            'ai' => '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8L12 3z"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8.8-2z"/>',
            'shield' => '<path d="M12 3l7 3v5c0 4.5-3 8.2-7 9.5C8 19.2 5 15.5 5 11V6l7-3z"/><path d="M9 12l2 2 4-4"/>',
        ];
        $inner = $paths[$kind] ?? $paths['doc'];
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
    }

    private static function icon_for(string $label): array {
        $l = mb_strtolower($label);
        if (str_contains($l, 'critical') || str_contains($l, 'nghiêm')) {
            return ['alert', 'is-bad'];
        }
        if (str_contains($l, 'warn') || str_contains($l, 'cảnh')) {
            return ['warn', 'is-warn'];
        }
        if (str_contains($l, 'pass') || str_contains($l, 'đạt') || str_contains($l, 'clear') || str_contains($l, 'sạch')) {
            return ['check', ''];
        }
        if (str_contains($l, 'health') || str_contains($l, 'sức')) {
            return ['health', ''];
        }
        return ['doc', ''];
    }

    /** $icon: pages | search | display | ai | shield | health… ('' = guessed from the label); $tone: '' | is-bad | is-warn. */
    public static function stat_card(string $label, string $value, string $note = '', string $url = '', string $icon = '', string $tone = ''): string {
        [$kind, $guess] = self::icon_for($label);
        $kind = $icon !== '' ? $icon : $kind;
        $tone = $icon !== '' ? $tone : $guess;
        $inner = sprintf(
            '<span class="tqk-wpseo-stat-ico %s">%s</span><span><div class="tqk-wpseo-stat-value">%s</div><div class="tqk-wpseo-stat-label">%s</div>',
            esc_attr($tone),
            self::icon($kind),
            esc_html($value),
            esc_html($label)
        );
        if ($note !== '') {
            $inner .= sprintf('<div class="tqk-wpseo-stat-note">%s</div>', esc_html($note));
        }
        $card = sprintf('<div class="tqk-wpseo-stat-card">%s</span></div>', $inner);
        if ($url !== '') {
            return sprintf('<a class="tqk-wpseo-stat-link" href="%s">%s</a>', esc_url($url), $card);
        }
        return $card;
    }

    /** @return ''|'is-good'|'is-ok'|'is-bad' (good >= 80, ok >= 50). */
    public static function score_tone(?int $pct): string {
        if ($pct === null) {
            return '';
        }
        if ($pct >= 80) {
            return 'is-good';
        }
        if ($pct >= 50) {
            return 'is-ok';
        }
        return 'is-bad';
    }

    public static function score_ring(int $pct): string {
        $pct = max(0, min(100, $pct));
        $stroke = match (self::score_tone($pct)) {
            'is-ok' => '#fbbf24',
            'is-bad' => '#fb7185',
            default => '#34d399',
        };
        $r = 52;
        $c = 2 * M_PI * $r;
        $off = $c * (1 - $pct / 100);
        return sprintf(
            '<svg class="tqk-wpseo-ring" width="132" height="132" viewBox="0 0 132 132" role="img" aria-label="%d/100">'
            . '<circle cx="66" cy="66" r="%d" fill="none" stroke="rgba(255,255,255,.18)" stroke-width="11"/>'
            . '<circle cx="66" cy="66" r="%d" fill="none" stroke="%s" stroke-width="11" stroke-linecap="round" '
            . 'stroke-dasharray="%.1f" stroke-dashoffset="%.1f" transform="rotate(-90 66 66)"/>'
            . '<text x="66" y="62" text-anchor="middle" fill="#fff" font-size="26" font-weight="700">%d</text>'
            . '<text x="66" y="82" text-anchor="middle" fill="rgba(255,255,255,.7)" font-size="11">/ 100</text>'
            . '</svg>',
            $pct,
            $r,
            $r,
            $stroke,
            $c,
            $off,
            $pct
        );
    }

    public static function score_bar(string $label, ?int $pct): string {
        $pct = $pct === null ? null : max(0, min(100, $pct));
        $tone = self::score_tone($pct);
        $text = $pct === null ? '—' : $pct . '%';
        $fill = $pct === null ? '' : sprintf(' style="width:%d%%"', $pct);
        return sprintf(
            '<div class="tqk-wpseo-score"><div class="tqk-wpseo-score-head"><span>%s</span><strong class="%s">%s</strong></div>' .
            '<div class="tqk-wpseo-score-track"><div class="tqk-wpseo-score-fill %s"%s></div></div></div>',
            esc_html($label),
            esc_attr($tone),
            esc_html($text),
            esc_attr($tone),
            $fill
        );
    }

    public static function empty_state(string $title, string $body, string $button = ''): string {
        $html = sprintf(
            '<div class="tqk-wpseo-empty"><span class="tqk-wpseo-empty-ico">%s</span><h2>%s</h2><p>%s</p>',
            self::icon('search'),
            esc_html($title),
            esc_html($body)
        );
        if ($button !== '') {
            $html .= $button;
        }
        return $html . '</div>';
    }

    public static function button(string $label, string $url, bool $primary = true): string {
        return sprintf(
            '<a class="tqk-btn %s" href="%s">%s</a>',
            $primary ? 'tqk-btn-primary' : 'tqk-btn-outline',
            esc_url($url),
            esc_html($label)
        );
    }

    /** @return string Accessible switch (checkbox) row. */
    public static function switch(string $name, bool $checked, string $label): string {
        return sprintf(
            '<label class="tqk-wpseo-switch"><input type="checkbox" name="%s" value="1"%s><span class="tqk-wpseo-track"><span class="tqk-wpseo-thumb"></span></span><span>%s</span></label>',
            esc_attr($name),
            $checked ? ' checked' : '',
            esc_html($label)
        );
    }

    /** @param array $rows Each row: [issue, where, action_html]. */
    public static function issue_table(array $headers, array $rows): string {
        $html = '<div class="tqk-wpseo-table-wrap"><div class="table-responsive"><table class="tqk-wpseo-table"><thead><tr>';
        foreach ($headers as $h) {
            $html .= sprintf('<th scope="col">%s</th>', esc_html($h));
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $i => $cell) {
                // First two columns are plain text; last column holds action HTML.
                $html .= $i < 2
                    ? sprintf('<td>%s</td>', esc_html((string) $cell))
                    : sprintf('<td>%s</td>', (string) $cell);
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div></div>';
    }
}
