<?php
/**
 * TMW SEO Engine — Model Title & Meta Repair v1.0.2
 *
 * Standalone eval-file script. Delegates entirely to the registered
 * WP-CLI subcommand `wp tmwseo repair-model-title-meta`.
 *
 * This file exists for operators who prefer eval-file over subcommands.
 * All logic, snapshot, and rollback behaviour lives in:
 *   includes/cli/class-cli.php → TMWSEOCommand::repair_model_title_meta()
 *
 * Usage:
 *   wp eval-file tools/tmw-seo-title-meta-repair-v1.0.2.php           # dry-run
 *   wp eval-file tools/tmw-seo-title-meta-repair-v1.0.2.php -- --apply # commit
 *
 * Preferred usage (identical output, uses class-cli.php directly):
 *   wp tmwseo repair-model-title-meta --dry-run
 *   wp tmwseo repair-model-title-meta
 *
 * Rollback after apply:
 *   wp tmwseo rollback --post_id=<id>
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Run via WP-CLI: wp eval-file tools/tmw-seo-title-meta-repair-v1.0.2.php' );
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    die( 'This script requires WP-CLI.' );
}

// Detect --apply flag; default is dry-run.
$tmw_v102_apply = false;
if ( isset( $args ) && is_array( $args ) && in_array( '--apply', $args, true ) ) {
    $tmw_v102_apply = true;
}

// Build assoc array expected by the subcommand method.
$tmw_v102_assoc = $tmw_v102_apply ? [] : [ 'dry-run' => true ];

// Instantiate and call the subcommand directly.
if ( ! class_exists( 'TMWSEO\Engine\CLI\TMWSEOCommand' ) ) {
    \WP_CLI::error( 'TMWSEOCommand class not found. Ensure tmw-seo-engine plugin is active.' );
    exit( 1 );
}

$cmd = new \TMWSEO\Engine\CLI\TMWSEOCommand();
$cmd->repair_model_title_meta( [], $tmw_v102_assoc );
