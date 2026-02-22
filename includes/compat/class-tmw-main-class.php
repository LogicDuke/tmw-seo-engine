<?php

if (!defined('ABSPATH')) { exit; }

if (!class_exists('TMW_Main_Class')) {
    class TMW_Main_Class {

        private static function get_plugin_instance() {
            if (class_exists('\\TMWSEO\\Engine\\Plugin') && method_exists('\\TMWSEO\\Engine\\Plugin', 'instance')) {
                return \TMWSEO\Engine\Plugin::instance();
            }

            return null;
        }

        public static function get_cluster_service() {
            $plugin = self::get_plugin_instance();
            if ($plugin && method_exists($plugin, 'get_cluster_service')) {
                return $plugin->get_cluster_service();
            }

            return class_exists('\\TMWSEO\\Engine\\Plugin')
                ? \TMWSEO\Engine\Plugin::get_cluster_service()
                : null;
        }

        public static function get_cluster_linking_engine() {
            $plugin = self::get_plugin_instance();
            if ($plugin && method_exists($plugin, 'get_cluster_linking_engine')) {
                return $plugin->get_cluster_linking_engine();
            }

            return class_exists('\\TMWSEO\\Engine\\Plugin')
                ? \TMWSEO\Engine\Plugin::get_cluster_linking_engine()
                : null;
        }

        public static function get_cluster_scoring_engine() {
            $plugin = self::get_plugin_instance();
            if ($plugin && method_exists($plugin, 'get_cluster_scoring_engine')) {
                return $plugin->get_cluster_scoring_engine();
            }

            return class_exists('\\TMWSEO\\Engine\\Plugin')
                ? \TMWSEO\Engine\Plugin::get_cluster_scoring_engine()
                : null;
        }

        public static function get_cluster_advisor() {
            $plugin = self::get_plugin_instance();
            if ($plugin && method_exists($plugin, 'get_cluster_advisor')) {
                return $plugin->get_cluster_advisor();
            }

            return class_exists('\\TMWSEO\\Engine\\Plugin')
                ? \TMWSEO\Engine\Plugin::get_cluster_advisor()
                : null;
        }

        public static function get_cluster_link_injector() {
            $plugin = self::get_plugin_instance();
            if ($plugin && method_exists($plugin, 'get_cluster_link_injector')) {
                return $plugin->get_cluster_link_injector();
            }

            return class_exists('\\TMWSEO\\Engine\\Plugin')
                ? \TMWSEO\Engine\Plugin::get_cluster_link_injector()
                : null;
        }

        public static function get_gsc_cluster_importer() {
            $plugin = self::get_plugin_instance();
            if ($plugin && method_exists($plugin, 'get_gsc_cluster_importer')) {
                return $plugin->get_gsc_cluster_importer();
            }

            return class_exists('\\TMWSEO\\Engine\\Plugin')
                ? \TMWSEO\Engine\Plugin::get_gsc_cluster_importer()
                : null;
        }

        public static function clear_cluster_cache($cluster_id) {
            $plugin = self::get_plugin_instance();
            if ($plugin && method_exists($plugin, 'clear_cluster_cache')) {
                return $plugin->clear_cluster_cache($cluster_id);
            }

            if (class_exists('\\TMWSEO\\Engine\\Plugin')) {
                return \TMWSEO\Engine\Plugin::clear_cluster_cache($cluster_id);
            }

            return null;
        }
    }
}
