<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\Crypto;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Affiliates\CrakRevenueCamManager;

if (!defined('ABSPATH')) { exit; }

/**
 * AdminSettingsSanitizer — Settings API registration + sanitizers.
 *
 * Extracted from the 4,700-line Admin class as the first concrete step
 * of the god-class decomposition the audit called out. Everything here
 * is pure-static and stateless: WP Settings API calls these via the
 * `sanitize_callback` registered in register(), and a few tests
 * (`SettingsValidationTest`) call sanitize_settings() directly.
 *
 * Public API preserved: the Admin class still exposes
 *  - Admin::register_settings()
 *  - Admin::sanitize_settings()
 *  - Admin::sanitize_platform_affiliate_settings()
 *  - Admin::sanitize_affiliate_networks()
 * as thin delegating wrappers. That keeps the existing test calling
 * Admin::sanitize_settings() working without modification, and lets
 * any external code (or hooks that referenced [Admin::class, '...'])
 * continue to function. New code should call this class directly.
 *
 * Dependencies (all already loaded by the engine loader): Settings,
 * Crypto, PlatformRegistry, CrakRevenueCamManager.
 */
class AdminSettingsSanitizer {

    /**
     * Register all WP Settings API entries owned by the engine. Hooked
     * to `admin_init` from Admin::init().
     */
    public static function register(): void {
        register_setting(
            'tmwseo_settings_group',
            'tmwseo_engine_settings',
            [
                'type' => 'array',
                'sanitize_callback' => [self::class, 'sanitize_engine_settings'],
                'default' => [],
            ]
        );

        register_setting(
            'tmwseo_settings_group',
            'tmwseo_platform_affiliate_settings',
            [
                'type' => 'array',
                'sanitize_callback' => [self::class, 'sanitize_platform_affiliate_settings'],
                'default' => [],
            ]
        );

        // Network-level affiliate settings — keyed by network slug (e.g. 'crack_revenue').
        // Separate from platform settings which are keyed by PlatformRegistry slug.
        // This option is what makes generic network keys like 'crack_revenue' actually
        // configurable through the admin UI without polluting the platform table.
        register_setting(
            'tmwseo_settings_group',
            'tmwseo_affiliate_networks',
            [
                'type'              => 'array',
                'sanitize_callback' => [self::class, 'sanitize_affiliate_networks'],
                'default'           => [],
            ]
        );

        register_setting(
            'tmwseo_settings_group',
            CrakRevenueCamManager::API_SETTINGS_OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [CrakRevenueCamManager::class, 'sanitize_api_settings'],
                'default'           => [],
            ]
        );
    }

    /**
     * Sanitize the main `tmwseo_engine_settings` array. Preserves any
     * keys not present in the submission (so partial saves don't wipe
     * fields on other tabs), validates enum-style fields against their
     * allow-lists, clamps numerics to sane ranges, and encrypts the
     * credential subset listed in Settings::secret_keys() before
     * persisting.
     */
    public static function sanitize_engine_settings($input): array {
        $input = is_array($input) ? $input : [];

        // Preserve any existing settings keys not present in this form submission,
        // so that partial saves (e.g., tabbed Settings pages) don't wipe keys.
        $existing = get_option('tmwseo_engine_settings', []);
        $existing = is_array($existing) ? $existing : [];

        $mode = sanitize_text_field((string)($input['openai_mode'] ?? $existing['openai_mode'] ?? 'hybrid'));
        if (!in_array($mode, ['quality', 'bulk', 'hybrid'], true)) {
            $mode = 'hybrid';
        }

        $primary = sanitize_text_field((string)($input['openai_model_primary'] ?? $existing['openai_model_primary'] ?? 'gpt-4o'));
        $bulk    = sanitize_text_field((string)($input['openai_model_bulk'] ?? $existing['openai_model_bulk'] ?? 'gpt-4o-mini'));

        $voice = sanitize_text_field((string)($input['brand_voice'] ?? $existing['brand_voice'] ?? 'premium'));
        if (!in_array($voice, ['premium', 'neutral'], true)) {
            $voice = 'premium';
        }

        $ai_primary = sanitize_text_field((string)($input['tmwseo_ai_primary'] ?? $existing['tmwseo_ai_primary'] ?? 'openai'));
        if (!in_array($ai_primary, ['openai', 'anthropic'], true)) {
            $ai_primary = 'openai';
        }

        $sanitized = [
            // Safety — always locked on
            'manual_control_mode' => 1,
            // safe_mode: simple checkbox. Admin::render_settings() is a single-page form —
            // absent key = user explicitly unchecked. Intentional OFF saves correctly.
            'safe_mode' => !empty($input['safe_mode']) ? 1 : 0,

            // OpenAI
            'openai_api_key'        => sanitize_text_field((string)($input['openai_api_key'] ?? $existing['openai_api_key'] ?? '')),
            'openai_mode'           => $mode,
            'openai_model_primary'  => $primary,
            'openai_model_bulk'     => $bulk,
            'openai_model'          => ($mode === 'bulk') ? $bulk : $primary,
            'brand_voice'           => $voice,
            'tmwseo_dry_run_mode'   => !empty($input['tmwseo_dry_run_mode']) ? 1 : 0,
            'auto_clear_noindex'    => !empty($input['auto_clear_noindex']) ? 1 : 0,
            'template_external_link_enabled' => !empty($input['template_external_link_enabled']) ? 1 : 0,
            'include_external_info_link'     => !empty($input['include_external_info_link']) ? 1 : 0,

            // v4.2: Anthropic / AI Router
            'tmwseo_anthropic_api_key' => sanitize_text_field((string)($input['tmwseo_anthropic_api_key'] ?? $existing['tmwseo_anthropic_api_key'] ?? '')),
            'tmwseo_ai_primary'        => $ai_primary,
            'tmwseo_openai_budget_usd' => max(0.0, (float)($input['tmwseo_openai_budget_usd'] ?? $existing['tmwseo_openai_budget_usd'] ?? 20.0)),

            // DataForSEO
            'tmwseo_dataforseo_budget_usd' => max(0.0, (float)($input['tmwseo_dataforseo_budget_usd'] ?? $existing['tmwseo_dataforseo_budget_usd'] ?? 20.0)),
            'dataforseo_login'         => sanitize_text_field((string)($input['dataforseo_login'] ?? $existing['dataforseo_login'] ?? '')),
            'dataforseo_password'      => sanitize_text_field((string)($input['dataforseo_password'] ?? $existing['dataforseo_password'] ?? '')),
            'dataforseo_location_code' => sanitize_text_field((string)($input['dataforseo_location_code'] ?? $existing['dataforseo_location_code'] ?? '2840')),
            'dataforseo_language_code' => sanitize_text_field((string)($input['dataforseo_language_code'] ?? $existing['dataforseo_language_code'] ?? 'en')),

            // v4.2: Google Search Console OAuth2
            'gsc_client_id'     => sanitize_text_field((string)($input['gsc_client_id'] ?? $existing['gsc_client_id'] ?? '')),
            'gsc_client_secret' => sanitize_text_field((string)($input['gsc_client_secret'] ?? $existing['gsc_client_secret'] ?? '')),
            'gsc_site_url'      => esc_url_raw((string)($input['gsc_site_url'] ?? $existing['gsc_site_url'] ?? '')),

            // v4.2: Google Indexing API
            'google_indexing_service_account_json' => sanitize_textarea_field((string)($input['google_indexing_service_account_json'] ?? $existing['google_indexing_service_account_json'] ?? '')),
            'indexing_api_post_types'              => sanitize_text_field((string)($input['indexing_api_post_types'] ?? $existing['indexing_api_post_types'] ?? 'model,video,tmw_video')),

            // v4.2: Schema + Orphan — single-page form, absent = unchecked = 0.
            'schema_enabled'    => !empty($input['schema_enabled']) ? 1 : 0,
            'schema_post_types' => sanitize_text_field((string)($input['schema_post_types'] ?? $existing['schema_post_types'] ?? 'model,video,tmw_video')),
            'orphan_scan_enabled' => !empty($input['orphan_scan_enabled']) ? 1 : 0,
            'enable_model_auto_keyword_discovery' => !empty($input['enable_model_auto_keyword_discovery']) ? 1 : 0,

            // Keyword engine
            // 4.6.1: Configurable review queue cap and model discovery toggle
            'keyword_review_queue_cap' => max(20, min(1000, (int)($input['keyword_review_queue_cap'] ?? $existing['keyword_review_queue_cap'] ?? 200))),
            'model_discovery_enabled'  => !empty($input['model_discovery_enabled']) ? 1 : 0,

            'keyword_min_volume'     => max(0, min(100000, (int)($input['keyword_min_volume'] ?? $existing['keyword_min_volume'] ?? 30))),
            'keyword_max_kd'         => max(0, min(100,    (int)($input['keyword_max_kd'] ?? $existing['keyword_max_kd'] ?? 60))),
            'keyword_new_limit'      => max(1, min(5000,   (int)($input['keyword_new_limit'] ?? $existing['keyword_new_limit'] ?? 300))),
            'keyword_kd_batch_limit' => max(1, min(1000,   (int)($input['keyword_kd_batch_limit'] ?? $existing['keyword_kd_batch_limit'] ?? 300))),
            'keyword_pages_per_day'  => max(1, min(100,    (int)($input['keyword_pages_per_day'] ?? $existing['keyword_pages_per_day'] ?? 3))),
            'keyword_negative_filters' => sanitize_textarea_field((string)($input['keyword_negative_filters'] ?? $existing['keyword_negative_filters'] ?? "video chat\nrandom chat\nomegle\nchatroulette\nchat room\nchatroom\nstranger chat\ntalk to strangers")),
            'max_keywords_per_run'   => max(1, (int)($input['max_keywords_per_run'] ?? $existing['max_keywords_per_run'] ?? 500)),
            'max_keywords_per_day'   => max(1, (int)($input['max_keywords_per_day'] ?? $existing['max_keywords_per_day'] ?? 5000)),
            'max_depth'              => max(1, (int)($input['max_depth'] ?? $existing['max_depth'] ?? 3)),
            'min_search_volume'      => max(0, (int)($input['min_search_volume'] ?? $existing['min_search_volume'] ?? 50)),
            'max_keywords_per_topic' => max(1, (int)($input['max_keywords_per_topic'] ?? $existing['max_keywords_per_topic'] ?? 300)),
            'competitor_domains'     => sanitize_textarea_field((string)($input['competitor_domains'] ?? $existing['competitor_domains'] ?? '')),

            // Misc
            'google_pagespeed_api_key' => sanitize_text_field((string)($input['google_pagespeed_api_key'] ?? $existing['google_pagespeed_api_key'] ?? '')),
            'serper_api_key'           => sanitize_text_field((string)($input['serper_api_key'] ?? $existing['serper_api_key'] ?? '')),
            'intel_max_seeds'          => max(1, (int)($input['intel_max_seeds'] ?? $existing['intel_max_seeds'] ?? 10)),
            'intel_max_keywords'       => max(50, (int)($input['intel_max_keywords'] ?? $existing['intel_max_keywords'] ?? 1000)),
            'debug_mode'               => !empty($input['debug_mode']) ? 1 : 0,

            // Google Ads Keyword Planner
            'google_ads_enabled'         => !empty($input['google_ads_enabled']) ? 1 : 0,
            'google_ads_developer_token' => sanitize_text_field((string)($input['google_ads_developer_token'] ?? $existing['google_ads_developer_token'] ?? '')),
            'google_ads_client_id'       => sanitize_text_field((string)($input['google_ads_client_id'] ?? $existing['google_ads_client_id'] ?? '')),
            'google_ads_client_secret'   => sanitize_text_field((string)($input['google_ads_client_secret'] ?? $existing['google_ads_client_secret'] ?? '')),
            'google_ads_refresh_token'   => sanitize_text_field((string)($input['google_ads_refresh_token'] ?? $existing['google_ads_refresh_token'] ?? '')),
            'google_ads_customer_id'     => sanitize_text_field((string)($input['google_ads_customer_id'] ?? $existing['google_ads_customer_id'] ?? '')),
            'google_ads_login_customer_id' => sanitize_text_field((string)($input['google_ads_login_customer_id'] ?? $existing['google_ads_login_customer_id'] ?? '')),

            // Google Trends
            'google_trends_enabled'   => !empty($input['google_trends_enabled']) ? 1 : 0,
            'google_trends_geo'       => sanitize_text_field((string)($input['google_trends_geo'] ?? $existing['google_trends_geo'] ?? 'US')),
            'google_trends_locale'    => sanitize_text_field((string)($input['google_trends_locale'] ?? $existing['google_trends_locale'] ?? 'en-US')),
            'google_trends_timeframe' => sanitize_text_field((string)($input['google_trends_timeframe'] ?? $existing['google_trends_timeframe'] ?? 'today 3-m')),

        ];

        // Encrypt every credential field listed in Settings::secret_keys()
        // before the option is written. Crypto::encrypt is idempotent —
        // values that already carry an "enc:" sentinel (because the form
        // omitted the field and the ?? fallback pulled the still-encrypted
        // existing value through sanitize_text_field unchanged) are
        // returned as-is, so this is safe to apply to the merged batch.
        $sanitized = Crypto::encrypt_in($sanitized, Settings::secret_keys());

        return $sanitized;
    }

    public static function sanitize_platform_affiliate_settings($input): array {
        $input = is_array($input) ? $input : [];
        $existing = get_option('tmwseo_platform_affiliate_settings', []);
        $sanitized = is_array($existing) ? $existing : [];

        foreach (self::get_affiliate_admin_platform_rows() as $platform_key => $defaults) {
            $row = is_array($input[$platform_key] ?? null) ? $input[$platform_key] : [];
            $sanitized[$platform_key] = [
                'enabled' => !empty($row['enabled']) ? 1 : 0,
                'template' => sanitize_textarea_field((string) ($row['template'] ?? '')),
                'campaign' => sanitize_text_field((string) ($row['campaign'] ?? '')),
                'source' => sanitize_text_field((string) ($row['source'] ?? '')),
                'subaffid' => sanitize_text_field((string) ($row['subaffid'] ?? '')),
                'psid' => sanitize_text_field((string) ($row['psid'] ?? '')),
                'pstool' => sanitize_text_field((string) ($row['pstool'] ?? '')),
                'psprogram' => sanitize_text_field((string) ($row['psprogram'] ?? '')),
                'campaign_id' => sanitize_text_field((string) ($row['campaign_id'] ?? '')),
                'siteid' => sanitize_text_field((string) ($row['siteid'] ?? (string) ($defaults['siteid'] ?? ''))),
                'categoryname' => sanitize_text_field((string) ($row['categoryname'] ?? (string) ($defaults['categoryname'] ?? ''))),
                'pagename' => sanitize_text_field((string) ($row['pagename'] ?? (string) ($defaults['pagename'] ?? ''))),
            ];
        }

        return $sanitized;
    }

    /**
     * Sanitize the `tmwseo_affiliate_networks` option.
     *
     * This option holds network-level affiliate templates — keyed by an arbitrary
     * network slug chosen by the operator (e.g. 'crack_revenue', 'trafficjunky').
     * It is entirely separate from `tmwseo_platform_affiliate_settings` which is
     * keyed by PlatformRegistry slugs.
     *
     * Only rows explicitly submitted by the admin form are preserved. Network keys
     * are validated as sanitized slugs. Empty-slug rows are discarded.
     */
    public static function sanitize_affiliate_networks($input): array {
        $input     = is_array($input) ? $input : [];
        $sanitized = [];

        foreach ($input as $raw_key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = sanitize_key((string) ($row['slug'] ?? $raw_key));
            if ($slug === '') {
                continue;
            }
            $sanitized[$slug] = [
                'label'    => sanitize_text_field((string) ($row['label']    ?? $slug)),
                'enabled'  => !empty($row['enabled']) ? 1 : 0,
                'template' => sanitize_textarea_field((string) ($row['template'] ?? '')),
                'campaign' => sanitize_text_field((string) ($row['campaign'] ?? '')),
                'source'   => sanitize_text_field((string) ($row['source']   ?? '')),
                'subaffid' => sanitize_text_field((string) ($row['subaffid'] ?? '')),
            ];
        }

        return $sanitized;
    }

    /**
     * Build affiliate admin rows for platforms eligible for affiliate routing setup.
     *
     * Prefers explicit PlatformRegistry `affiliate_supported` metadata.
     * Falls back to excluding known non-affiliate groups for safety.
     *
     * Public so the affiliate-settings admin UI can read the row list to
     * render its form. (Previously private to Admin; promoted to public
     * here so the UI doesn't need to live in Admin too.)
     */
    public static function get_affiliate_admin_platform_rows(): array {
        $rows = [];
        foreach (PlatformRegistry::get_platforms() as $platform) {
            $slug = sanitize_key((string) ($platform['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $group = sanitize_key((string) ($platform['group'] ?? ''));
            $supported = !empty($platform['affiliate_supported']);
            if (!$supported && in_array($group, ['social', 'linkhub'], true)) {
                continue;
            }
            if (!$supported) {
                continue;
            }

            $rows[$slug] = [
                'label' => sanitize_text_field((string) ($platform['name'] ?? ucfirst($slug))),
                'affiliate_link_pattern' => sanitize_text_field((string) ($platform['affiliate_link_pattern'] ?? '')),
                'siteid' => $slug === 'livejasmin' ? 'jasmin' : '',
                'categoryname' => $slug === 'livejasmin' ? 'girl' : '',
                'pagename' => $slug === 'livejasmin' ? 'freechat' : '',
            ];
        }

        return $rows;
    }

    /** @deprecated Alias kept for back-compat. Prefer get_affiliate_admin_platform_rows(). */
    public static function get_affiliate_platform_defaults(): array {
        return self::get_affiliate_admin_platform_rows();
    }
}
