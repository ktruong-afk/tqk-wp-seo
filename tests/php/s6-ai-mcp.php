<?php
// S6 tests: AI providers, suggester, MCP tools. Run: php tests/php/s6-ai-mcp.php
require __DIR__ . '/stubs.php';
require dirname(__DIR__, 2) . '/tqk-wpseo.php';

$fail = 0;
function check($name, $cond) {
    global $fail;
    if ($cond) {
        echo "ok - {$name}\n";
    } else {
        $fail++;
        echo "FAIL - {$name}\n";
    }
}

use TQK_WPSEO\AI\Factory;
use TQK_WPSEO\AI\OllamaProvider;
use TQK_WPSEO\AI\Suggester;
use TQK_WPSEO\MCP\Server;

call_user_func($GLOBALS['__activation']);
do_action('init');

$GLOBALS['__posts'][51] = [
    'ID' => 51, 'post_title' => 'AI Post', 'post_content' => '<h1>AI Post</h1><p>' . str_repeat('AI content words here. ', 30) . '</p>',
    'post_excerpt' => 'AI excerpt here today.', 'post_type' => 'post', 'post_status' => 'publish',
    'post_name' => 'ai-post', 'post_author' => 3, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-02',
];
$GLOBALS['__users'][3] = ['display_name' => 'Khai', 'user_nicename' => 'khai'];
$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();

// Factory: none by default.
check('factory null when disabled', Factory::create() === null);

// Ollama parses response.
$GLOBALS['__http_queue'][] = ['body' => wp_json_encode(['response' => "1. First Title\n2. Second Title\n"]) ];
$p = new OllamaProvider('http://127.0.0.1:11434', 'llama3.1');
check('provider name', $p->name() === 'ollama');
check('ollama generates', $p->generate('hi') === "1. First Title\n2. Second Title");

// parse_list.
check('parse_list cleans', Suggester::parse_list("1. Alpha\n- Beta\n* Gamma\n\n") === ['Alpha', 'Beta', 'Gamma']);

// Prompts mention the post.
$post = get_post(51);
$data = \TQK_WPSEO\Core\Data::for(['type' => 'singular', 'object_id' => 51, 'post_type' => 'post']);
check('title prompt', str_contains(Suggester::prompt_title($post, $data), 'AI Post'));
check('schema prompt', str_contains(Suggester::prompt_schema($post, $data), 'Schema.org'));

// Suggester errors without provider.
check('generate errors when disabled', isset(Suggester::generate('title', 51)['error']));

// Suggester with provider (mocked HTTP).
$GLOBALS['__options']['tqk_wpseo_settings']['ai_provider'] = 'ollama';
$GLOBALS['__http_queue'][] = ['body' => wp_json_encode(['response' => "1. Opt A\n2. Opt B\n"]) ];
$opts = Suggester::generate('title', 51);
check('generate returns options', $opts === ['Opt A', 'Opt B']);

// AI ajax endpoint.
$GLOBALS['__http_queue'][] = ['body' => wp_json_encode(['response' => "1. K1\n2. K2\n"]) ];
$_POST = ['ai_type' => 'keywords', 'post_id' => 51, '_ajax_nonce' => 'valid'];
try {
    Suggester::ajax();
    check('ai ajax returns', false);
} catch (WP_Test_Response $e) {
    check('ai ajax returns', $e->payload === ['success' => true, 'data' => ['options' => ['K1', 'K2']]]);
}

// Metabox shows AI box.
ob_start();
\TQK_WPSEO\Admin\Metabox::render($post);
$mb = (string) ob_get_clean();
check('metabox has ai buttons', str_contains($mb, 'tqk-wpseo-ai-btn'));

// MCP: token + auth.
$token = Server::token();
check('token generated + stable', strlen($token) === 48 && Server::token() === $token);
do_action('rest_api_init');
check('auth rejects wrong token', Server::check(['headers' => ['x-tqk-token' => 'nope']]) === false);
check('auth accepts token', Server::check(['headers' => ['x-tqk-token' => $token]]) === true);
check('routes registered', in_array('tqk-wpseo/v1/tools', $GLOBALS['__rest_routes'], true) && in_array('tqk-wpseo/v1/call', $GLOBALS['__rest_routes'], true));

// MCP read tools.
check('tool list', in_array('seo_orphan_pages', Server::tools(), true));
$g = Server::call('seo_get_page', ['post_id' => 51]);
check('get_page ok', ($g['ok'] ?? false) === true && ($g['seo']['title'] ?? '') !== '');
$a = Server::call('seo_analyze_page', ['post_id' => 51]);
check('analyze ok', ($a['ok'] ?? false) === true && isset($a['analysis']['score']));
$s = Server::call('seo_get_schema', ['post_id' => 51]);
check('schema nodes ok', ($s['ok'] ?? false) === true && count($s['graph'] ?? []) >= 4);
check('unknown tool fails', (Server::call('nope', [])['ok'] ?? true) === false);

// MCP write tools + logging.
$w = Server::call('seo_update_meta', ['post_id' => 51, 'fields' => ['_tqk_wpseo_title' => 'MCP Title', 'evil_key' => 'x']]);
check('update_meta saves allowlisted', ($w['ok'] ?? false) === true && ($w['saved'] ?? []) === ['_tqk_wpseo_title']);
check('update_meta stored', get_post_meta(51, '_tqk_wpseo_title', true) === 'MCP Title');
$r = Server::call('seo_create_redirect', ['source' => '/mcp-old/', 'target' => '/mcp-new/', 'type' => 301]);
check('create_redirect ok', ($r['ok'] ?? false) === true && ($r['id'] ?? 0) > 0);
check('writes logged', count(get_option(Server::LOG_OPTION, [])) === 2);

// MCP rest wrappers.
check('rest tools envelope', isset(Server::rest_tools([])['tools']));
check('rest call envelope', (Server::rest_call(['tool' => 'seo_scan_status', 'params' => []])['ok'] ?? false) === true);

echo $fail === 0 ? "\nALL S6 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
