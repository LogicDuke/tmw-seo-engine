<?php
namespace TMW\SEO\Lighthouse;

use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

class Collector_PSI {
    public static function run(string $url, string $strategy = 'mobile'): array {
        $api = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
        $strategy = $strategy === 'desktop' ? 'desktop' : 'mobile';

        $query = [
            'url' => $url,
            'strategy' => $strategy,
        ];

        $key = trim((string) Settings::get('google_pagespeed_api_key', ''));
        if ($key !== '') {
            $query['key'] = $key;
        }

        $request_url = add_query_arg($query, $api);
        $resp = wp_remote_get($request_url, ['timeout' => 90]);

        if (is_wp_error($resp)) {
            throw new \RuntimeException('PSI request failed: ' . $resp->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300 || !is_array($json)) {
            throw new \RuntimeException('PSI bad response code ' . $code);
        }

        if (empty($json['lighthouseResult']) || !is_array($json['lighthouseResult'])) {
            throw new \RuntimeException('PSI missing lighthouseResult payload');
        }

        return $json['lighthouseResult'];
    }
}
