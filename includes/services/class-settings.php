<?php
namespace TMWSEO\Engine\Services;

if (!defined('ABSPATH')) { exit; }

class Settings {

    public static function defaults(): array {
        return [
            // Safety
            'safe_mode' => 0,

            // OpenAI
            'openai_api_key' => '',
            'openai_mode' => 'hybrid', // quality|bulk|hybrid
            'openai_model_primary' => 'gpt-4o',
            'openai_model_bulk' => 'gpt-4o-mini',
            'openai_model' => 'gpt-4o', // legacy
            'brand_voice' => 'premium', // premium|neutral
            'tmwseo_dry_run_mode' => 0,

            // DataForSEO
            'dataforseo_login' => '',
            'dataforseo_password' => '',
            'dataforseo_location_code' => '2840', // US by default (legacy expectation)
            'dataforseo_language_code' => 'en',

            // Keyword engine (adaptive defaults)
            'keyword_seeds_per_run' => 5,
            'keyword_suggestions_limit' => 200,
            'keyword_new_limit' => 300,
            'keyword_kd_batch_limit' => 300,
            'keyword_pages_per_day' => 3,
            'keyword_min_volume' => 30,
            'keyword_max_kd' => 60,

            // Competitors (one per line)
            'competitor_domains' => "chaturbate.com\nstripchat.com\nlivejasmin.com\nmyfreecams.com\ncamsoda.com\nbonga-cams.com\ncam4.com",

            // PageSpeed Insights (optional)
            'google_pagespeed_api_key' => '',
        ];
    }

    public static function all(): array {
        $opts = get_option('tmwseo_engine_settings', []);
        if (!is_array($opts)) $opts = [];
        return array_merge(self::defaults(), $opts);
    }

    public static function get(string $key, $default = null) {
        $opts = self::all();
        return array_key_exists($key, $opts) ? $opts[$key] : $default;
    }

    public static function is_safe_mode(): bool {
        return (bool) self::get('safe_mode', 0);
    }

    public static function openai_model_for_quality(): string {
        $mode = (string) self::get('openai_mode', 'hybrid');
        if ($mode === 'bulk') return (string) self::get('openai_model_bulk', 'gpt-4o-mini');
        return (string) self::get('openai_model_primary', (string) self::get('openai_model', 'gpt-4o'));
    }

    public static function openai_model_for_bulk(): string {
        $mode = (string) self::get('openai_mode', 'hybrid');
        if ($mode === 'quality') return (string) self::get('openai_model_primary', (string) self::get('openai_model', 'gpt-4o'));
        return (string) self::get('openai_model_bulk', 'gpt-4o-mini');
    }

    public static function competitor_domains(): array {
        $raw = (string) self::get('competitor_domains', '');
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $out = [];
        foreach ($lines as $l) {
            $l = trim((string)$l);
            if ($l === '') continue;
            // Normalize: strip protocol/www/path.
            $l = preg_replace('#^https?://#i', '', $l);
            $l = preg_replace('#^www\.#i', '', $l);
            $l = preg_replace('#/.*$#', '', $l);
            if ($l !== '') $out[] = $l;
        }
        return array_values(array_unique($out));
    }
}
