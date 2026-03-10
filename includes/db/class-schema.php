<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class Schema {

    /**
     * Required intelligence tables that must exist regardless of stored DB versions.
     *
     * @return array<string,string>
     */
    private static function required_intelligence_table_sql(string $charset_collate): array {
        global $wpdb;

        $content_briefs = $wpdb->prefix . 'tmw_seo_content_briefs';
        $serp_analysis = $wpdb->prefix . 'tmw_seo_serp_analysis';
        $seo_competitors = $wpdb->prefix . 'tmw_seo_competitors';
        $ranking_probability = $wpdb->prefix . 'tmw_seo_ranking_probability';

        return [
            $content_briefs => "CREATE TABLE $content_briefs (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                primary_keyword VARCHAR(255) NOT NULL,
                cluster_key VARCHAR(255) NOT NULL,
                brief_type VARCHAR(80) NOT NULL,
                brief_json LONGTEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'ready',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY cluster_status (cluster_key, status),
                KEY keyword (primary_keyword)
            ) $charset_collate;",

            $serp_analysis => "CREATE TABLE $serp_analysis (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                keyword VARCHAR(255) NOT NULL,
                serp_weakness_score DECIMAL(4,2) NOT NULL DEFAULT 1,
                reason TEXT NULL,
                signals_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY keyword (keyword),
                KEY score_created (serp_weakness_score, created_at)
            ) $charset_collate;",

            $seo_competitors => "CREATE TABLE $seo_competitors (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                domain VARCHAR(191) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY domain (domain),
                KEY active_domain (is_active, domain)
            ) $charset_collate;",

            $ranking_probability => "CREATE TABLE $ranking_probability (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                keyword VARCHAR(255) NOT NULL,
                inputs_json LONGTEXT NOT NULL,
                ranking_probability DECIMAL(6,2) NOT NULL,
                ranking_tier VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY keyword (keyword),
                KEY score_tier (ranking_probability, ranking_tier)
            ) $charset_collate;",
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function get_missing_required_intelligence_tables(): array {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $required = array_keys(self::required_intelligence_table_sql($charset_collate));
        $missing = [];

        foreach ($required as $table_name) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
            if ($exists !== $table_name) {
                $missing[] = $table_name;
            }
        }

        return $missing;
    }

    /**
     * Ensure required intelligence tables exist even when schema versions are already current.
     * Safe to run repeatedly.
     */
    public static function reconcile_required_intelligence_tables(): void {
        $missing_tables = self::get_missing_required_intelligence_tables();
        if (empty($missing_tables)) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $required_sql = self::required_intelligence_table_sql($charset_collate);

        foreach ($missing_tables as $table_name) {
            if (isset($required_sql[$table_name])) {
                dbDelta($required_sql[$table_name]);
            }
        }
    }

    /**
     * Ensure the intelligence schema is fully present for upgraded installs.
     * Safe to run repeatedly.
     */
    public static function ensure_intelligence_schema(): void {
        $target_version = 1;

        if (class_exists('TMW_Intelligence_DB_Migration') && defined('TMW_Intelligence_DB_Migration::SCHEMA_VERSION')) {
            $target_version = (int) constant('TMW_Intelligence_DB_Migration::SCHEMA_VERSION');
        }

        $stored_version = (int) get_option('tmw_intelligence_schema_version', 0);
        $missing_tables = self::get_missing_required_intelligence_tables();

        if ($stored_version >= $target_version && empty($missing_tables)) {
            return;
        }

        self::create_or_update_tables();
        update_option('tmw_intelligence_schema_version', $target_version);
    }

    /**
     * Keep legacy and checklist cluster version option names aligned.
     * Safe to run repeatedly.
     */
    public static function normalize_cluster_schema_version_option(): void {
        $cluster_schema_version = get_option('tmw_cluster_schema_version', false);
        $cluster_db_version = get_option('tmw_cluster_db_version', false);

        if (false !== $cluster_schema_version && (false === $cluster_db_version || (string) $cluster_db_version !== (string) $cluster_schema_version)) {
            update_option('tmw_cluster_db_version', $cluster_schema_version);
            return;
        }

        if (false !== $cluster_db_version && false === $cluster_schema_version) {
            update_option('tmw_cluster_schema_version', $cluster_db_version);
        }
    }

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
        $aff_clicks = $wpdb->prefix . 'tmw_aff_clicks';

        // Keyword intelligence (alpha.8)
        $keyword_raw = $wpdb->prefix . 'tmw_keyword_raw';
        $keyword_candidates = $wpdb->prefix . 'tmw_keyword_candidates';
        $keyword_clusters = $wpdb->prefix . 'tmw_keyword_clusters';
        $keyword_graph = $wpdb->prefix . 'tmwseo_keyword_graph';
        $generated_pages = $wpdb->prefix . 'tmw_generated_pages';
        $opportunities = $wpdb->prefix . 'tmw_seo_opportunities';
        $suggestions = $wpdb->prefix . 'tmw_seo_suggestions';
        $model_similarity = $wpdb->prefix . 'tmw_model_similarity';
        $content_briefs = $wpdb->prefix . 'tmw_seo_content_briefs';
        $cluster_scores = $wpdb->prefix . 'tmw_seo_cluster_scores';
        $serp_analysis = $wpdb->prefix . 'tmw_seo_serp_analysis';
        $seo_competitors = $wpdb->prefix . 'tmw_seo_competitors';
        $ranking_probability = $wpdb->prefix . 'tmw_seo_ranking_probability';
        $internal_links = $wpdb->prefix . 'tmwseo_internal_links';
        $seeds_registry = $wpdb->prefix . 'tmwseo_seeds';
        $top_opportunities = $wpdb->prefix . 'tmwseo_top_opportunities';
        $cluster_summary = $wpdb->prefix . 'tmwseo_cluster_summary';
        $entity_keyword_map = $wpdb->prefix . 'tmwseo_entity_keyword_map';
        $keyword_trends = $wpdb->prefix . 'tmwseo_keyword_trends';

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

        $sql_seeds_registry = "CREATE TABLE $seeds_registry (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            seed VARCHAR(255) NOT NULL,
            source VARCHAR(50) NOT NULL,
            seed_type VARCHAR(50) NOT NULL DEFAULT 'general',
            priority SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_used DATETIME NULL,
            hash CHAR(32) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY seed_hash (hash),
            KEY source_entity (source, entity_type, entity_id),
            KEY priority_last_used (priority, last_used),
            KEY last_used (last_used)
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


        $sql_aff_clicks = "CREATE TABLE $aff_clicks (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            platform VARCHAR(50) NOT NULL,
            username VARCHAR(191) NOT NULL,
            target_url TEXT NOT NULL,
            ip_address VARCHAR(64) NULL,
            user_agent TEXT NULL,
            clicked_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY platform_clicked (platform, clicked_at),
            KEY username_clicked (username, clicked_at)
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
            intent_type VARCHAR(50) NOT NULL DEFAULT 'generic',
            entity_type VARCHAR(50) NOT NULL DEFAULT 'generic',
            entity_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            volume INT(11) NULL,
            cpc DECIMAL(10,2) NULL,
            difficulty DECIMAL(6,2) NULL,
            opportunity DECIMAL(10,4) NULL,
            serp_weakness DECIMAL(6,4) NOT NULL DEFAULT 0,
            node_degree INT(11) NOT NULL DEFAULT 0,
            graph_cluster_id VARCHAR(64) NULL,
            graph_cluster_size INT(11) NOT NULL DEFAULT 0,
            sources LONGTEXT NULL,
            notes TEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword (keyword),
            KEY canonical (canonical),
            KEY status (status),
            KEY intent_entity (intent_type, entity_type, entity_id),
            KEY opportunity (opportunity),
            KEY serp_weakness (serp_weakness),
            KEY graph_cluster (graph_cluster_id, graph_cluster_size),
            KEY node_degree (node_degree)
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

        $sql_keyword_graph = "CREATE TABLE $keyword_graph (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            related_keyword VARCHAR(255) NOT NULL,
            source VARCHAR(40) NOT NULL,
            relationship_type VARCHAR(40) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY keyword (keyword),
            KEY related_keyword (related_keyword),
            KEY source (source),
            KEY relationship_type (relationship_type),
            KEY keyword_related (keyword, related_keyword),
            KEY created_at (created_at)
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



        $sql_opportunities = "CREATE TABLE $opportunities (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            search_volume INT(11) NULL,
            difficulty DECIMAL(6,2) NULL,
            opportunity_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            competitor_url VARCHAR(255) NOT NULL DEFAULT '',
            competitor_position INT(11) NULL,
            estimated_traffic DECIMAL(12,2) NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'keyword_cycle',
            type VARCHAR(30) NOT NULL DEFAULT 'keyword',
            recommended_action VARCHAR(50) NOT NULL DEFAULT 'Create Draft',
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword_competitor (keyword, competitor_url),
            KEY status_score (status, opportunity_score),
            KEY source_type (source, type)
        ) $charset_collate;";

        $sql_model_similarity = "CREATE TABLE $model_similarity (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            model_id BIGINT(20) UNSIGNED NOT NULL,
            similar_model_id BIGINT(20) UNSIGNED NOT NULL,
            similarity_score TINYINT(3) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY model_pair (model_id, similar_model_id),
            KEY model_score (model_id, similarity_score),
            KEY similar_model_score (similar_model_id, similarity_score)
        ) $charset_collate;";


        $required_intelligence_sql = self::required_intelligence_table_sql($charset_collate);
        $sql_content_briefs = $required_intelligence_sql[$content_briefs];

        $sql_cluster_scores = "CREATE TABLE $cluster_scores (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cluster_key VARCHAR(255) NOT NULL,
            score DECIMAL(6,2) NOT NULL DEFAULT 0,
            label VARCHAR(20) NOT NULL,
            explanation TEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cluster_key (cluster_key),
            KEY score (score)
        ) $charset_collate;";

        $sql_serp_analysis = $required_intelligence_sql[$serp_analysis];

        $sql_seo_competitors = $required_intelligence_sql[$seo_competitors];

        $sql_ranking_probability = $required_intelligence_sql[$ranking_probability];

        $sql_suggestions = "CREATE TABLE $suggestions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            source_engine VARCHAR(100) NOT NULL,
            priority_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            estimated_traffic INT(11) NOT NULL DEFAULT 0,
            difficulty DECIMAL(6,2) NOT NULL DEFAULT 0,
            suggested_action TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status_priority (status, priority_score),
            KEY source_engine (source_engine),
            KEY type (type)
        ) $charset_collate;";

        $sql_internal_links = "CREATE TABLE $internal_links (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_post_id BIGINT(20) UNSIGNED NOT NULL,
            source_url VARCHAR(255) NOT NULL,
            source_title VARCHAR(255) NOT NULL,
            target_post_id BIGINT(20) UNSIGNED NOT NULL,
            target_url VARCHAR(255) NOT NULL,
            target_title VARCHAR(255) NOT NULL,
            anchor VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'suggested',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_target_anchor (source_post_id, target_post_id, anchor),
            KEY source_status (source_post_id, status),
            KEY target_status (target_post_id, status)
        ) $charset_collate;";

        $sql_top_opportunities = "CREATE TABLE $top_opportunities (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            search_volume INT(11) NOT NULL DEFAULT 0,
            difficulty DECIMAL(6,2) NOT NULL DEFAULT 0,
            serp_weakness DECIMAL(6,4) NOT NULL DEFAULT 0,
            cluster_id VARCHAR(64) NOT NULL DEFAULT '',
            opportunity_score DECIMAL(10,4) NOT NULL DEFAULT 0,
            materialized_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY score_volume (opportunity_score, search_volume),
            KEY cluster_id (cluster_id),
            KEY keyword (keyword)
        ) $charset_collate;";

        $sql_cluster_summary = "CREATE TABLE $cluster_summary (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cluster_id VARCHAR(64) NOT NULL,
            cluster_size INT(11) NOT NULL DEFAULT 0,
            avg_volume DECIMAL(10,2) NOT NULL DEFAULT 0,
            avg_difficulty DECIMAL(6,2) NOT NULL DEFAULT 0,
            materialized_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cluster_id (cluster_id),
            KEY cluster_size (cluster_size)
        ) $charset_collate;";

        $sql_entity_keyword_map = "CREATE TABLE $entity_keyword_map (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT(20) UNSIGNED NOT NULL,
            materialized_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword_entity (keyword, entity_type, entity_id),
            KEY entity_lookup (entity_type, entity_id),
            KEY keyword (keyword)
        ) $charset_collate;";

        $sql_keyword_trends = "CREATE TABLE $keyword_trends (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            current_position INT(11) NOT NULL DEFAULT 0,
            previous_position INT(11) NOT NULL DEFAULT 0,
            rank_change INT(11) NOT NULL DEFAULT 0,
            trend_score DECIMAL(10,2) NOT NULL DEFAULT 0,
            snapshot_week VARCHAR(12) NOT NULL,
            materialized_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword_week (keyword, snapshot_week),
            KEY trend_score (trend_score),
            KEY rank_change (rank_change)
        ) $charset_collate;";

$sql_legacy_rank = "CREATE TABLE $legacy_rank (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            position INT UNSIGNED NOT NULL,
            url VARCHAR(255) NOT NULL DEFAULT '',
            checked_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY keyword_checked (keyword(191), checked_at)
        ) $charset_collate;";

        dbDelta($sql_jobs);
        dbDelta($sql_logs);
        dbDelta($sql_seeds_registry);
        dbDelta($sql_platform);
        dbDelta($sql_keywords);
        dbDelta($sql_competitors);
        dbDelta($sql_indexing);
        dbDelta($sql_pagespeed);
        dbDelta($sql_aff_clicks);
        dbDelta($sql_keyword_raw);
        dbDelta($sql_keyword_candidates);
        dbDelta($sql_keyword_clusters);
        dbDelta($sql_keyword_graph);
        dbDelta($sql_generated_pages);
        dbDelta($sql_opportunities);
        dbDelta($sql_model_similarity);
        dbDelta($sql_content_briefs);
        dbDelta($sql_cluster_scores);
        dbDelta($sql_serp_analysis);
        dbDelta($sql_seo_competitors);
        dbDelta($sql_ranking_probability);
        dbDelta($sql_suggestions);
        dbDelta($sql_internal_links);
        dbDelta($sql_top_opportunities);
        dbDelta($sql_cluster_summary);
        dbDelta($sql_entity_keyword_map);
        dbDelta($sql_keyword_trends);
        dbDelta($sql_legacy_rank);

        // ── Keyword usage deduplication tables (anti-cannibalization) ──────
        $kw_usage     = $wpdb->prefix . 'tmwseo_keyword_usage';
        $kw_usage_log = $wpdb->prefix . 'tmwseo_keyword_usage_log';

        $sql_kw_usage = "CREATE TABLE $kw_usage (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword_hash CHAR(32) NOT NULL,
            keyword_text TEXT NOT NULL,
            category VARCHAR(64) NOT NULL DEFAULT '',
            type VARCHAR(16) NOT NULL DEFAULT '',
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_used_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY keyword_hash (keyword_hash)
        ) $charset_collate;";

        $sql_kw_usage_log = "CREATE TABLE $kw_usage_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword_hash CHAR(32) NOT NULL,
            keyword_text TEXT NOT NULL,
            category VARCHAR(64) NOT NULL DEFAULT '',
            type VARCHAR(16) NOT NULL DEFAULT '',
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_type VARCHAR(32) NOT NULL DEFAULT '',
            used_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY keyword_hash (keyword_hash),
            KEY used_at (used_at)
        ) $charset_collate;";

        dbDelta($sql_kw_usage);
        dbDelta($sql_kw_usage_log);

        \TMW\SEO\Lighthouse\Schema::create_or_update_tables();

        update_option('tmwseo_engine_db_version', TMWSEO_ENGINE_VERSION);
    }
}
