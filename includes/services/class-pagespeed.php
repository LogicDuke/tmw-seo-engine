<?php
namespace TMWSEO\Engine\Services;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class PageSpeed {

    public static function run_cycle_job(array $job): void {
        if (Settings::is_safe_mode()) {
            Logs::info('pagespeed', 'Safe mode enabled; skipping pagespeed cycle');
            return;
        }

        $urls = [ home_url('/') ];

        foreach ($urls as $url) {
            self::run_once($url, 'mobile');
            self::run_once($url, 'desktop');
        }

        Logs::info('pagespeed', 'Cycle completed', ['count' => count($urls)]);
    }

    private static function run_once(string $url, string $strategy = 'mobile'): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_pagespeed';

        $api = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
        $qs = [
            'url' => $url,
            'strategy' => $strategy,
        ];
        $key = trim((string)Settings::get('google_pagespeed_api_key', ''));
        if ($key !== '') $qs['key'] = $key;

        $request_url = add_query_arg($qs, $api);

        $resp = wp_remote_get($request_url, ['timeout' => 60]);
        if (is_wp_error($resp)) {
            Logs::warn('pagespeed', 'WP error', ['error' => $resp->get_error_message(), 'url' => $url, 'strategy' => $strategy]);
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300 || !is_array($json)) {
            Logs::warn('pagespeed', 'Bad response', ['code' => $code, 'url' => $url, 'strategy' => $strategy, 'body' => substr($raw, 0, 300)]);
            return;
        }

        $score = null;
        if (isset($json['lighthouseResult']['categories']['performance']['score'])) {
            $score = (float)$json['lighthouseResult']['categories']['performance']['score'] * 100.0;
        }

        $wpdb->insert($table, [
            'url' => $url,
            'strategy' => $strategy,
            'score' => $score,
            'raw' => wp_json_encode($json),
            'checked_at' => current_time('mysql'),
        ]);

        Logs::info('pagespeed', 'Checked', ['url' => $url, 'strategy' => $strategy, 'score' => $score]);
    }
}
