<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class Schema {

    public static function create_or_update_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        // New tables (Phase 1+)
        $jobs = $wpdb->prefix . 'tmw_jobs';
        $logs = $wpdb->prefix . 'tmw_logs';
        $platform = $wpdb->prefix . 'tmw_platform_profiles';
        $keywords = $wpdb->prefix . 'tmw_keywords';
        $competitors = $wpdb->prefix . 'tmw_competitors';
        $indexing = $wpdb->prefix . 'tmw_indexing';
        $pagespeed = $wpdb->prefix . 'tmw_pagespeed';

        // Keyword intelligence (alpha.8)
        $keyword_raw = $wpdb->prefix . 'tmw_keyword_raw';
        $keyword_candidates = $wpdb->prefix . 'tmw_keyword_candidates';
        $keyword_clusters = $wpdb->prefix . 'tmw_keyword_clusters';
        $generated_pages = $wpdb->prefix . 'tmw_generated_pages';

        // Legacy table kept for compatibility with alpha.4
        $legacy_rank = $wpdb->prefix . 'tmwseo_engine_rank_history';

        $sql_jobs = "CREATE TABLE $jobs (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(50) NOT NULL,
            entity_type VARCHAR(30) NOT NULL,
            entity_id BIGINT(20) UNSIGNED NULL,
            payload LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            attempts INT(11) NOT NULL DEFAULT 0,
            run_after DATETIME NOT NULL,
            locked_until DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status_run_after (status, run_after),
            KEY entity (entity_type, entity_id),
            KEY type (type)
        ) $charset_collate;";

        $sql_logs = "CREATE TABLE $logs (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            time DATETIME NOT NULL,
            level VARCHAR(10) NOT NULL,
            context VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            data LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY level_time (level, time),
            KEY context_time (context, time)
        ) $charset_collate;";

        $sql_platform = "CREATE TABLE $platform (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            model_id BIGINT(20) UNSIGNED NOT NULL,
            platform_key VARCHAR(30) NOT NULL,
            profile_url TEXT NOT NULL,
            embed_url TEXT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            meta LONGTEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY model_platform (model_id, platform_key)
        ) $charset_collate;";

        $sql_keywords = "CREATE TABLE $keywords (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(30) NOT NULL,
            entity_id BIGINT(20) UNSIGNED NOT NULL,
            keyword VARCHAR(255) NOT NULL,
            volume INT(11) NULL,
            cpc DECIMAL(10,2) NULL,
            difficulty DECIMAL(6,2) NULL,
            intent VARCHAR(30) NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'dataforseo',
            raw LONGTEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY entity (entity_type, entity_id),
            KEY keyword (keyword)
        ) $charset_collate;";

        $sql_competitors = "CREATE TABLE $competitors (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(30) NOT NULL,
            entity_id BIGINT(20) UNSIGNED NOT NULL,
            seed_keyword VARCHAR(255) NOT NULL,
            domain VARCHAR(255) NOT NULL,
            url TEXT NULL,
            metric DECIMAL(10,2) NULL,
            raw LONGTEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY entity (entity_type, entity_id),
            KEY seed (seed_keyword),
            KEY domain (domain)
        ) $charset_collate;";

        $sql_indexing = "CREATE TABLE $indexing (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            url TEXT NOT NULL,
            action VARCHAR(20) NOT NULL,
            status VARCHAR(30) NOT NULL,
            response LONGTEXT NULL,
            last_submitted_at DATETIME NULL,
            last_checked_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY action_status (action, status)
        ) $charset_collate;";

        $sql_pagespeed = "CREATE TABLE $pagespeed (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            url TEXT NOT NULL,
            strategy VARCHAR(10) NOT NULL,
            score INT(11) NULL,
            lcp DECIMAL(10,2) NULL,
            cls DECIMAL(10,3) NULL,
            inp DECIMAL(10,2) NULL,
            raw LONGTEXT NULL,
            checked_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY strategy_checked (strategy, checked_at)
        ) $charset_collate;";

        
        $sql_keyword_raw = "CREATE TABLE $keyword_raw (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            source VARCHAR(30) NOT NULL,
            source_ref VARCHAR(255) NULL,
            volume INT(11) NULL,
            cpc DECIMAL(10,2) NULL,
            competition DECIMAL(6,4) NULL,
            raw LONGTEXT NULL,
            discovered_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword_source (keyword, source),
            KEY source_ref (source_ref)
        ) $charset_collate;";

        $sql_keyword_candidates = "CREATE TABLE $keyword_candidates (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            canonical VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            intent VARCHAR(30) NULL,
            volume INT(11) NULL,
            cpc DECIMAL(10,2) NULL,
            difficulty DECIMAL(6,2) NULL,
            opportunity DECIMAL(10,4) NULL,
            sources LONGTEXT NULL,
            notes TEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword (keyword),
            KEY canonical (canonical),
            KEY status (status),
            KEY opportunity (opportunity)
        ) $charset_collate;";

        $sql_keyword_clusters = "CREATE TABLE $keyword_clusters (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cluster_key VARCHAR(255) NOT NULL,
            representative VARCHAR(255) NOT NULL,
            keywords LONGTEXT NULL,
            total_volume INT(11) NULL,
            avg_difficulty DECIMAL(6,2) NULL,
            opportunity DECIMAL(10,4) NULL,
            page_id BIGINT(20) UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cluster_key (cluster_key),
            KEY status (status),
            KEY opportunity (opportunity),
            KEY page_id (page_id)
        ) $charset_collate;";

        $sql_generated_pages = "CREATE TABLE $generated_pages (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            page_id BIGINT(20) UNSIGNED NOT NULL,
            cluster_id BIGINT(20) UNSIGNED NULL,
            keyword VARCHAR(255) NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'keyword',
            indexing VARCHAR(20) NOT NULL DEFAULT 'noindex',
            last_generated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY page_id (page_id),
            KEY cluster_id (cluster_id),
            KEY indexing (indexing)
        ) $charset_collate;";


$sql_legacy_rank = "CREATE TABLE $legacy_rank (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            position INT UNSIGNED NOT NULL,
            checked_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($sql_jobs);
        dbDelta($sql_logs);
        dbDelta($sql_platform);
        dbDelta($sql_keywords);
        dbDelta($sql_competitors);
        dbDelta($sql_indexing);
        dbDelta($sql_pagespeed);
        dbDelta($sql_keyword_raw);
        dbDelta($sql_keyword_candidates);
        dbDelta($sql_keyword_clusters);
        dbDelta($sql_generated_pages);
        dbDelta($sql_legacy_rank);

        \TMW\SEO\Lighthouse\Schema::create_or_update_tables();

        update_option('tmwseo_engine_db_version', TMWSEO_ENGINE_VERSION);
    }
}
