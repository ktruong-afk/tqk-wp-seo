<?php
namespace TQK_WPSEO\AI;

defined('ABSPATH') || exit;

/**
 * Google Gemini (Flash) over the Generative Language REST API.
 *
 * Every call goes through RateLimiter first. Structured output is used for
 * bulk work: Gemini is given a JSON schema and must answer in it, so code
 * reads fields instead of guessing at free text, and no quota is spent on
 * re-asking after a badly formatted answer.
 */
class GeminiProvider implements ProviderInterface {

    public const API = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(private string $key, private string $model) {
    }

    public function name(): string {
        return 'gemini';
    }

    public static function valid_model(string $m): bool {
        return (bool) preg_match('/^[a-z0-9][a-z0-9.\-]{1,62}$/', $m);
    }

    /**
     * Plain-text generate for the one-off metabox buttons. Waits a short
     * slot (max 15 s) instead of failing, because a person is clicking.
     */
    public function generate(string $prompt, array $context = []): string {
        $r = $this->call($prompt, null, 15);
        return $r['status'] === 'ok' ? trim((string) $r['text']) : '';
    }

    /**
     * @param array|null $schema Gemini responseSchema; null for free text.
     * @param int $max_wait Seconds this request may sleep for a slot (0 = never).
     * @return array{status:string, text:string, data:?array, wait:int, daily:bool, error:string}
     *   status: ok | wait (rate limited, try after 'wait' s) | fatal (key/model wrong) | error
     */
    public function call(string $prompt, ?array $schema = null, int $max_wait = 0): array {
        $out = ['status' => 'error', 'text' => '', 'data' => null, 'wait' => 0, 'daily' => false, 'error' => ''];
        if ($this->key === '' || !self::valid_model($this->model)) {
            return ['status' => 'fatal', 'error' => __('Gemini API key or model is missing.', 'tqk-wpseo')] + $out;
        }
        $slot = RateLimiter::acquire();
        if (!$slot['ok'] && $slot['wait'] <= $max_wait && !$slot['daily']) {
            sleep($slot['wait']);
            $slot = RateLimiter::acquire();
        }
        if (!$slot['ok']) {
            return ['status' => 'wait', 'wait' => $slot['wait'], 'daily' => $slot['daily']] + $out;
        }

        $gen = ['temperature' => 0.4];
        if ($schema !== null) {
            $gen['responseMimeType'] = 'application/json';
            $gen['responseSchema'] = $schema;
        }
        $res = wp_remote_post(self::API . rawurlencode($this->model) . ':generateContent', [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json', 'x-goog-api-key' => $this->key],
            'body' => wp_json_encode([
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => $gen,
            ]),
        ]);
        if (is_wp_error($res)) {
            return ['status' => 'wait', 'wait' => 30, 'error' => $res->get_error_message()] + $out;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        $json = json_decode($body, true);

        if ($code === 429) {
            $daily = stripos($body, 'PerDay') !== false;
            $wait = RateLimiter::block(self::retry_delay(is_array($json) ? $json : []), $daily);
            return ['status' => 'wait', 'wait' => $wait, 'daily' => $daily, 'error' => 'HTTP 429'] + $out;
        }
        if ($code >= 500) {
            return ['status' => 'wait', 'wait' => RateLimiter::block(30), 'error' => 'HTTP ' . $code] + $out;
        }
        if ($code === 400 || $code === 401 || $code === 403 || $code === 404) {
            $msg = (string) ($json['error']['message'] ?? ('HTTP ' . $code));
            return ['status' => 'fatal', 'error' => mb_substr($msg, 0, 300)] + $out;
        }
        $text = '';
        foreach ((array) ($json['candidates'][0]['content']['parts'] ?? []) as $p) {
            $text .= (string) ($p['text'] ?? '');
        }
        if ($text === '') {
            $why = (string) ($json['candidates'][0]['finishReason'] ?? ($json['promptFeedback']['blockReason'] ?? 'empty'));
            return ['error' => 'Gemini: ' . $why] + $out;
        }
        $out['status'] = 'ok';
        $out['text'] = $text;
        if ($schema !== null) {
            $data = json_decode($text, true);
            if (!is_array($data)) {
                return ['status' => 'error', 'error' => __('Gemini answered with invalid JSON.', 'tqk-wpseo')] + $out;
            }
            $out['data'] = $data;
        }
        return $out;
    }

    /** Seconds from google.rpc.RetryInfo ("12s", "0.5s"); 60 when absent. */
    public static function retry_delay(array $json): int {
        foreach ((array) ($json['error']['details'] ?? []) as $d) {
            $delay = (string) ($d['retryDelay'] ?? '');
            if ($delay !== '' && preg_match('/^(\d+(?:\.\d+)?)s$/', $delay, $m)) {
                return max(1, (int) ceil((float) $m[1]));
            }
        }
        return 60;
    }
}
