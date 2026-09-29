<?php
namespace TQK_WPSEO\AI;

defined('ABSPATH') || exit;

/** S6 — Builds provider from settings. Null when AI is disabled. */
class Factory {

    public static function create(): ?ProviderInterface {
        if (!\TQK_WPSEO\Core\Modules::is_active('ai')) {
            return null;
        }
        $provider = (string) \TQK_WPSEO\Admin\Settings::get('ai_provider', 'none');
        if ($provider === 'gemini') {
            $key = (string) \TQK_WPSEO\Admin\Settings::get('gemini_api_key', '');
            $model = (string) \TQK_WPSEO\Admin\Settings::get('gemini_model', '');
            return $key !== '' && GeminiProvider::valid_model($model) ? new GeminiProvider($key, $model) : null;
        }
        $endpoint = (string) \TQK_WPSEO\Admin\Settings::get('ai_endpoint', 'http://127.0.0.1:11434');
        $model = (string) \TQK_WPSEO\Admin\Settings::get('ai_model', 'llama3.1');
        if ($endpoint === '') {
            return null;
        }
        return match ($provider) {
            'ollama' => new OllamaProvider($endpoint, $model),
            'lmstudio' => new LMStudioProvider($endpoint, $model),
            default => null,
        };
    }
}
