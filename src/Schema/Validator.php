<?php
namespace TQK_WPSEO\Schema;

defined('ABSPATH') || exit;

/**
 * S15 — Validates a post's schema data before the builder emits anything.
 *
 * A malformed node is worse than no node: Google drops the whole graph, and a
 * half-filled FAQPage or Recipe with a missing step does exactly that. So the
 * builder refuses to emit unless this passes.
 */
class Validator {

    /**
     * @return array[] List of {field, message} for every unmet rule. Empty = valid.
     */
    public static function errors(int $post_id, string $type): array {
        $specs = Fields::for($type);
        if ($specs === []) {
            return [];
        }
        $errors = [];
        foreach ($specs as $name => $spec) {
            $raw = (string) get_post_meta($post_id, (string) $spec['key'], true);
            $value = trim($raw);
            if ($value === '') {
                if ($spec['required']) {
                    $errors[] = [
                        'field' => $name,
                        'message' => sprintf(__('%s is required for %s.', 'tqk-wp-seo'), $spec['label'], $type),
                    ];
                }
                continue;
            }
            foreach (self::rules_for($name) as $rule) {
                $fail = self::check($rule, $value, $name, $spec);
                if ($fail !== null) {
                    $errors[] = ['field' => $name, 'message' => $fail];
                }
            }
        }
        return $errors;
    }

    public static function is_valid(int $post_id, string $type): bool {
        return self::errors($post_id, $type) === [];
    }

    /**
     * Per-field format rules. Duration fields share one rule set; ratings and
     * dates get their own because a wrong value there is silently useless.
     *
     * @return array[] Each: [kind, param, message]
     */
    private static function rules_for(string $name): array {
        $duration = [
            ['kind' => 'duration', 'param' => '', 'message' => __('Use ISO 8601 duration (PT30M) or a readable time like 30 phút.', 'tqk-wp-seo')],
        ];
        return match ($name) {
            'prep_time', 'cook_time', 'total_time', 'howto_total_time' => $duration,
            'course_rating' => [
                ['kind' => 'number_range', 'param' => [0, 5], 'message' => __('Rating must be between 0 and 5.', 'tqk-wp-seo')],
            ],
            'course_hours' => [
                ['kind' => 'number_range', 'param' => [0, 10000], 'message' => __('Hours must be a positive number.', 'tqk-wp-seo')],
            ],
            'event_start', 'event_end' => [
                ['kind' => 'date', 'param' => '', 'message' => __('Use a valid date and time.', 'tqk-wp-seo')],
            ],
            default => [],
        };
    }

    /** @return string|null Error message, or null when the value passes. */
    private static function check(array $rule, string $value, string $name, array $spec) {
        switch ($rule['kind']) {
            case 'duration':
                return Builder::iso_duration($value) === ''
                    ? $rule['message']
                    : null;

            case 'number_range':
                if (!is_numeric(str_replace(',', '.', $value))) {
                    return $rule['message'];
                }
                $n = (float) str_replace(',', '.', $value);
                [$min, $max] = $rule['param'];
                return ($n < $min || $n > $max) ? $rule['message'] : null;

            case 'date':
                return self::is_dateish($value) ? null : $rule['message'];

            default:
                return null;
        }
    }

    /**
     * HTML datetime-local gives "2026-10-01T09:00"; users paste with a space or
     * a full ISO stamp. All are acceptable input, none produce a warning.
     */
    private static function is_dateish(string $value): bool {
        $v = trim(str_replace('T', ' ', $value));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            [$y, $m, $d] = array_map('intval', explode('-', $v));
            return checkdate($m, $d, $y);
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ ](\d{2}):(\d{2})/', $v, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] <= 23 && (int) $m[5] <= 59;
    }
}
