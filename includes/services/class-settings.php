<?php
namespace TMWSEO\Engine\Services;

if (!defined('ABSPATH')) { exit; }

class Settings {

    public static function defaults(): array {
        return [
            // Safety
            'safe_mode' => 1,

            // Phase 1 policy
            // Manual Control Mode disables all cron + automatic post optimizations.
            'manual_control_mode' => 1,
            'debug_mode' => 0,

            // Optional: Serper API key (People Also Ask / related searches).
            'serper_api_key' => '',

            // Intelligence (Phase 1)
            'intel_max_seeds' => 10,
            'intel_max_keywords' => 1000,

            // OpenAI
            'openai_api_key' => '',
            'openai_mode' => 'hybrid', // quality|bulk|hybrid
            'openai_model_primary' => 'gpt-4o',
            'openai_model_bulk' => 'gpt-4o-mini',
            'openai_model' => 'gpt-4o', // legacy
            'brand_voice' => 'premium', // premium|neutral
            'tmwseo_dry_run_mode' => 0,

            // Indexing policy
            // Keep RankMath noindex by default until you explicitly enable auto-clearing.
            'auto_clear_noindex' => 0,

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

            // Affiliate links
            'affiliate_link_pattern' => '',
            'affiliate_campaign' => '',
            'affiliate_source' => '',

            // Template mode linking
            'template_external_link_enabled' => 0,
            'include_external_info_link' => 0,

            // ── v4.2 additions ─────────────────────────────────────────────
            // Google Search Console OAuth2
            'gsc_client_id'      => '',
            'gsc_client_secret'  => '',
            'gsc_site_url'       => '',

            // Google Indexing API (service account JSON)
            'google_indexing_service_account_json' => '',
            'indexing_api_post_types'              => 'model,video,tmw_video',

            // Anthropic Claude (fallback AI)
            'tmwseo_anthropic_api_key' => '',
            'tmwseo_ai_primary'        => 'openai', // openai | anthropic

            // AI budget cap (USD per month, 0 = unlimited)
            'tmwseo_openai_budget_usd' => 20.0,

            // JSON-LD Schema
            'schema_enabled'   => 1,
            'schema_post_types'=> 'model,video,tmw_video',

            // Orphan page detection
            'orphan_scan_enabled' => 1,

            // Model keyword discovery automation
            'enable_model_auto_keyword_discovery' => 1,
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
        return (bool) self::get('safe_mode', 1);
    }

    /**
     * Human-approval guardrail.
     *
     * Safety policy: all actionable operations must require explicit
     * human approval in every environment.
     */
    public static function is_human_approval_required(): bool {
        return TrustPolicy::is_human_approval_required();
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
