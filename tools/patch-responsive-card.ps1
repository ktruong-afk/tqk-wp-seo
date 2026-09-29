$p = "C:\laragon\www\openbuilder\wp-content\plugins\tqk-wpseo\src\Admin\Pages.php"
$t = [System.IO.File]::ReadAllText($p)

$startMarker = "    private static function responsive_card(): string {"
$endMarker   = "    /** Bulk AI (local)"

$start = $t.IndexOf($startMarker)
$end   = $t.IndexOf($endMarker)
if ($start -lt 0 -or $end -lt 0 -or $end -le $start) { throw "markers not found start=$start end=$end" }

# also drop the old docblock that sits directly above responsive_card
$docStart = $t.LastIndexOf("    /**", $start)
if ($docStart -lt 0 -or ($start - $docStart) -gt 700) { $docStart = $start }

$new = @'
    /**
     * Responsive Audit: runs real pages at 1440/1280/768/375 inside a real
     * iframe so the theme's own @media rules fire. Findings are grouped by
     * root cause, so one CSS rule clears a dozen reported elements.
     *
     * Scope is the user's call, not ours. The default is the cheap honest
     * pass (every page, one sample per other post type); a full crawl is one
     * checkbox away, because the measurement is client-side and costs no
     * extra server work beyond the page loads the visitor makes anyway.
     */
    private static function responsive_card(): string {
        $q = self::responsive_queue();
        if ($q['posts'] === []) {
            return '';
        }
        $type_opts = sprintf('<option value="">%s</option>', esc_html__('All post types', 'tqk-wpseo'));
        foreach ($q['counts'] as $type => $n) {
            $type_opts .= sprintf('<option value="%s">%s (%d)</option>', esc_attr($type), esc_html($type), (int) $n);
        }
        $total = array_sum($q['counts']);

        return '<h2 class="tqk-wpseo-section">' . esc_html__('Responsive Audit (4 viewports)', 'tqk-wpseo') . '</h2>'
            . '<p>' . esc_html__('Loads real pages at 1440, 1280, 768 and 375 px and measures the rendered result. Every measurement happens in your browser, so a longer crawl costs no extra server work. Findings are grouped by root cause, not by position.', 'tqk-wpseo') . '</p>'
            . '<p><label>' . esc_html__('Post type', 'tqk-wpseo') . ' <select id="tqk-rs-type">' . $type_opts . '</select></label> '
            . '<label><input type="checkbox" id="tqk-rs-all"> ' . esc_html__('Audit every post in this type', 'tqk-wpseo') . '</label></p>'
            . '<p><button type="button" class="button button-primary" id="tqk-rs-run">' . esc_html__('Run responsive audit', 'tqk-wpseo') . '</button> '
            . '<span class="description" id="tqk-rs-hint"></span></p>'
            . '<script type="application/json" id="tqk-rs-data">'
            . wp_json_encode(['counts' => $q['counts'], 'posts' => $q['posts'], 'total' => (int) $total])
            . '</script>'
            . '<div id="tqk-rs-modal" class="tqk-rs-modal" hidden>'
            . '<div class="tqk-rs-panel">'
            . '<h3>' . esc_html__('Responsive audit', 'tqk-wpseo') . '</h3>'
            . '<p id="tqk-rs-status">' . esc_html__('Starting.', 'tqk-wpseo') . '</p>'
            . '<div class="tqk-rs-bar"><div id="tqk-rs-fill"></div></div>'
            . '<div id="tqk-rs-vps"></div>'
            . '<div id="tqk-rs-out"></div>'
            . '<p><button type="button" class="button" id="tqk-rs-close">' . esc_html__('Close', 'tqk-wpseo') . '</button></p>'
            . '</div></div>';
    }

    /**
     * Published post queue for the responsive audit, with a per-type count.
     * 200 is the same cap the page-audit picker already uses, so this adds no
     * new query cost beyond that existing control.
     *
     * @return array{counts: array<string,int>, posts: list<array{url:string,title:string,type:string}>}
     */
    private static function responsive_queue(): array {
        $pts = array_values(array_unique(array_merge(
            ['post', 'page'],
            (array) (function_exists('get_post_types') ? get_post_types(['public' => true]) : [])
        )));
        $pts = array_values(array_filter($pts, static fn($t) => $t !== 'attachment'));

        $posts = [];
        $counts = [];
        $found = get_posts([
            'post_type' => $pts,
            'post_status' => 'publish',
            'numberposts' => 200,
        ]);
        foreach ($found as $p) {
            $url = (string) get_permalink($p);
            if ($url === '') {
                continue;
            }
            $type = (string) $p->post_type;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            $posts[] = ['url' => $url, 'title' => (string) $p->post_title, 'type' => $type];
        }
        return ['counts' => $counts, 'posts' => $posts];
    }

'@

$t2 = $t.Substring(0, $docStart) + $new + $t.Substring($end)
[System.IO.File]::WriteAllText($p, $t2)
Write-Output "patched responsive_card + responsive_queue"
