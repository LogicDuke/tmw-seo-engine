<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Services;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

/**
 * Capabilities — capability-gate helper with built-in denial logging.
 *
 * The audit's stated concern: every admin handler that enforces
 * `current_user_can(...)` and then `wp_die('Insufficient permissions')`
 * leaves zero audit trail. A staff member or compromised admin account
 * probing the system can hammer protected endpoints and the only
 * evidence is a 403-ish response — no row in wp_tmw_logs, no entry in
 * the PHP error log, nothing the ops team can review after the fact.
 *
 * Capabilities::ensure() bundles the cap check, the denial log entry,
 * and the wp_die in one call. Migrating an existing handler is a
 * mechanical edit:
 *
 *   - if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
 *   + Capabilities::ensure('manage_options', 'Unauthorized');
 *
 * Logs land under context='security' so they sort alongside the
 * authentication-event rows already written by Services\SecurityEvents
 * (login_failed, role_change, auth-cookie tampering, etc.) — one
 * filterable feed in the engine's log viewer becomes the source of
 * truth for "who tried what."
 */
class Capabilities {

    /**
     * Require the current user to hold $cap. If they do, returns void
     * normally. If they don't, writes a 'security' / 'Permission denied'
     * row to wp_tmw_logs and terminates the request with wp_die.
     *
     * @param string $cap     WordPress capability (manage_options, edit_posts, …).
     * @param string $message Message shown to the denied user. Defaults
     *                        to the existing engine string for back-compat.
     */
    public static function ensure(string $cap, string $message = 'Insufficient permissions'): void {
        if (current_user_can($cap)) {
            return;
        }

        Logs::warn('security', 'Permission denied', [
            'cap'     => $cap,
            'user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'ip'      => self::client_ip(),
            'ua'      => self::user_agent(),
            'request' => self::request_path(),
            'referer' => self::referer(),
        ]);

        wp_die(esc_html($message));
    }

    /**
     * Same as ensure() but redirects to $redirect instead of wp_die.
     * For admin handlers that prefer to bounce the user back with a
     * notice rather than show a die screen.
     *
     * @param string $cap
     * @param string $redirect Absolute or relative URL passed to wp_safe_redirect.
     * @param string $message  Reason string included in the log row.
     */
    public static function ensure_or_redirect(string $cap, string $redirect, string $message = 'Insufficient permissions'): void {
        if (current_user_can($cap)) {
            return;
        }

        Logs::warn('security', 'Permission denied', [
            'cap'      => $cap,
            'user_id'  => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'ip'       => self::client_ip(),
            'ua'       => self::user_agent(),
            'request'  => self::request_path(),
            'referer'  => self::referer(),
            'redirect' => substr($redirect, 0, 240),
            'reason'   => substr($message, 0, 240),
        ]);

        wp_safe_redirect($redirect);
        exit;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers — same shape as SecurityEvents to keep log payloads consistent.
    // (Could be lifted to a shared trait later if we end up with a third
    //  consumer; two callers is below the de-dup threshold for now.)
    // ────────────────────────────────────────────────────────────────────────

    private static function client_ip(): string {
        $ip = '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = (string) $_SERVER['REMOTE_ADDR'];
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    private static function user_agent(): string {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240);
    }

    /**
     * Strip the query string before logging — query strings can carry
     * nonces and reset tokens that we don't want sitting in the
     * security log. Matches the path-only convention the retrotube-
     * child-v3 boot guard uses for the same reason.
     */
    private static function request_path(): string {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? substr($path, 0, 240) : '';
    }

    private static function referer(): string {
        $ref = function_exists('wp_get_referer') ? wp_get_referer() : '';
        if (!is_string($ref) || $ref === '') {
            return '';
        }
        // Same query-string strip as request_path() — a referer landing
        // page often carries the nonces the user just consumed.
        $path = parse_url($ref, PHP_URL_PATH);
        $host = parse_url($ref, PHP_URL_HOST);
        $out  = '';
        if (is_string($host) && $host !== '') $out .= $host;
        if (is_string($path) && $path !== '') $out .= $path;
        return substr($out, 0, 240);
    }
}
