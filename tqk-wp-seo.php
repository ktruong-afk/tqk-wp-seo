<?php
/**
 * Plugin Name: TQK WP SEO
 * Plugin URI: https://github.com/ktruong-afk/tqk-wp-seo
 * Description: Website Audit & Optimization Engine. TQK WP SEO checks every page the way a visitor sees it — on desktop, laptop, tablet and phone — turns what it finds into a plan to fix, lets an AI suggest the wording while you approve every change, and keeps the site clean of the malware cracked plugins bring.
 * Version: 0.2.0
 * Author: Trương Quang Khải
 * Author URI: https://www.facebook.com/Unk.khai/
 * Text Domain: tqk-wp-seo
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

define('TQK_WPSEO_VERSION', '0.2.0');
define('TQK_WPSEO_FILE', __FILE__);
define('TQK_WPSEO_DIR', plugin_dir_path(__FILE__));
define('TQK_WPSEO_URL', plugin_dir_url(__FILE__));
define('TQK_WPSEO_OPTION', 'tqk_wpseo_settings');
define('TQK_WPSEO_VERSION_OPTION', 'tqk_wpseo_version');

/**
 * i18n MANDATORY: load text-domain first, before any module.
 * Rule: every user-facing string MUST use text-domain 'tqk-wp-seo'.
 */
function tqk_wpseo_load_textdomain() {
    load_plugin_textdomain('tqk-wp-seo', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'tqk_wpseo_load_textdomain');

require_once TQK_WPSEO_DIR . 'src/Core/Activator.php';
require_once TQK_WPSEO_DIR . 'src/Core/Deactivator.php';
require_once TQK_WPSEO_DIR . 'src/Core/Context.php';
require_once TQK_WPSEO_DIR . 'src/Core/Data.php';
require_once TQK_WPSEO_DIR . 'src/Core/Meta.php';
require_once TQK_WPSEO_DIR . 'src/Core/Canonical.php';
require_once TQK_WPSEO_DIR . 'src/Core/Robots.php';
require_once TQK_WPSEO_DIR . 'src/Core/OpenGraph.php';
require_once TQK_WPSEO_DIR . 'src/Core/Frontend.php';
require_once TQK_WPSEO_DIR . 'src/Core/Queue.php';
require_once TQK_WPSEO_DIR . 'src/Core/Secrets.php';
require_once TQK_WPSEO_DIR . 'src/Core/Pro.php';
require_once TQK_WPSEO_DIR . 'src/Core/Modules.php';
require_once TQK_WPSEO_DIR . 'src/Core/Breadcrumb.php';
require_once TQK_WPSEO_DIR . 'src/Schema/Graph.php';
require_once TQK_WPSEO_DIR . 'src/Schema/Fields.php';
require_once TQK_WPSEO_DIR . 'src/Schema/Builder.php';
require_once TQK_WPSEO_DIR . 'src/Schema/Validator.php';
require_once TQK_WPSEO_DIR . 'src/Schema/Nodes.php';
require_once TQK_WPSEO_DIR . 'src/Schema/Schema.php';
require_once TQK_WPSEO_DIR . 'src/Sitemap/Sitemap.php';
require_once TQK_WPSEO_DIR . 'src/Analyzer/Text.php';
require_once TQK_WPSEO_DIR . 'src/Analyzer/Keyword.php';
require_once TQK_WPSEO_DIR . 'src/Analyzer/PageAudit.php';
require_once TQK_WPSEO_DIR . 'src/Analyzer/Analyzer.php';
require_once TQK_WPSEO_DIR . 'src/Analyzer/Frontend.php';
require_once TQK_WPSEO_DIR . 'src/Analyzer/LiveAudit.php';
require_once TQK_WPSEO_DIR . 'src/Analyzer/ImageFix.php';
require_once TQK_WPSEO_DIR . 'src/Analyzer/AnalysisBox.php';
require_once TQK_WPSEO_DIR . 'src/Redirect/Redirects.php';
require_once TQK_WPSEO_DIR . 'src/Redirect/Monitor404.php';
require_once TQK_WPSEO_DIR . 'src/Links/Engine.php';
require_once TQK_WPSEO_DIR . 'src/Scanner/Scanner.php';
require_once TQK_WPSEO_DIR . 'src/Admin/Settings.php';
require_once TQK_WPSEO_DIR . 'src/Admin/Access.php';
require_once TQK_WPSEO_DIR . 'src/Admin/Ajax.php';
require_once TQK_WPSEO_DIR . 'src/Admin/Wizard.php';
require_once TQK_WPSEO_DIR . 'src/AI/ProviderInterface.php';
require_once TQK_WPSEO_DIR . 'src/AI/OllamaProvider.php';
require_once TQK_WPSEO_DIR . 'src/AI/LMStudioProvider.php';
require_once TQK_WPSEO_DIR . 'src/AI/Factory.php';
require_once TQK_WPSEO_DIR . 'src/AI/Suggester.php';
require_once TQK_WPSEO_DIR . 'src/AI/RateLimiter.php';
require_once TQK_WPSEO_DIR . 'src/AI/GeminiProvider.php';
require_once TQK_WPSEO_DIR . 'src/AI/Advisor.php';
require_once TQK_WPSEO_DIR . 'src/Google/SearchConsole.php';
require_once TQK_WPSEO_DIR . 'src/Indexing/Submitter.php';
require_once TQK_WPSEO_DIR . 'src/Core/Vault.php';
require_once TQK_WPSEO_DIR . 'src/MCP/Server.php';
require_once TQK_WPSEO_DIR . 'src/MCP/Reports.php';
require_once TQK_WPSEO_DIR . 'src/MCP/Protocol.php';
require_once TQK_WPSEO_DIR . 'src/Tools/Importer.php';
require_once TQK_WPSEO_DIR . 'src/Security/Scan.php';
require_once TQK_WPSEO_DIR . 'src/Local/Address.php';
require_once TQK_WPSEO_DIR . 'src/Report/Report.php';
require_once TQK_WPSEO_DIR . 'src/Admin/Assets.php';
require_once TQK_WPSEO_DIR . 'src/Admin/Metabox.php';
require_once TQK_WPSEO_DIR . 'src/Admin/AiJson.php';
require_once TQK_WPSEO_DIR . 'src/Admin/AdminBar.php';
require_once TQK_WPSEO_DIR . 'src/Security/PageCheck.php';
require_once TQK_WPSEO_DIR . 'src/Admin/TermFields.php';
require_once TQK_WPSEO_DIR . 'src/Admin/View.php';
require_once TQK_WPSEO_DIR . 'src/Admin/Menu.php';
require_once TQK_WPSEO_DIR . 'src/Admin/Pages.php';

register_activation_hook(__FILE__, ['TQK_WPSEO\Core\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['TQK_WPSEO\Core\Deactivator', 'deactivate']);

/**
 * Bootstrap placeholder — stages land here per docs/drafts/02-BUILD-ORDER.md.
 * S0: admin menu + scoped Bootstrap layout + activation/DB (done).
 */
function tqk_wpseo_init() {
    \TQK_WPSEO\Admin\Menu::register();
    \TQK_WPSEO\Admin\Assets::hooks();
    \TQK_WPSEO\Admin\Metabox::hooks();
    \TQK_WPSEO\Admin\AdminBar::hooks();
    \TQK_WPSEO\Admin\TermFields::hooks();
    \TQK_WPSEO\Core\Frontend::hooks();
    $on = fn($m) => \TQK_WPSEO\Core\Modules::is_active($m);
    if ($on('schema')) {
        \TQK_WPSEO\Schema\Schema::hooks();
    }
    if ($on('sitemap')) {
        \TQK_WPSEO\Sitemap\Sitemap::hooks();
    }
    if ($on('analyzer')) {
        \TQK_WPSEO\Analyzer\Analyzer::hooks();
        \TQK_WPSEO\Analyzer\AnalysisBox::hooks();
        \TQK_WPSEO\Analyzer\Frontend::hooks();
        \TQK_WPSEO\Analyzer\LiveAudit::hooks();
        \TQK_WPSEO\Analyzer\ImageFix::hooks();
    }
    if ($on('scanner')) {
        \TQK_WPSEO\Scanner\Scanner::hooks();
    }
    if ($on('analytics')) {
        \TQK_WPSEO\Google\SearchConsole::hooks();
    }
    if ($on('redirects')) {
        \TQK_WPSEO\Redirect\Redirects::hooks();
    }
    if ($on('monitor-404')) {
        \TQK_WPSEO\Redirect\Monitor404::hooks();
    }
    if ($on('links')) {
        \TQK_WPSEO\Links\Engine::hooks();
    }
    if ($on('instant-indexing')) {
        \TQK_WPSEO\Indexing\Submitter::hooks();
    }
    if ($on('ai')) {
        \TQK_WPSEO\AI\Suggester::hooks();
    }
    if ($on('mcp')) {
        \TQK_WPSEO\MCP\Server::hooks();
        \TQK_WPSEO\MCP\Protocol::hooks();
    }
    \TQK_WPSEO\Admin\Settings::register();
    \TQK_WPSEO\Admin\Access::hooks();
    add_action('admin_init', ['TQK_WPSEO\Core\Activator', 'ensure_tables']);
    \TQK_WPSEO\Admin\Ajax::hooks();
    \TQK_WPSEO\Report\Report::hooks();
    \TQK_WPSEO\Core\Vault::hooks();
    \TQK_WPSEO\Admin\Wizard::hooks();
}
add_action('init', 'tqk_wpseo_init');
