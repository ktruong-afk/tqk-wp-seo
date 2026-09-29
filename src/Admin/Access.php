<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

/**
 * Role Manager: grants a custom capability to chosen roles so non-admins
 * can use SEO screens. Admins always pass via manage_options.
 * Storage: settings['seo_roles'] = [role => bool]. Sync applies WP caps.
 */
class Access {

    public const CAP = 'tqk_seo_access';

    /** @return string[] Manageable role slugs. */
    public static function roles(): array {
        return ['editor', 'author', 'contributor'];
    }

    public static function can(): bool {
        return current_user_can('manage_options') || current_user_can(self::CAP);
    }

    /** @return array Role => bool saved states. */
    public static function states(): array {
        $saved = Settings::get('seo_roles', []);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::roles() as $r) {
            $out[$r] = !empty($saved[$r]);
        }
        return $out;
    }

    public static function hooks(): void {
        add_action('admin_init', [self::class, 'ensure_admin']);
    }

    /** Admins always hold the cap so menus never vanish on old installs. */
    public static function ensure_admin(): void {
        if (!function_exists('get_role')) {
            return;
        }
        $admin = get_role('administrator');
        if ($admin && method_exists($admin, 'add_cap')) {
            $admin->add_cap(self::CAP);
        }
    }

    /** Applies saved states to WP roles. No-op when role API unavailable (tests). */
    public static function sync(): void {
        if (!function_exists('get_role')) {
            return;
        }
        $states = self::states();
        $admin = get_role('administrator');
        if ($admin && method_exists($admin, 'add_cap')) {
            $admin->add_cap(self::CAP);
        }
        foreach ($states as $role => $on) {
            $r = get_role($role);
            if (!$r) {
                continue;
            }
            if ($on && method_exists($r, 'add_cap')) {
                $r->add_cap(self::CAP);
            } elseif (!$on && method_exists($r, 'remove_cap')) {
                $r->remove_cap(self::CAP);
            }
        }
    }
}
