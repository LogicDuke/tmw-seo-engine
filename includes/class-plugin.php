<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

require_once TMWSEO_ENGINE_PATH . 'includes/db/class-schema.php';
require_once TMWSEO_ENGINE_PATH . 'includes/db/class-logs.php';
require_once TMWSEO_ENGINE_PATH . 'includes/db/class-jobs.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cron/class-cron.php';
require_once TMWSEO_ENGINE_PATH . 'includes/worker/class-worker.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-admin.php';
require_once TMWSEO_ENGINE_PATH . 'includes/migration/class-migration.php';

require_once TMWSEO_ENGINE_PATH . 'includes/services/class-settings.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-title-fixer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-openai.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-dataforseo.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-pagespeed.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-validator.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-kd-filter.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-content-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/platform/class-platform-profiles.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-repository.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-service.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-linking-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-scoring-engine.php';

class Plugin {

    private static $cluster_service;
    private static $cluster_linking_engine;
    private static $cluster_scoring_engine;

    public static function get_cluster_service() {
        return self::$cluster_service ?? null;
    }

    public static function get_cluster_linking_engine() {
        return self::$cluster_linking_engine ?? null;
    }

    public static function get_cluster_scoring_engine() {
        return self::$cluster_scoring_engine ?? null;
    }

    public static function clear_cluster_cache($cluster_id) {
        $cluster_id = (int) $cluster_id;
        if ($cluster_id <= 0) {
            return;
        }

        delete_transient('tmw_cluster_analysis_' . $cluster_id);
        delete_transient('tmw_cluster_score_' . $cluster_id);
    }

    public static function init(): void {
        Cron::init();
        Migration::maybe_migrate_legacy();
        \TMWSEO\Engine\Content\ContentEngine::init();
        \TMWSEO\Engine\Keywords\KeywordEngine::init();
        \TMWSEO\Engine\Platform\PlatformProfiles::init();

        global $wpdb;
        $cluster_repository = new \TMW_Cluster_Repository($wpdb);
        $cluster_service = new \TMW_Cluster_Service($cluster_repository);
        self::$cluster_service = $cluster_service;

        $cluster_linking_engine = new \TMW_Cluster_Linking_Engine(self::$cluster_service);
        self::$cluster_linking_engine = $cluster_linking_engine;

        $cluster_scoring_engine = new \TMW_Cluster_Scoring_Engine(
            self::$cluster_service,
            self::$cluster_linking_engine
        );
        self::$cluster_scoring_engine = $cluster_scoring_engine;

        if (is_admin()) {
            Admin::init();
        }
    }

    public static function activate(): void {
        Schema::create_or_update_tables();
        Cron::schedule_events();
        Migration::maybe_migrate_legacy(true);
        Logs::info('core', 'Activated ' . TMWSEO_ENGINE_VERSION);
    }

    public static function deactivate(): void {
        Cron::unschedule_events();
        Logs::info('core', 'Deactivated ' . TMWSEO_ENGINE_VERSION);
    }
}
