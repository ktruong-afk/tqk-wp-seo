<?php
namespace TQK_WPSEO\AI;

defined('ABSPATH') || exit;

/** S6 — Ollama (http://127.0.0.1:11434 by default). */
class OllamaProvider implements ProviderInterface {

    public function __construct(private string $endpoint, private string $model) {
    }

    public function name(): string {
        return 'ollama';
    }

    public function generate(string $prompt, array $context = []): string {
        $res = wp_remote_post(rtrim($this->endpoint, '/') . '/api/generate', [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'model' => $this->model,
                'prompt' => $prompt,
                'stream' => false,
                'options' => ['temperature' => 0.7],
            ]),
        ]);
        if (is_wp_error($res)) {
            return '';
        }
        $decoded = json_decode(wp_remote_retrieve_body($res), true);
        return trim((string) ($decoded['response'] ?? ''));
    }
}
