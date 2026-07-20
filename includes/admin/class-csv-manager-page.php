<?php
/**
 * @deprecated 5.1.1 This file is NOT loaded by the plugin bootstrap and has no active callers.
 *
 * The canonical CSV Manager is class-csv-manager-admin-page.php
 * (TMWSEO\Engine\Admin\CSVManagerAdminPage — 398 lines, fully integrated into the menu).
 *
 * This file exists only as dead code from an earlier iteration. It will be
 * removed in the v6 clean-up pass. Do NOT add new code here.
 *
 * If you need CSV management functionality, use:
 *   \TMWSEO\Engine\Admin\CSVManagerAdminPage
 */
namespace TMWSEO\Engine\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @deprecated 5.1.1 Use CSVManagerAdminPage instead. This class is unreachable from the plugin bootstrap.
 */
class CSVManagerPage {

    public static function init(): void {
        _doing_it_wrong(
            __METHOD__,
            'CSVManagerPage is deprecated and unreachable. Use CSVManagerAdminPage instead.',
            '5.1.1'
        );
    }

    public static function render_page(): void {
        _doing_it_wrong(
            __METHOD__,
            'CSVManagerPage is deprecated and unreachable. Use CSVManagerAdminPage instead.',
            '5.1.1'
        );
    }

    public static function handle_delete(): void {
        _doing_it_wrong(
            __METHOD__,
            'CSVManagerPage is deprecated and unreachable. Use CSVManagerAdminPage instead.',
            '5.1.1'
        );
    }
}
