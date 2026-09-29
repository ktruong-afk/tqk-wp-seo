<?php
// S17 tests: Gemini provider, free-tier rate limiter, AI Advisor queue.
// No network: Gemini answers are mocked through $GLOBALS['__http_queue'].
// Run: php tests/php/s17-gemini-advisor.php
require __DIR__ . '/stubs.php';
require dirname(__DIR__, 2) . '/tqk-wpseo.php';

if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12, $special = true, $extra = false) {
        return substr(str_repeat(bin2hex(random_bytes(16)), 2), 0, $len);
    }
}

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

use TQK_WPSEO\AI\Advisor;
use TQK_WPSEO\AI\GeminiProvider;
use TQK_WPSEO\AI\RateLimiter;
use TQK_WPSEO\Analyzer\LiveAudit;

$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();
$set = function (array $kv) {
    $GLOBALS['__options']['tqk_wpseo_settings'] = array_merge($GLOBALS['__options']['tqk_wpseo_settings'], $kv);
};
$reset_rate = function () {
    unset($GLOBALS['__options'][RateLimiter::STATE], $GLOBALS['__options'][RateLimiter::LOCK]);
};
$gemini_ok = function (array $data) {
    return ['code' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]]]]])];
};

// --- defaults are free-tier safe ---
$d = \TQK_WPSEO\Core\Activator::default_settings();
check('defaults: 5 requests / minute', $d['ai_rpm'] === 5);
check('defaults: 200 requests / day', $d['ai_rpd'] === 200);
check('gemini key is a secret (encrypted at rest)', \TQK_WPSEO\Core\Secrets::is_secret('gemini_api_key'));

// --- rate limiter ---
$reset_rate();
$t = 1790000000;
$a = RateLimiter::acquire($t);
check('limiter: first call goes out', $a['ok']);
$b = RateLimiter::acquire($t + 1);
check('limiter: second call within 12 s waits (60/5)', !$b['ok'] && $b['wait'] >= 10);
check('limiter: after the gap the next call goes out', RateLimiter::acquire($t + 15)['ok']);

$GLOBALS['__options'][RateLimiter::LOCK] = $t + 30;
check('limiter: a held lock makes a concurrent request wait', !RateLimiter::acquire($t + 30)['ok']);
check('limiter: a stale lock (crashed request) is cleared', RateLimiter::acquire($t + 300)['ok']);

$reset_rate();
$set(['ai_rpd' => 2, 'ai_rpm' => 60]);
// Calls spaced beyond the 1 s gap + 2 s jitter.
RateLimiter::acquire($t);
RateLimiter::acquire($t + 10);
$c = RateLimiter::acquire($t + 20);
check('limiter: daily quota reached → wait until reset', !$c['ok'] && $c['daily'] && $c['wait'] > 3600 * 0);
check('limiter: reset is at Pacific midnight', RateLimiter::next_reset($t) > $t && RateLimiter::day(RateLimiter::next_reset($t)) !== RateLimiter::day($t));
check('limiter: new Pacific day → counter resets', RateLimiter::acquire(RateLimiter::next_reset($t) + 5)['ok']);
$set(['ai_rpd' => 200, 'ai_rpm' => 5]);

$reset_rate();
RateLimiter::block(40, false, $t);
check('limiter: 429 retryDelay is honoured', RateLimiter::check($t + 10)['wait'] === 30);

// --- provider ---
check('retryDelay parsed from RetryInfo', GeminiProvider::retry_delay(['error' => ['details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '17.3s']]]]) === 18);
check('retryDelay defaults to 60', GeminiProvider::retry_delay([]) === 60);
check('model names validated', GeminiProvider::valid_model('gemini-2.5-flash') && !GeminiProvider::valid_model('../x') && !GeminiProvider::valid_model('a b'));

$g = new GeminiProvider('KEY', 'gemini-2.5-flash');
$reset_rate();
$GLOBALS['__http_queue'] = [$gemini_ok(['pages' => [['post_id' => 1]]])];
$r = $g->call('x', ['type' => 'OBJECT']);
check('ok: structured JSON decoded', $r['status'] === 'ok' && ($r['data']['pages'][0]['post_id'] ?? 0) === 1);

$reset_rate();
$GLOBALS['__http_queue'] = [['code' => 429, 'body' => json_encode(['error' => ['code' => 429, 'details' => [['retryDelay' => '20s']]]])]];
$r = $g->call('x');
check('429 per minute → wait, not daily', $r['status'] === 'wait' && !$r['daily'] && $r['wait'] >= 20);

$reset_rate();
$GLOBALS['__http_queue'] = [['code' => 429, 'body' => json_encode(['error' => ['code' => 429, 'message' => 'Quota exceeded for metric generate_content_free_tier_requests, quotaId: GenerateRequestsPerDayPerProjectPerModel-FreeTier']])]];
$r = $g->call('x');
check('429 per day → daily wait until reset', $r['status'] === 'wait' && $r['daily']);
check('daily block persists for other callers', RateLimiter::check()['daily']);

$reset_rate();
$GLOBALS['__http_queue'] = [['code' => 400, 'body' => json_encode(['error' => ['message' => 'API key not valid.']])]];
$r = $g->call('x');
check('bad key → fatal with Google message', $r['status'] === 'fatal' && strpos($r['error'], 'API key') !== false);

$reset_rate();
$GLOBALS['__http_queue'] = [['code' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'not json']]]]]])]];
check('invalid JSON answer → error, not a crash', $g->call('x', ['type' => 'OBJECT'])['status'] === 'error');

$set(['ai_provider' => 'gemini', 'gemini_api_key' => '', 'gemini_model' => 'gemini-2.5-flash']);
$GLOBALS['__options']['tqk_wpseo_modules'] = ['ai' => true];
check('factory: no key → no provider', \TQK_WPSEO\AI\Factory::create() === null || !(\TQK_WPSEO\AI\Factory::create() instanceof GeminiProvider));

// --- settings: masked secret never overwrites the stored key ---
$GLOBALS['__options']['tqk_wpseo_settings']['gemini_api_key'] = \TQK_WPSEO\Core\Secrets::encrypt('REALKEY');
$clean = \TQK_WPSEO\Admin\Settings::sanitize(['gemini_api_key' => '********', 'bing_api_key' => '********']);
check('settings: mask keeps the stored gemini key', \TQK_WPSEO\Core\Secrets::decrypt($clean['gemini_api_key']) === 'REALKEY');
check('settings: mask never becomes a bing key', \TQK_WPSEO\Core\Secrets::decrypt((string) $clean['bing_api_key']) === '');
$clean = \TQK_WPSEO\Admin\Settings::sanitize(['ai_rpm' => '0', 'ai_rpd' => '999999', 'gemini_model' => 'bad model!']);
check('settings: limits clamped', $clean['ai_rpm'] === 1 && $clean['ai_rpd'] === 100000);
check('settings: invalid model falls back to default', $clean['gemini_model'] === 'gemini-2.5-flash');

// --- advisor queue ---
$mk = function (int $id, string $content) {
    $GLOBALS['__posts'][$id] = [
        'ID' => $id, 'post_title' => "Bai {$id}", 'post_type' => 'post', 'post_status' => 'publish',
        'post_name' => "bai-{$id}", 'post_author' => 1, 'post_date' => '2026-09-01',
        'post_modified' => '2026-09-02', 'post_excerpt' => '', 'post_content' => $content,
    ];
};
foreach ([31, 32, 33, 34, 35, 36] as $id) {
    $mk($id, '<h2>Muc ' . $id . '</h2><p>Noi dung bai viet ' . $id . '.</p><img src="https://s.test/a' . $id . '.jpg">');
}
$live = LiveAudit::start('all', 'post', false);
$bad = ['seo' => ['score' => 40, 'findings' => [['sev' => 'critical', 'msg' => 'Missing meta description']]], 'vps' => [], 'groups' => []];
$good = ['seo' => ['score' => 100, 'findings' => [['sev' => 'pass', 'msg' => 'title']]], 'vps' => [], 'groups' => []];
foreach ($live['ids'] as $i => $id) {
    $live = LiveAudit::save($live, $i, $id === 36 ? $good : $bad, [])['run'];
}
// Field check adds "no focus keyword" to every post; give 34 one so it is clean.
update_post_meta(36, '_tqk_wpseo_focus_keyword', 'x');
$live = LiveAudit::start('all', 'post', false);
foreach ($live['ids'] as $i => $id) {
    $live = LiveAudit::save($live, $i, $id === 36 ? $good : $bad, [])['run'];
}
$cands = Advisor::candidates();
check('advisor: only pages with issues are queued', !in_array(36, $cands, true) && in_array(31, $cands, true));

$ctx = Advisor::context(31);
check('context: no raw HTML, outline + text + images without ALT', ($ctx['outline'][0] ?? '') === 'H2: Muc 31' && $ctx['images_without_alt'] === ['https://s.test/a31.jpg'] && strpos($ctx['text_start'], '<') === false);
check('context: failed checks included', in_array('Missing meta description', $ctx['problems'], true));

$run = Advisor::start();
check('advisor run: full list up front', Advisor::state($run)['total'] === count($cands));

$reset_rate();
$pages = [];
foreach (array_slice($cands, 0, Advisor::GROUP) as $id) {
    $pages[] = ['post_id' => $id, 'seo_title' => "Tieu de {$id}", 'seo_description' => 'Tieu de ' . str_repeat('a', 125), 'focus_keyword' => 'tieu de',
        'image_alts' => [['src' => "https://s.test/a{$id}.jpg", 'alt' => 'Anh'], ['src' => 'https://evil.test/x.jpg', 'alt' => 'x']],
        'schema_fields' => [['field' => 'not_a_field', 'value' => 'x']]];
}
$GLOBALS['__http_queue'] = [$gemini_ok(['pages' => $pages])];
$res = Advisor::step($run, $g, 'gemini-2.5-flash');
$run = $res['run'];
check('step: one request settles a group of 3 pages', count($res['done_ids']) === 3 && $run['calls'] === 1);
$s = Advisor::suggestion($cands[0]);
check('step: suggestion cached as JSON on the post', is_string(get_post_meta($cands[0], Advisor::META, true)) && ($s['data']['seo_title'] ?? '') === 'Tieu de ' . $cands[0]);
check('clean: ALT only for images that were asked about', count($s['data']['image_alts']) === 1);
check('clean: unknown schema fields dropped', $s['data']['schema_fields'] === []);
check('checks: 133 chars with keyword passes', ($s['data']['checks']['seo_description'] ?? 'x') === '');

// Rate limited: nothing marked, wait returned.
$res = Advisor::step($run, $g, 'gemini-2.5-flash');
check('step: next group waits for the rate limit, nothing lost', $res['wait'] > 0 && Advisor::state($res['run'])['pending'] === count($cands) - 3);

// Cache: a new run over unchanged pages spends no request.
$run2 = Advisor::start();
$reset_rate();
$GLOBALS['__http_queue'] = [];
$res = Advisor::step($run2, $g, 'gemini-2.5-flash');
check('cache: unchanged pages reuse suggestions, no call', count($res['done_ids']) === 3 && (int) ($res['run']['cached'] ?? 0) === 3 && (int) ($res['run']['calls'] ?? 0) === 0);

// Page changed → cache miss.
$GLOBALS['__posts'][$cands[0]]['post_content'] .= '<p>Them</p>';
check('cache: edited page gets a new hash', Advisor::hash(Advisor::context($cands[0])) !== ($s['hash'] ?? ''));

// Fatal (bad key) pauses the run with the reason, keeps pending pages.
$run3 = Advisor::start();
$reset_rate();
$GLOBALS['__http_queue'] = [['code' => 403, 'body' => json_encode(['error' => ['message' => 'Permission denied']])]];
$res = Advisor::step($run3, $g, 'gemini-2.5-flash');
check('fatal: run paused with reason, pages stay pending', $res['fatal'] !== '' && $res['run']['status'] === 'paused' && Advisor::state($res['run'])['pending'] > 0);

// Apply: only ticked fields are written.
$id = $cands[1];
$applied = Advisor::apply($id, ['seo_title' => true]);
check('apply: only ticked field written', $applied === ['seo_title'] && get_post_meta($id, '_tqk_wpseo_title', true) === "Tieu de {$id}" && get_post_meta($id, '_tqk_wpseo_description', true) === '');
$GLOBALS['__can'] = false;
check('apply: needs edit rights on the post', Advisor::apply($id, ['seo_description' => true]) === []);
$GLOBALS['__can'] = true;

echo $fail === 0 ? "\nALL S17 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
