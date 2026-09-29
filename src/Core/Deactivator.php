<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/**
 * S0.4 — Deactivation: flush rewrites only. Data is never deleted here.
 */
class Deactivator {

    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
