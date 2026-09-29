<?php
namespace TQK_WPSEO\Schema;

defined('ABSPATH') || exit;

/**
 * S15 — Field specs per schema type.
 *
 * One declarative table drives three things: the metabox form, the validator,
 * and the builder. Adding a type means adding a row here, not new branching
 * code in the render/save/build paths.
 */
class Fields {

    public const META_PREFIX = '_tqk_wpseo_schema_';

    /** @return string[] Types that have a dynamic form (i.e. need own data). */
    public static function types(): array {
        return ['FAQPage', 'HowTo', 'Recipe', 'Course', 'Event'];
    }

    /** Field spec lookup for a type. [] when the type needs no extra data. */
    public static function for(string $type): array {
        $specs = self::specs();
        return $specs[$type] ?? [];
    }

    public static function has_fields(string $type): bool {
        return self::for($type) !== [];
    }

    public static function key(string $name): string {
        return self::META_PREFIX . $name;
    }

    public static function label(string $type, string $name): string {
        return (string) (self::for($type)[$name]['label'] ?? $name);
    }

    /**
     * Spec table. Each entry: label, type (text|textarea|datetime|number|select),
     * required (bool), options (for select), and help text.
     */
    public static function specs(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $f = function (string $label, string $type, bool $required = false, array $options = [], string $help = '') {
            return [
                'label' => $label,
                'type' => $type,
                'required' => $required,
                'options' => $options,
                'help' => $help,
            ];
        };

        $cache = [
            'FAQPage' => [
                'faq_items' => [
                    'label' => __('FAQ items', 'tqk-wpseo'),
                    'type' => 'textarea',
                    'required' => true,
                    'options' => [],
                    'help' => __('One pair per block: question on one line, answer on the next, blank line between pairs.', 'tqk-wpseo'),
                ],
            ],
            'HowTo' => [
                'howto_name' => $f(__('How-to title', 'tqk-wpseo'), 'text', false),
                'howto_description' => $f(__('Short summary', 'tqk-wpseo'), 'textarea', false),
                'howto_total_time' => $f(__('Total time', 'tqk-wpseo'), 'text', false, [], __('e.g. 45 phút or PT45M.', 'tqk-wpseo')),
                'howto_tools' => $f(__('Tools (one per line)', 'tqk-wpseo'), 'textarea', false),
                'howto_supplies' => $f(__('Supplies / materials (one per line)', 'tqk-wpseo'), 'textarea', false),
                'howto_steps' => [
                    'label' => __('Steps (one per line)', 'tqk-wpseo'),
                    'type' => 'textarea',
                    'required' => true,
                    'options' => [],
                    'help' => __('One step per line. Numbering is added automatically.', 'tqk-wpseo'),
                ],
            ],
            'Recipe' => [
                'recipe_name' => $f(__('Recipe name', 'tqk-wpseo'), 'text', false),
                'recipe_description' => $f(__('Recipe description', 'tqk-wpseo'), 'textarea', false),
                'recipe_yield' => $f(__('Yield', 'tqk-wpseo'), 'text', false, [], __('e.g. 4 servings.', 'tqk-wpseo')),
                'prep_time' => $f(__('Prep time', 'tqk-wpseo'), 'text', false, [], __('e.g. 15 phút or PT15M.', 'tqk-wpseo')),
                'cook_time' => $f(__('Cook time', 'tqk-wpseo'), 'text', false),
                'total_time' => $f(__('Total time', 'tqk-wpseo'), 'text', false),
                'ingredients' => [
                    'label' => __('Ingredients (one per line)', 'tqk-wpseo'),
                    'type' => 'textarea',
                    'required' => true,
                    'options' => [],
                    'help' => __('One ingredient per line.', 'tqk-wpseo'),
                ],
                'instructions' => [
                    'label' => __('Instructions (one step per line)', 'tqk-wpseo'),
                    'type' => 'textarea',
                    'required' => true,
                    'options' => [],
                    'help' => __('One step per line.', 'tqk-wpseo'),
                ],
            ],
            'Course' => [
                'course_provider' => $f(__('Provider', 'tqk-wpseo'), 'text', true),
                'course_description' => $f(__('Course description', 'tqk-wpseo'), 'textarea', false),
                'course_level' => $f(
                    __('Level', 'tqk-wpseo'),
                    'select',
                    false,
                    [
                        '' => __('Not set', 'tqk-wpseo'),
                        'beginner' => __('Beginner', 'tqk-wpseo'),
                        'intermediate' => __('Intermediate', 'tqk-wpseo'),
                        'advanced' => __('Advanced', 'tqk-wpseo'),
                    ]
                ),
                'course_hours' => $f(__('Total hours', 'tqk-wpseo'), 'number', false),
                'course_rating' => $f(__('Average rating (0–5)', 'tqk-wpseo'), 'number', false, [], __('Only set this if the rating comes from real learner reviews.', 'tqk-wpseo')),
                'course_rating_count' => $f(__('Number of ratings', 'tqk-wpseo'), 'number', false),
                'course_url' => $f(__('Course URL', 'tqk-wpseo'), 'url', false),
            ],
            'Event' => [
                'event_start' => $f(__('Start date & time', 'tqk-wpseo'), 'datetime', true),
                'event_end' => $f(__('End date & time', 'tqk-wpseo'), 'datetime', false),
                'event_location' => $f(__('Location', 'tqk-wpseo'), 'text', true),
                'event_address' => $f(__('Full address', 'tqk-wpseo'), 'text', false),
                'event_offers' => $f(
                    __('Attendance mode', 'tqk-wpseo'),
                    'select',
                    false,
                    [
                        '' => __('Not set', 'tqk-wpseo'),
                        'OfflineEventAttendanceMode' => 'In person',
                        'OnlineEventAttendanceMode' => 'Online',
                        'HybridEventAttendanceMode' => 'Hybrid',
                    ]
                ),
            ],
        ];

        foreach ($cache as $type => $fields) {
            foreach ($fields as $name => $spec) {
                $cache[$type][$name]['key'] = self::key($name);
            }
        }
        return $cache;
    }
}
