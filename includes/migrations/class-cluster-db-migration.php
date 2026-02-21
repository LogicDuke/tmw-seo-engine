<?php

if (! defined('ABSPATH')) {
    exit;
}

class TMW_Cluster_DB_Migration {
    const SCHEMA_VERSION = 1;
    const OPTION_KEY     = 'tmw_cluster_schema_version';

    public function maybe_migrate() {
        $stored_version = (int) get_option(self::OPTION_KEY, 0);

        if ($stored_version < self::SCHEMA_VERSION) {
            $this->run_migration();
            update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
        }
    }

    private function run_migration() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = '';

        dbDelta($sql);
    }
}
