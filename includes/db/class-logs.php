<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class Logs {

    /**
     * Sentinel that replaces redacted values so log readers can see a
     * scrub happened (vs. data simply being absent).
     */
    private const REDACTED_PLACEHOLDER = '[REDACTED]';

    public static function add(string $level, string $context, string $message, array $data = []): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_logs';
        // Defence-in-depth: redact sensitive-shaped keys from $data before
        // JSON-encoding. Today no caller passes credentials to Logs::*,
        // but the data column has no schema constraint preventing one
        // from doing so — and once a secret lands in wp_tmw_logs it
        // persists for the full retention window AND in any backup taken
        // during that window. Cheaper to scrub up-front than to chase
        // a future leak through forensics.
        $scrubbed = !empty($data) ? self::redact_sensitive($data) : [];
        $wpdb->insert(
            $table,
            [
                'time' => current_time('mysql'),
                'level' => $level,
                'context' => $context,
                'message' => $message,
                'data' => !empty($scrubbed) ? wp_json_encode($scrubbed) : null,
            ],
            ['%s','%s','%s','%s','%s']
        );
    }

    /**
     * Substring patterns matched case-insensitively against array keys.
     * Conservative — we use substring matching rather than exact whitelist
     * because credentials get spelled many ways (api_key, apikey,
     * dataforseo_password, refresh_token, gsc_client_secret …). The
     * list deliberately excludes overly-generic stems like "key" alone
     * because that would silently redact legitimate fields such as
     * cache_key, batch_key, hash_key.
     *
     * Filterable via `tmwseo_logs_redact_keys` so operators can extend
     * (or, at their own risk, narrow) the set without editing engine code.
     */
    private static function sensitive_key_patterns(): array {
        $patterns = [
            'password', 'passwd', 'pwd',
            'secret',
            'api_key', 'apikey', 'api-key',
            'access_token', 'refresh_token', 'auth_token', 'id_token', 'bearer',
            'private_key', 'signing_key', 'encryption_key',
            'authorization',
            'credentials',
        ];
        $filtered = apply_filters('tmwseo_logs_redact_keys', $patterns);
        return is_array($filtered) ? array_values(array_filter(array_map('strval', $filtered))) : $patterns;
    }

    /**
     * Walk an array and replace any value whose KEY matches a sensitive
     * pattern with the redacted placeholder. Recurses into nested arrays
     * for non-sensitive keys; for sensitive keys, replaces the whole
     * subtree (so e.g. ['credentials' => ['user'=>'u', 'pass'=>'p']]
     * becomes ['credentials' => '[REDACTED]'] rather than leaking the
     * username).
     *
     * What this DOESN'T catch — flagged so a future audit knows the
     * limits, not so the next reader expands the scope without thinking:
     *  - Secrets buried inside STRING VALUES (e.g. a URL with a token
     *    in the query string, or a JSON-stringified body containing a
     *    "password" field). Catching those would need substring or regex
     *    scanning of every string in the log payload — too expensive
     *    and too false-positive-prone for the value it adds.
     *  - Secrets stored under numeric keys.
     *  - Secrets stored as object properties (rare in our call sites).
     */
    private static function redact_sensitive(array $data): array {
        $patterns = self::sensitive_key_patterns();
        foreach ($data as $key => $value) {
            if (is_string($key) && $patterns !== []) {
                $key_lc = strtolower($key);
                foreach ($patterns as $p) {
                    if ($p !== '' && strpos($key_lc, strtolower($p)) !== false) {
                        $data[$key] = self::REDACTED_PLACEHOLDER;
                        continue 2; // skip the recurse-into-array branch
                    }
                }
            }
            if (is_array($value)) {
                $data[$key] = self::redact_sensitive($value);
            }
        }
        return $data;
    }

    public static function debug(string $context, string $message, array $data = []): void { self::add('debug', $context, $message, $data); }
    public static function info(string $context, string $message, array $data = []): void { self::add('info', $context, $message, $data); }
    public static function warn(string $context, string $message, array $data = []): void { self::add('warn', $context, $message, $data); }
    // PSR-3 spells this `warning`; the engine has 45 sites using warn() and
    // 7 newer sites using warning(). The latter were silently fatal-error
    // landmines until this alias existed. Both spellings persist for back-compat.
    public static function warning(string $context, string $message, array $data = []): void { self::warn($context, $message, $data); }
    public static function error(string $context, string $message, array $data = []): void { self::add('error', $context, $message, $data); }

    /**
     * Delete log rows older than the retention window. Without this the
     * tmw_logs table grows unboundedly — every cron tick, every API call,
     * every security event adds at least one row. After a year on a busy
     * site the table is millions of rows, slowing the admin-side log
     * viewer and bloating database backups.
     *
     * Defaults to 30 days. Filterable via `tmwseo_logs_retention_days`:
     * compliance frameworks (SOC 2, GDPR breach notification) may require
     * longer retention; ops on a small site may want shorter. Passing
     * 0 or negative through the filter disables pruning entirely.
     *
     * DELETE runs in chunks (LIMIT batch_size, loop until done) so a
     * one-million-row first-sweep doesn't hold the write lock for the
     * full duration of the operation. The single-column `time_idx` KEY
     * added in class-schema.php makes each batch index-served — the
     * composite `level_time` / `context_time` keys don't help a
     * time-only filter because their leading columns are level / context.
     *
     * Called from Cron::daily() (existing daily WP-Cron tick).
     *
     * @param int $retention_days Default retention window in days.
     * @param int $batch_size     Max rows per DELETE query (100..50000).
     * @return int Total rows deleted in this prune run.
     */
    public static function prune(int $retention_days = 30, int $batch_size = 5000): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_logs';

        $retention_days = (int) apply_filters('tmwseo_logs_retention_days', $retention_days);
        if ($retention_days <= 0) {
            return 0;
        }

        $cutoff     = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));
        $batch_size = max(100, min(50000, $batch_size));

        $deleted = 0;
        // Hard cap on loop iterations as a belt-and-braces guard against
        // a pathological case (e.g. row count growing during the prune).
        // 200 × 50000 = 10M rows max per daily run, well above any
        // realistic backlog.
        for ($i = 0; $i < 200; $i++) {
            $batch = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE time < %s LIMIT %d",
                $cutoff,
                $batch_size
            ));
            if ($batch === false) {
                break;
            }
            $batch    = (int) $batch;
            $deleted += $batch;
            if ($batch < $batch_size) {
                break;
            }
        }

        return $deleted;
    }

    public static function latest(int $limit = 200, string $level = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_logs';
        $limit = max(1, min(1000, $limit));
        if ($level !== '') {
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $table WHERE level = %s ORDER BY id DESC LIMIT %d", $level, $limit),
                ARRAY_A
            );
        }
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );
    }
}
