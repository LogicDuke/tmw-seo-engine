<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Services;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

/**
 * Security event capture.
 *
 * Hooks WordPress's authentication, authorization, and account-lifecycle
 * events and writes each one to the tmw_logs table via the Logs facade.
 * Before this, security incidents left no breadcrumb trail anywhere in the
 * stack — the audit's stated concern is exactly that gap.
 *
 * What we capture and why:
 *   - wp_login_failed             — brute force / cred-stuffing attempts
 *   - wp_login                    — session-origin for incident response
 *   - wp_logout                   — completes the session timeline
 *   - retrieve_password           — possible account-takeover prep
 *   - password_reset              — confirms account takeover succeeded
 *   - user_register               — unauthorised registrations / spam
 *   - delete_user                 — destructive admin action
 *   - set_user_role               — privilege-escalation visibility
 *   - auth_cookie_bad_hash        — session-cookie forgery attempts
 *   - auth_cookie_bad_username    — session-cookie forgery attempts
 *
 * What we deliberately don't capture, and why:
 *   - Generic permission denials. No single WP hook fires for every
 *     current_user_can() == false; capturing them would require auditing
 *     each AJAX / admin handler individually. Out of scope for this layer.
 *   - Generic nonce failures. check_admin_referer() doesn't emit a
 *     dedicated hook on failure. Same reason as above.
 *
 * Test environment: the engine's PHPUnit bootstrap stubs add_action() as a
 * no-op, so this class's register() call results in ten no-op invocations
 * and the handlers themselves never fire during tests. No conditional
 * guards are needed inside the handlers.
 */
class SecurityEvents {

    /**
     * Register all hook handlers. Called once at file load.
     */
    public static function register(): void {
        add_action('wp_login_failed',          [self::class, 'on_login_failed'],            10, 2);
        add_action('wp_login',                 [self::class, 'on_login_success'],           10, 2);
        add_action('wp_logout',                [self::class, 'on_logout'],                  10, 1);
        add_action('retrieve_password',        [self::class, 'on_password_reset_requested'],10, 1);
        add_action('password_reset',           [self::class, 'on_password_reset_completed'],10, 2);
        add_action('user_register',            [self::class, 'on_user_register'],           10, 1);
        add_action('delete_user',              [self::class, 'on_user_delete'],             10, 2);
        add_action('set_user_role',            [self::class, 'on_role_change'],             10, 3);
        add_action('auth_cookie_bad_hash',     [self::class, 'on_bad_cookie_hash'],         10, 1);
        add_action('auth_cookie_bad_username', [self::class, 'on_bad_cookie_username'],     10, 1);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Handlers
    // ────────────────────────────────────────────────────────────────────────

    public static function on_login_failed(string $username, $error = null): void {
        Logs::warn('security', 'Login failed', [
            'username' => self::safe_username($username),
            'ip'       => self::client_ip(),
            'ua'       => self::user_agent(),
        ]);
    }

    public static function on_login_success(string $user_login, $user): void {
        Logs::info('security', 'Login succeeded', [
            'user_id'    => self::user_id_of($user),
            'user_login' => self::safe_username($user_login),
            'ip'         => self::client_ip(),
            'ua'         => self::user_agent(),
        ]);
    }

    public static function on_logout($user_id = 0): void {
        Logs::info('security', 'Logout', [
            'user_id' => (int) $user_id,
            'ip'      => self::client_ip(),
        ]);
    }

    public static function on_password_reset_requested(string $user_login): void {
        Logs::info('security', 'Password reset requested', [
            'user_login' => self::safe_username($user_login),
            'ip'         => self::client_ip(),
            'ua'         => self::user_agent(),
        ]);
    }

    public static function on_password_reset_completed($user, string $new_pass = ''): void {
        // $new_pass is in the WP action signature but deliberately ignored —
        // never log secrets, even briefly.
        Logs::warn('security', 'Password reset completed', [
            'user_id' => self::user_id_of($user),
            'ip'      => self::client_ip(),
        ]);
    }

    public static function on_user_register(int $user_id): void {
        $login = '';
        $email_hash = '';
        if (function_exists('get_userdata')) {
            $user = get_userdata($user_id);
            if ($user) {
                $login = self::safe_username((string) ($user->user_login ?? ''));
                // Hash the email so log readers can correlate registrations
                // without exposing the address itself. SHA-256 keyed on
                // wp_salt('auth') prevents trivial rainbow-table lookup.
                $salt = function_exists('wp_salt') ? wp_salt('auth') : '';
                $email_hash = hash('sha256', strtolower((string) ($user->user_email ?? '')) . $salt);
            }
        }
        Logs::info('security', 'User registered', [
            'user_id'    => $user_id,
            'user_login' => $login,
            'email_hash' => $email_hash,
            'ip'         => self::client_ip(),
        ]);
    }

    public static function on_user_delete(int $id, $reassign = null): void {
        Logs::warn('security', 'User deleted', [
            'target_user_id'   => $id,
            'actor_user_id'    => self::current_user_id(),
            'reassign_user_id' => $reassign === null ? null : (int) $reassign,
            'ip'               => self::client_ip(),
        ]);
    }

    public static function on_role_change(int $user_id, string $role, array $old_roles): void {
        Logs::warn('security', 'User role changed', [
            'target_user_id' => $user_id,
            'actor_user_id'  => self::current_user_id(),
            'new_role'       => $role,
            'old_roles'      => $old_roles,
            'ip'             => self::client_ip(),
        ]);
    }

    public static function on_bad_cookie_hash($cookie_elements): void {
        Logs::warn('security', 'Auth cookie bad hash', self::cookie_log_data($cookie_elements));
    }

    public static function on_bad_cookie_username($cookie_elements): void {
        Logs::warn('security', 'Auth cookie bad username', self::cookie_log_data($cookie_elements));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the real client IP. Prefers Cloudflare's CF-Connecting-IP
     * (set by the edge, unforwardable by clients) so a CF-fronted origin
     * doesn't log the same edge IP for every request. Falls back to
     * REMOTE_ADDR. Deliberately does NOT trust X-Forwarded-For — it's
     * client-spoofable when the origin is reachable directly.
     */
    private static function client_ip(): string {
        $ip = '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? (string) $ip : '0.0.0.0';
    }

    /**
     * Truncate user-agent to bound log-row width. Some attacker UAs are
     * long, malformed, or injection probes; we still want the data for
     * forensics but capped.
     */
    private static function user_agent(): string {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return substr($ua, 0, 240);
    }

    /**
     * Sanitize and truncate a username string before it lands in the log.
     * On failed-login events the "username" field is whatever the attacker
     * typed — it may contain SQL/XSS probes; sanitize_text_field strips
     * tags and control chars, the substr bounds the size.
     */
    private static function safe_username(string $u): string {
        return substr(sanitize_text_field($u), 0, 100);
    }

    /**
     * Best-effort current user ID resolution. Used to record the actor
     * behind admin actions (delete_user, set_user_role).
     */
    private static function current_user_id(): int {
        return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }

    /**
     * Extract an integer user ID from whatever the hook gave us — could be
     * a WP_User object, an int, or null in malformed-cookie contexts.
     */
    private static function user_id_of($user): int {
        if (is_object($user) && isset($user->ID)) {
            return (int) $user->ID;
        }
        if (is_numeric($user)) {
            return (int) $user;
        }
        return 0;
    }

    /**
     * Project the WP cookie-elements array into a log row, stripping the
     * actual HMAC/token values (we don't want secrets in logs) while
     * keeping enough context to correlate with other events.
     */
    private static function cookie_log_data($cookie_elements): array {
        $username = '';
        $scheme   = '';
        if (is_array($cookie_elements)) {
            $username = self::safe_username((string) ($cookie_elements['username'] ?? ''));
            $scheme   = substr((string) ($cookie_elements['scheme'] ?? ''), 0, 32);
        }
        return [
            'username' => $username,
            'scheme'   => $scheme,
            'ip'       => self::client_ip(),
            'ua'       => self::user_agent(),
        ];
    }
}

SecurityEvents::register();
