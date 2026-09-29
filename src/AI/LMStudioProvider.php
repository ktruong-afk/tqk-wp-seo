<?php
namespace TQK_WPSEO\AI;

defined('ABSPATH') || exit;

/** S6 — LM Studio (OpenAI-compatible /v1/chat/completions). */
class LMStudioProvider implements ProviderInterface {

    public function __construct(private string $endpoint, private string $model) {
    }

    public function name(): string {
        return 'lmstudio';
    }

    public function generate(string $prompt, array $context = []): string {
        $res = wp_remote_post(rtrim($this->endpoint, '/') . '/v1/chat/completions', [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.7,
            ]),
        ]);
        if (is_wp_error($res)) {
            return '';
        }
        $decoded = json_decode(wp_remote_retrieve_body($res), true);
        return trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
    }
}
