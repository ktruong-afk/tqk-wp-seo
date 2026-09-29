<?php
namespace TQK_WPSEO\AI;

defined('ABSPATH') || exit;

/**
 * Server-side pacing for hosted AI calls (Gemini free tier first).
 *
 * The limit lives in the database, not in the browser: two open tabs, a
 * metabox button and the bulk queue all share one budget. PHP never sleeps
 * for long here; callers get "wait N seconds" back and come back later.
 *
 *   - per minute: calls are spaced 60 / rpm seconds apart (plus jitter);
 *   - per day: counted per Pacific-time day, the day Google resets quota on;
 *   - blocked_until: set from a 429's retryDelay, or to the next reset when
 *     the daily quota is gone.
 */
class RateLimiter {

    public const STATE = 'tqk_wpseo_ai_rate';
    public const LOCK = 'tqk_wpseo_ai_lock';
    public const TZ = 'America/Los_Angeles';

    public static function rpm(): int {
        return max(1, min(1000, (int) \TQK_WPSEO\Admin\Settings::get('ai_rpm', 5)));
    }

    public static function rpd(): int {
        return max(1, min(100000, (int) \TQK_WPSEO\Admin\Settings::get('ai_rpd', 200)));
    }

    /** Pacific-time day key, e.g. 2026-09-27. */
    public static function day(int $now): string {
        return (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');
    }

    /** Unix time of the next Pacific midnight (+60 s margin). */
    public static function next_reset(int $now): int {
        $d = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(self::TZ))->setTime(0, 0)->modify('+1 day');
        return $d->getTimestamp() + 60;
    }

    public static function state(int $now): array {
        $s = get_option(self::STATE, []);
        $s = is_array($s) ? $s : [];
        $day = self::day($now);
        if (($s['day'] ?? '') !== $day) {
            $s['day'] = $day;
            $s['count'] = 0;
            if (!empty($s['daily'])) {
                $s['daily'] = false;
                $s['blocked_until'] = 0;
            }
        }
        return $s + ['last' => 0, 'count' => 0, 'blocked_until' => 0, 'daily' => false];
    }

    /**
     * Seconds to wait before the next call may go out; 0 means "go now".
     *
     * @return array{wait:int, daily:bool, used:int, limit:int}
     */
    public static function check(?int $now = null): array {
        $now = $now ?? time();
        $s = self::state($now);
        $out = ['wait' => 0, 'daily' => false, 'used' => (int) $s['count'], 'limit' => self::rpd()];
        if ((int) $s['count'] >= self::rpd()) {
            return ['wait' => max(1, self::next_reset($now) - $now), 'daily' => true] + $out;
        }
        if ((int) $s['blocked_until'] > $now) {
            return ['wait' => (int) $s['blocked_until'] - $now, 'daily' => !empty($s['daily'])] + $out;
        }
        $gap = (int) ceil(60 / self::rpm());
        $next = (int) $s['last'] + $gap;
        if ($next > $now) {
            $out['wait'] = $next - $now;
        }
        return $out;
    }

    /**
     * Claims the slot for one call. Atomic via add_option (fails when the
     * row exists), so two requests racing for the same slot cannot both win.
     *
     * @return array{ok:bool, wait:int, daily:bool}
     */
    public static function acquire(?int $now = null): array {
        $now = $now ?? time();
        $held = (int) get_option(self::LOCK, 0);
        if ($held > 0 && $held < $now - 90) {
            delete_option(self::LOCK); // stale lock from a crashed request
        }
        if (!add_option(self::LOCK, $now, '', false)) {
            return ['ok' => false, 'wait' => 3, 'daily' => false];
        }
        $c = self::check($now);
        if ($c['wait'] > 0) {
            delete_option(self::LOCK);
            return ['ok' => false, 'wait' => $c['wait'], 'daily' => $c['daily']];
        }
        $s = self::state($now);
        $s['last'] = $now + random_int(0, 2);
        $s['count'] = (int) $s['count'] + 1;
        update_option(self::STATE, $s, false);
        delete_option(self::LOCK);
        return ['ok' => true, 'wait' => 0, 'daily' => false];
    }

    /** Google said slow down: honour its delay, or the next reset for daily quota. */
    public static function block(int $seconds, bool $daily = false, ?int $now = null): int {
        $now = $now ?? time();
        $s = self::state($now);
        $until = $daily ? self::next_reset($now) : $now + max(5, $seconds);
        $s['blocked_until'] = max((int) $s['blocked_until'], $until);
        $s['daily'] = $daily || !empty($s['daily']);
        update_option(self::STATE, $s, false);
        return $s['blocked_until'] - $now;
    }
}
