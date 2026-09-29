<?php
namespace TQK_WPSEO\AI;

defined('ABSPATH') || exit;

/** S6 — AI provider contract. Code scores; providers only suggest/explain. */
interface ProviderInterface {

    public function name(): string;

    /** @return string Raw model output (empty on failure). */
    public function generate(string $prompt, array $context = []): string;
}
